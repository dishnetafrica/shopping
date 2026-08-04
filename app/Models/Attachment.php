<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A document attached to a transaction as evidence.
 *
 * `subject_type` is a short slug, not a class name — see the migration for why. The map
 * below is the single source of truth for which record types can carry evidence, and it is
 * used to validate every incoming request, so a caller cannot invent a subject type.
 */
class Attachment extends Model
{
    use BelongsToTenant;

    /** slug => [Eloquent class, human label]. Adding a row here is all it takes to extend. */
    public const SUBJECTS = [
        'cashbook' => [\App\Models\OrderCost::class,        'Cashbook entry'],
        'purchase' => [\App\Models\OrderCost::class,        'Purchase'],
        'expense'  => [\App\Models\OrderCost::class,        'Expense'],
        'payment'  => [\App\Models\CustomerPayment::class,  'Customer payment'],
        'invoice'  => [\App\Models\Invoice::class,          'Invoice'],
        'quotation' => [\App\Models\Quotation::class,       'Quotation'],
        'order'    => [\App\Models\Order::class,            'Order'],
    ];

    /** The document types a person can pick. Free-form would make the report useless. */
    public const KINDS = [
        'receipt'       => 'Receipt',
        'invoice'       => 'Supplier invoice',
        'delivery_note' => 'Delivery note',
        'voucher'       => 'Payment voucher',
        'contract'      => 'Contract / agreement',
        'other'         => 'Other',
    ];

    protected $fillable = [
        'tenant_id', 'subject_type', 'subject_id', 'kind', 'path', 'original_name',
        'mime', 'byte_size', 'sha256', 'note', 'uploaded_by', 'uploaded_by_name',
        'detached_at', 'detached_by', 'detach_reason',
    ];

    protected $casts = [
        'byte_size'   => 'integer',
        'detached_at' => 'datetime',
    ];

    /** Live evidence — a detached document is history, not evidence. */
    public function scopeLive($q)
    {
        return $q->whereNull('detached_at');
    }

    public function scopeFor($q, string $subjectType, int $subjectId)
    {
        return $q->where('subject_type', $subjectType)->where('subject_id', $subjectId);
    }

    public static function isSubject(string $type): bool
    {
        return array_key_exists($type, self::SUBJECTS);
    }

    public static function kindLabel(string $kind): string
    {
        return self::KINDS[$kind] ?? 'Document';
    }

    /** Human file size, for the screen. */
    public function sizeLabel(): string
    {
        $b = (int) $this->byte_size;
        if ($b <= 0) {
            return '';
        }
        if ($b < 1024) {
            return $b . ' B';
        }
        if ($b < 1048576) {
            return round($b / 1024) . ' KB';
        }

        return round($b / 1048576, 1) . ' MB';
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
