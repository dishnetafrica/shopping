<?php

namespace App\Http\Controllers;

use App\Models\OrderTask;
use App\Models\Tenant;
use App\Models\User;
use App\Support\JobToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public, no-login "My Jobs" list (the DishNet scheduling-list model). A daily per-person token (JobToken)
 * authorizes viewing ONLY that user's own open tasks. Each task links to the existing per-task page
 * (/task/{nonce}) which already handles accept / mark-done / comment without login.
 *
 * Security: token is the credential (unguessable + daily-expiring), rate-limited at the route, reveals only
 * this person's own task titles/due/status — never account access, never another user's tasks.
 */
class JobsController extends Controller
{
    public function list(string $token)
    {
        $data = JobToken::verify($token);
        if (! $data) {
            abort(404);
        }
        [$tid, $uid] = $data;

        $tenant = Tenant::find($tid);
        $user   = User::withoutGlobalScopes()->where('tenant_id', $tid)->find($uid);
        if (! $tenant || ! $user) {
            abort(404);
        }

        $tasks = OrderTask::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->where('owner_user_id', $uid)
            ->whereIn('status', OrderTask::ACTIVE)
            ->orderBy('due_at')
            ->limit(200)
            ->get();

        // Ensure every listed task has a link_nonce so its detail page opens. Raw update = no model events.
        foreach ($tasks as $tk) {
            if (empty($tk->link_nonce)) {
                $nonce = Str::random(16);
                DB::table('order_tasks')->where('id', $tk->id)->update(['link_nonce' => $nonce]);
                $tk->link_nonce = $nonce;
            }
        }

        return response()->view('jobs-list', [
            'tenantName' => (string) $tenant->name,
            'userName'   => (string) ($user->name ?: 'You'),
            'tasks'      => $tasks,
        ]);
    }
}
