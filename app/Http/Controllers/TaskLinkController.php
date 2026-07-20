<?php

namespace App\Http\Controllers;

use App\Services\Ops\TaskEngine;
use Illuminate\Http\Request;

/**
 * Public, no-login task access via an unguessable 16-char nonce (like DishNet's
 * scheduling links). The nonce authorizes ONLY task-scoped actions on that ONE task —
 * never account access, never other tasks, never tenant data beyond the task text.
 *
 * Security:
 *  - nonce is the credential; unknown/invalid → 404
 *  - rate-limited (see routes) to defeat brute-forcing
 *  - reveals only this task's title/due/status
 *  - actions limited to accept / complete / comment on this task
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
            'nonce'  => $nonce,
            'title'  => $task->title,
            'due'    => $task->due_at ? $task->due_at->format('M j, Y H:i') : null,
            'status' => $task->status,
            'active' => $task->isActive(),
            'done'   => $task->status === 'completed',
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
        return response()->json($this->engine->completeByNonce($task, $note ?: null));
    }

    public function comment(Request $r, string $nonce)
    {
        $task = $this->engine->findByNonce($nonce);
        if (! $task) abort(404);
        $text = (string) $r->input('text', '');
        return response()->json($this->engine->commentByNonce($task, $text));
    }
}
