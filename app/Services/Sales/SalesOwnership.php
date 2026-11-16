<?php

namespace App\Services\Sales;

use App\Capability\Contracts\CapabilityContext;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Vertical;
use Illuminate\Support\Facades\DB;

/**
 * One customer, one salesperson — the single place that decides and records who owns a customer.
 *
 * A salesperson is a USER ACCOUNT (users.id), with their WhatsApp number on the account for alerts.
 * The owner is stored on the customer (customer_profiles.owner_user_id) and mirrored onto that
 * customer's conversations and leads, so each list can filter "mine" with one indexed column.
 *
 * Rules (decision C, DishNet South Sudan sales plan):
 *   - A customer who already has an owner keeps them: every new enquiry, chat and quote goes to that
 *     person, never to a second salesperson.
 *   - A new customer is given to the next salesperson in turn (round-robin over active sales users
 *     who have a WhatsApp number), or to the first salesperson who replies to them in the inbox.
 *   - Only an owner/admin or a manager can move a customer to someone else, and every move is
 *     written to the audit log with who, when and why.
 *   - Salespeople see their own customers plus unassigned ones; owners and managers see everyone.
 *
 * Switched on only for businesses whose type is "Internet provider" (Vertical::ISP). Every other
 * business keeps its existing lead routing (lead_recipients by phone) and sees no difference.
 */
class SalesOwnership
{
    /** Roles that take customers in turn. */
    public const SELLING_ROLES = ['sales'];

    /** Roles that see every customer and may move customers between salespeople. */
    public const MANAGING_ROLES = ['manager'];

    public static function enabled(?Tenant $tenant): bool
    {
        return $tenant !== null && Vertical::of($tenant) === Vertical::ISP;
    }

    public static function canManage(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->isOwnerLike() || in_array((string) $user->role, self::MANAGING_ROLES, true);
    }

    /** Owner of this customer (by phone), or null. */
    public function ownerOf(Tenant $tenant, string $phone): ?User
    {
        $phone = self::digits($phone);
        if ($phone === '') {
            return null;
        }
        $id = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('phone', $phone)->value('owner_user_id');

        return $id ? User::where('tenant_id', $tenant->id)->find((int) $id) : null;
    }

    /**
     * The salesperson for this customer: their existing owner, or — for a new customer — the next
     * salesperson in turn, who then owns them. Null only when the business has no salesperson with
     * a WhatsApp number (the caller then leaves the customer unassigned for a manager).
     */
    public function ownerForEnquiry(Tenant $tenant, string $phone, ?string $name = null): ?User
    {
        if ($existing = $this->ownerOf($tenant, $phone)) {
            return $existing;
        }
        $pick = $this->nextInTurn($tenant);
        if ($pick) {
            $this->setOwner($tenant, $phone, $pick, $name);
        }

        return $pick;
    }

    /**
     * May this user act on (reply to, open) this customer? A salesperson may act on their own and on
     * unassigned customers; replying to an unassigned one makes it theirs ($claim).
     *
     * @return array{allowed:bool,owner:?User,claimed:bool}
     */
    public function checkAccess(Tenant $tenant, User $user, string $phone, bool $claim): array
    {
        if (! self::enabled($tenant) || self::canManage($user)) {
            return ['allowed' => true, 'owner' => null, 'claimed' => false];
        }
        $owner = $this->ownerOf($tenant, $phone);
        if ($owner && (int) $owner->id !== (int) $user->id) {
            return ['allowed' => false, 'owner' => $owner, 'claimed' => false];
        }
        if (! $owner && $claim && self::digits($phone) !== '') {
            // Two salespeople replying at the same moment: only the first write wins.
            $won = $this->setOwnerIfUnowned($tenant, $phone, $user);
            if (! $won) {
                $owner = $this->ownerOf($tenant, $phone);

                return ['allowed' => $owner === null || (int) $owner->id === (int) $user->id, 'owner' => $owner, 'claimed' => false];
            }

            return ['allowed' => true, 'owner' => $user, 'claimed' => true];
        }

        return ['allowed' => true, 'owner' => $owner, 'claimed' => false];
    }

    /**
     * A manager moves a customer to another salesperson. Moves the customer, their conversations and
     * their open leads together, and logs it.
     *
     * @return array{ok:bool,error?:string}
     */
    public function reassign(Tenant $tenant, string $phone, int $toUserId, User $by, string $reason): array
    {
        if (! self::canManage($by)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'reason_required'];
        }
        $to = User::where('tenant_id', $tenant->id)->find($toUserId);
        if (! $to) {
            return ['ok' => false, 'error' => 'unknown_staff'];        // only this business's own staff
        }
        $before = $this->ownerOf($tenant, $phone);
        $this->setOwner($tenant, $phone, $to, null, true);
        AuditLogger::log($tenant->id, 'customer_owner', (int) (CustomerProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('phone', self::digits($phone))->value('id') ?? 0),
            'reassign', $by, ['owner_user_id' => $before?->id, 'owner' => $before?->name], ['owner_user_id' => $to->id, 'owner' => $to->name], $reason);

        return ['ok' => true];
    }

    /**
     * Who prepared a quotation: the signed-in staff member when one creates it (panel, POS), or — on
     * an Internet-provider business — the customer's salesperson when the bot drafts it.
     */
    public function preparedBy(Tenant $tenant, string $phone): ?int
    {
        // Never allowed to stop a quotation being saved: any failure here just leaves it blank.
        try {
            $uid = auth()->id();
            if ($uid !== null && User::where('tenant_id', $tenant->id)->whereKey($uid)->exists()) {
                return (int) $uid;
            }

            return self::enabled($tenant) ? $this->ownerOf($tenant, $phone)?->id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Restrict a list query to what this user may see: their own rows and unassigned ones. */
    public static function scopeVisible($query, CapabilityContext $ctx, string $column = 'owner_user_id')
    {
        if (! self::enabled($ctx->tenant) || self::canManage($ctx->user)) {
            return $query;
        }
        $me = (int) $ctx->user->id;

        return $query->where(fn ($q) => $q->where($column, $me)->orWhereNull($column));
    }

    /** Salespeople who take customers in turn: active sales users with a WhatsApp number, oldest first. */
    public function salespeople(Tenant $tenant): array
    {
        return User::where('tenant_id', $tenant->id)->whereIn('role', self::SELLING_ROLES)
            ->whereNotNull('phone')->where('phone', '!=', '')->orderBy('id')->get()->all();
    }

    /** PURE. The next index in turn and who it picks. */
    public static function pickInTurn(array $ids, int $pointer): array
    {
        $n = count($ids);
        if ($n === 0) {
            return ['id' => null, 'next' => 0];
        }
        $i = (($pointer % $n) + $n) % $n;

        return ['id' => $ids[$i], 'next' => ($i + 1) % $n];
    }

    // ---------------------------------------------------------------------------------------------

    private function nextInTurn(Tenant $tenant): ?User
    {
        $people = $this->salespeople($tenant);
        if (! $people) {
            return null;
        }
        return DB::transaction(function () use ($tenant, $people) {
            $fresh = Tenant::whereKey($tenant->id)->lockForUpdate()->first() ?? $tenant;
            $pick = self::pickInTurn(array_map(fn ($u) => (int) $u->id, $people), (int) $fresh->setting('sales_rr_index', 0));
            $fresh->putSetting('sales_rr_index', $pick['next']);

            return collect($people)->firstWhere('id', $pick['id']);
        });
    }

    private function setOwnerIfUnowned(Tenant $tenant, string $phone, User $user): bool
    {
        $phone = self::digits($phone);
        $this->profile($tenant, $phone, null);
        $won = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('phone', $phone)
            ->whereNull('owner_user_id')->update(['owner_user_id' => $user->id]) > 0;
        if ($won) {
            $this->mirror($tenant, $phone, (int) $user->id, false);
        }

        return $won;
    }

    private function setOwner(Tenant $tenant, string $phone, User $user, ?string $name, bool $moveOpenLeads = false): void
    {
        $phone = self::digits($phone);
        $p = $this->profile($tenant, $phone, $name);
        $p->owner_user_id = $user->id;
        $p->save();
        $this->mirror($tenant, $phone, (int) $user->id, $moveOpenLeads);
    }

    /** Copy the owner onto the customer's conversations and leads (open leads move on reassignment). */
    private function mirror(Tenant $tenant, string $phone, int $userId, bool $moveOpenLeads): void
    {
        Conversation::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('customer_phone', $phone)->update(['owner_user_id' => $userId]);
        $leads = Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('customer_phone', $phone);
        $moveOpenLeads
            ? $leads->whereIn('status', Lead::OPEN_STATUSES)->update(['owner_user_id' => $userId])
            : $leads->whereNull('owner_user_id')->update(['owner_user_id' => $userId]);
    }

    private function profile(Tenant $tenant, string $phone, ?string $name): CustomerProfile
    {
        return CustomerProfile::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'phone' => $phone],
            ['name' => $name ? mb_substr($name, 0, 120) : null],
        );
    }

    private static function digits(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }
}
