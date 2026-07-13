<?php

namespace App\Capability\Command;

interface Command
{
    public function key(): string;

    public function actor(): string;   // 'human' | 'ai'

    public function permission(): string;

    public function reversible(): bool;
}
