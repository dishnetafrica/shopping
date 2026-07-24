<?php

namespace App\Http\Controllers;

use App\Services\Ops\TaskEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Public, no-login task access via an unguessable 16-char nonce (like DishNet's
 * scheduling links). The nonce authorizes ONLY task-scoped actions on that ONE task —
 * never account access, never other tasks, never tenant data beyond the task text.
 *
 * Security:
 *  - nonce is the credential; unknown/invalid → 404
 *  - rate-limited (see routes) to defeat brute-forcing
 *  - reveals only this task's title/due/status/note
 *  - actions limited to accept / complete / comment / photo-upload on this task
 */
class TaskLinkController extends Controller
{
    public function __construct(private TaskEngine $engine) {}

    /** Show the task page. */
    public function show(string $nonce)
    {
        $task = $this->engine->findByNonce($nonce);
        if (! $task) abort(404);

        return response()->view('task-link', [
            'nonce'      => $nonce,
            'title'      => $task->title,
            'note'       => (string) ($task->note ?? ''),
            'due'        => $task->due_at ? $task->due_at->format('M j, Y H:i') : null,
            'status'     => $task->status,
            'active'     => $task->isActive(),
            'done'       => $task->status === 'completed',
            'attachment' => (string) ($task->attachment ?? ''),
        ]);
    }

    public function accept(string $nonce)
    {
        $task = $this->engine->findByNonce($nonce);
        if (! $task) abort(404);
        return response()->json($this->engine->acceptByNonce($task));
    }

    public function complete(Request $r, string $nonce)
    {
        $task = $this->engine->findByNonce($nonce);
        if (! $task) abort(404);
        $note = (string) $r->input('note', '');
        $url  = $this->storeBase64($task->tenant_id, $r);   // optional proof photo (null if none/invalid)
        return response()->json($this->engine->completeByNonce($task, $note ?: null, $url));
    }

    /** Attach a photo WITHOUT completing (optional proof, added any time). */
    public function upload(Request $r, string $nonce)
    {
        $task = $this->engine->findByNonce($nonce);
        if (! $task) abort(404);
        $url = $this->storeBase64($task->tenant_id, $r);
        if (! $url) return response()->json(['ok' => false, 'error' => 'No valid photo (JPG/PNG/PDF, up to 15 MB).'], 422);
        return response()->json($this->engine->attachByNonce($task, $url));
    }

    public function comment(Request $r, string $nonce)
    {
        $task = $this->engine->findByNonce($nonce);
        if (! $task) abort(404);
        $text = (string) $r->input('text', '');
        return response()->json($this->engine->commentByNonce($task, $text));
    }

    /**
     * Decode a base64 data-URL from the request and store it publicly, returning a full URL — or null if
     * absent/invalid. Same shape/limits as the panel's proof upload (JPG/PNG/WEBP/PDF, ≤15 MB).
     */
    private function storeBase64(int $tenantId, Request $r): ?string
    {
        $data = (string) $r->input('attachment_data', '');
        if ($data === '') return null;
        if (str_contains($data, ',')) $data = substr($data, strpos($data, ',') + 1);
        $bin = base64_decode($data, true);
        if ($bin === false || strlen($bin) > 15 * 1024 * 1024) return null;
        $ext = strtolower(pathinfo((string) $r->input('attachment_name', 'photo.jpg'), PATHINFO_EXTENSION)) ?: 'jpg';
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) return null;
        $file = 'ops/' . $tenantId . '/' . uniqid('proof_', true) . '.' . $ext;
        Storage::disk('public')->put($file, $bin);
        return url(Storage::url($file));
    }
}
