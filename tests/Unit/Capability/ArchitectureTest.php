<?php

namespace Tests\Unit\Capability;

use PHPUnit\Framework\TestCase;

class ArchitectureTest extends TestCase
{
    public function test_http_layer_never_references_models_or_services(): void
    {
        $dir = dirname(__DIR__, 3) . '/app/Capability/Http';
        foreach (glob($dir . '/*.php') as $file) {
            $src = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/use\s+App\\\\Models\\\\/', $src, basename($file) . ' must not import App\\Models');
            $this->assertDoesNotMatchRegularExpression('/use\s+App\\\\Services\\\\/', $src, basename($file) . ' must not import App\\Services');
        }
    }
}
