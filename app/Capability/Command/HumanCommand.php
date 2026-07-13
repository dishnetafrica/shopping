<?php

namespace App\Capability\Command;

final class HumanCommand implements Command
{
    public function __construct(
        private string $key,
        private string $permission = '',
        private bool $reversible = true,
    ) {}

    public function key(): string { return $this->key; }

    public function actor(): string { return 'human'; }

    public function permission(): string { return $this->permission; }

    public function reversible(): bool { return $this->reversible; }
}
