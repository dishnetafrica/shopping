<?php

namespace App\Services\Evidence;

use App\Models\Attachment;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Storing and retrieving transaction evidence.
 *
 * One place, because every upload path in this application validates differently — most of
 * them not at all. `ExpenseController`, `OrderCostController` and `PaymentController` each
 * accept a `receipt` file with no size limit, no MIME check and no record of who sent it.
 * Everything new goes through here instead.
 *
 * DECISIONS WORTH KNOWING
 *
 * Content-addressed paths. The file is stored at `evidence/{tenant}/{sha256}.{ext}`, so the
 * same receipt photographed twice occupies one file and two rows. It also means the path
 * itself is derived from the bytes: a file cannot be quietly replaced with different content
 * at the same path without the hash — and therefore the path — changing.
 *
 * Never served from /storage/. Those URLs are raw web-server file serving with no tenant
 * check at all, so anyone holding a path can read another business's receipts. Evidence is
 * streamed through an authenticated route instead, which also survives the storage symlink
 * being dropped on redeploy — a documented recurring problem on this host.
 *
 * Detach is not delete. Removing evidence records who removed it and why, and keeps the row.
 */
final class EvidenceService
{
    /** 20 MB. A phone photo is 2-5 MB and a scanned multi-page invoice rarely exceeds this. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    /** What a receipt or invoice can actually be. Anything else is refused by name. */
    public const ALLOWED = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'heic' => 'image/heic',
        'pdf' => 'application/pdf',
    ];

    public static function available(): bool
    {
        return Schema::hasTable('attachments');
    }

    /**
     * Store one document against one transaction.
     *
     * @return array{ok:bool, error?:string, message?:string, attachment?:Attachment}
     */
    public static function attach(
        int $tenantId,
        string $subjectType,
        int $subjectId,
        string $bytes,
        string $originalName,
        string $kind = 'receipt',
        ?int $userId = null,
        ?string $userName = null,
        ?string $note = null
    ): array {
        if (! self::available()) {
            return ['ok' => false, 'error' => 'not_migrated', 'message' => 'Run php artisan migrate first.'];
        }
        if (! Attachment::isSubject($subjectType)) {
            return ['ok' => false, 'error' => 'bad_subject', 'message' => 'Unknown record type.'];
        }
        if ($subjectId <= 0) {
            return ['ok' => false, 'error' => 'bad_subject_id', 'message' => 'Which record is this for?'];
        }

        $size = strlen($bytes);
        if ($size === 0) {
            return ['ok' => false, 'error' => 'empty', 'message' => 'That file is empty.'];
        }
        if ($size > self::MAX_BYTES) {
            // Round UP, so a file one byte over never reports itself as exactly the limit.
            return ['ok' => false, 'error' => 'too_big',
                'message' => 'That file is ' . (ceil($size / 104857.6) / 10) . ' MB. The limit is '
                    . (self::MAX_BYTES / 1048576) . ' MB — photograph it again at a lower size.'];
        }

        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (! isset(self::ALLOWED[$ext])) {
            return ['ok' => false, 'error' => 'bad_type',
                'message' => 'Only photos (JPG, PNG, WEBP, HEIC, GIF) and PDF files can be attached.'];
        }
        if (! array_key_exists($kind, Attachment::KINDS)) {
            $kind = 'other';
        }

        $sha = hash('sha256', $bytes);
        $path = 'evidence/' . $tenantId . '/' . $sha . '.' . $ext;

        try {
            // Content-addressed: if these exact bytes are already stored, do not write again.
            if (! Storage::disk('public')->exists($path)) {
                Storage::disk('public')->put($path, $bytes);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'store_failed', 'message' => 'Could not save the file.'];
        }

        $a = Attachment::create([
            'tenant_id'        => $tenantId,
            'subject_type'     => $subjectType,
            'subject_id'       => $subjectId,
            'kind'             => $kind,
            'path'             => $path,
            'original_name'    => mb_substr(self::safeName($originalName), 0, 200),
            'mime'             => self::ALLOWED[$ext],
            'byte_size'        => $size,
            'sha256'           => $sha,
            'note'             => $note !== null ? mb_substr($note, 0, 300) : null,
            'uploaded_by'      => $userId,
            'uploaded_by_name' => $userName !== null ? mb_substr($userName, 0, 120) : null,
        ]);

        AuditLogger::log($tenantId, 'attachment', (int) $a->id, 'attach', $userName ?? 'system',
            null, ['subject' => $subjectType . '#' . $subjectId, 'name' => $a->original_name, 'sha256' => $sha], null);

        return ['ok' => true, 'attachment' => $a];
    }

    /**
     * Remove evidence from a transaction. The row survives with who and why, so the trail
     * still shows that a document was once attached and then taken off.
     */
    public static function detach(Attachment $a, ?int $userId, ?string $userName, string $reason = ''): void
    {
        $a->detached_at = now();
        $a->detached_by = $userId;
        $a->detach_reason = mb_substr(trim($reason), 0, 200) ?: null;
        $a->save();

        AuditLogger::log((int) $a->tenant_id, 'attachment', (int) $a->id, 'detach', $userName ?? 'system',
            ['subject' => $a->subject_type . '#' . $a->subject_id], ['reason' => $a->detach_reason], null);
    }

    /** @return \Illuminate\Support\Collection<int,Attachment> */
    public static function forSubject(int $tenantId, string $subjectType, int $subjectId)
    {
        if (! self::available()) {
            return collect();
        }

        return Attachment::where('tenant_id', $tenantId)->live()
            ->for($subjectType, $subjectId)->orderBy('id')->get();
    }

    /**
     * How many live documents each of these subjects has.
     *
     * @param  int[]  $ids
     * @return array<int,int>  subject_id => count
     */
    public static function countsFor(int $tenantId, string $subjectType, array $ids): array
    {
        if (! self::available() || ! $ids) {
            return [];
        }

        return Attachment::where('tenant_id', $tenantId)->live()
            ->where('subject_type', $subjectType)->whereIn('subject_id', $ids)
            ->selectRaw('subject_id, count(*) c')->groupBy('subject_id')
            ->pluck('c', 'subject_id')->all();
    }

    /** How many live documents this business has on file, all subjects. */
    public static function countLive(int $tenantId): int
    {
        if (! self::available()) {
            return 0;
        }

        return (int) Attachment::where('tenant_id', $tenantId)->live()->count();
    }

    /**
     * Does the stored file still hash to what was recorded?
     *
     * @return array{ok:bool, reason:string}
     */
    public static function verify(Attachment $a): array
    {
        try {
            if (! Storage::disk('public')->exists($a->path)) {
                return ['ok' => false, 'reason' => 'file missing from storage'];
            }
            $bytes = Storage::disk('public')->get($a->path);
            if (hash('sha256', (string) $bytes) !== (string) $a->sha256) {
                return ['ok' => false, 'reason' => 'CONTENT CHANGED — hash does not match'];
            }
            if (strlen((string) $bytes) !== (int) $a->byte_size) {
                return ['ok' => false, 'reason' => 'size does not match'];
            }

            return ['ok' => true, 'reason' => 'intact'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'could not read: ' . $e->getMessage()];
        }
    }

    /** Strip anything that could confuse a download header or a filesystem. */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._ ()\-]+/u', '_', $name) ?? 'document';

        return trim($name) !== '' ? trim($name) : 'document';
    }
}
