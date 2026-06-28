<?php
namespace Tests\Support\Knowledge;

use App\Models\KnowledgeAction;
use App\Services\Knowledge\Contracts\Capability;
use App\Services\Knowledge\Contracts\Extractor;
use App\Services\Knowledge\Contracts\Projector;
use App\Services\Knowledge\Dto\ActionRequest;
use App\Services\Knowledge\Dto\ExtractionResult;
use App\Services\Knowledge\Dto\Fact;
use App\Services\Knowledge\Intent;

/**
 * A throwaway capability that exists ONLY to prove extensibility: it handles a brand-new intent
 * string ("greeting") that is NOT in the engine's Intent constants, plus Intent::NOTE, and
 * projects to its own "test_projection". If this can be registered and exercised without editing
 * a single engine file, the architecture is genuinely generic — not just theoretically so.
 */
class TestCapability implements Capability
{
    public const INTENT_GREETING = 'greeting';     // deliberately not a core Intent::* constant

    public function name(): string { return 'test_capability'; }
    public function intents(): array { return [self::INTENT_GREETING, Intent::NOTE]; }
    public function extractor(): Extractor { return new TestExtractor(); }
    public function projector(): Projector { return new TestProjector(); }
}

class TestExtractor implements Extractor
{
    public function extract(string $text, array $profile = []): ExtractionResult
    {
        // One message → multiple facts AND multiple actions (proves the engine supports this).
        return new ExtractionResult(
            intent: TestCapability::INTENT_GREETING,
            facts: [
                new Fact('test_capability', 'Greeting', 'last_greeting', ['text' => $text], 0.9),
                new Fact('test_capability', 'Facility', 'mood', ['value' => 'friendly'], 0.8),
            ],
            actions: [
                new ActionRequest('test_capability', 'log_greeting', $text, ['boom' => str_contains($text, 'BOOM')]),
                new ActionRequest('test_capability', 'note', $text),
            ],
        );
    }
}

class TestProjector implements Projector
{
    /** Public log so tests can assert the projection ran. This IS the "test_projection". */
    public static array $applied = [];

    public function apply(KnowledgeAction $action): string
    {
        if (data_get($action->params_json, 'boom')) {
            throw new \RuntimeException('projector blew up on purpose');   // to prove event survives
        }
        self::$applied[] = $action->action_type;
        return 'test:' . $action->action_type;
    }

    public function revert(KnowledgeAction $action): void
    {
        self::$applied = array_values(array_diff(self::$applied, [$action->action_type]));
    }
}
