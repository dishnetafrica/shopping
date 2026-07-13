<?php

namespace App\Capability\Registry;

final class CapabilityManifest
{
    public function __construct(
        public string $key,
        public string $type,        // 'object' | 'process' | 'shared'
        public string $owner,       // 'sales' | 'accounts' | 'warehouse' | 'management' | plugin
        public string $module = 'core',
        public string $version = '1.0',
        public array $dependsOn = [],
        public array $events = [],   // ['consumes'=>[], 'emits'=>[]]
        public string $permission = '',
        public array $commands = [],
        public string $api = '',
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
