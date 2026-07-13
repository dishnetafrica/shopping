<?php

namespace App\Capability\Command;

final class AiCommand implements Command
{
    public function __construct(
        private string $key,
        private string $permission = '',
        private bool $reversible = true,
        public float $confidence = 0.0,
        public string $autonomy = 'suggest',   // 'suggest' | 'draft' | 'auto'
        public string $rationale = '',
    ) {}

    public function key(): string { return $this->key; }

    public function actor(): string { return 'ai'; }

    public function permission(): string { return $this->permission; }

    public function reversible(): bool { return $this->reversible; }
}
