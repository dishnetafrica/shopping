<?php

namespace App\Services\Comms;

/** Outcome of a single provider attempt. */
class ProviderResult
{
    public bool $ok;
    public ?string $messageId;
    public ?string $error;

    private function __construct(bool $ok, ?string $messageId = null, ?string $error = null)
    {
        $this->ok        = $ok;
        $this->messageId = $messageId;
        $this->error     = $error;
    }

    public static function sent(?string $messageId = null): self
    {
        return new self(true, $messageId);
    }

    public static function failed(string $error): self
    {
        // Trim so a giant provider stack trace can't bloat comm_messages.last_error.
        return new self(false, null, mb_substr($error, 0, 1000));
    }
}
