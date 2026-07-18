<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Media front-door fix (2026-07-18): voice + photos must reach the pipeline that was built for them. */
class MediaFrontDoorTest extends TestCase
{
    public function test_the_guard_admits_audio_and_images_without_text(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Bot/WebhookController.php'));
        $this->assertStringContainsString("has_audio", $src);
        $this->assertStringContainsString("has_image", $src);
        $this->assertStringContainsString('!$hasMedia', $src,
            'empty-text drop must exempt media messages');
    }

    public function test_the_pipeline_branches_it_feeds_still_exist(): void
    {
        $job = file_get_contents(app_path('Jobs/ProcessIncomingMessage.php'));
        $this->assertStringContainsString("voice_received", $job);
        $this->assertStringContainsString("Got your voice note", $job, 'the never-leave-them-hanging ack');
        $this->assertStringContainsString("has_image", $job, 'the vision branch');
    }
}
