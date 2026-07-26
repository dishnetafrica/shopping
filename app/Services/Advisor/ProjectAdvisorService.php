<?php declare(strict_types=1);

namespace App\Services\Advisor;

use App\Models\AdvisorProject;
use App\Models\Tenant;

/**
 * Project Advisor orchestrator — ties a discovery profile to its sizing engine.
 *
 * The registry is the one place a new industry is added: map a profile key to its DiscoveryProfile
 * (questions + mapping) and its SizingEngine (deterministic maths). Everything else — schema serving,
 * compute, persistence — is generic and reused unchanged.
 *
 *   compute()  is pure (answers → brief → sizing); no DB, safe to call for a live wizard preview.
 *   save()/…   persist a captured project (answers + sizing snapshot) for the proposal stage.
 */
class ProjectAdvisorService
{
    /** profile key => [DiscoveryProfile::class, SizingEngine::class]. Add industries here. */
    private const REGISTRY = [
        'solar' => [SolarDiscoveryProfile::class, SolarSizingService::class],
    ];

    public static function make(): self
    {
        return new self();
    }

    public function profiles(): array
    {
        return array_keys(self::REGISTRY);
    }

    public function has(string $profile): bool
    {
        return isset(self::REGISTRY[$profile]);
    }

    public function profile(string $profile): DiscoveryProfile
    {
        $cls = self::REGISTRY[$profile][0] ?? null;
        if (! $cls) {
            throw new \InvalidArgumentException("Unknown advisor profile: {$profile}");
        }
        return new $cls();
    }

    public function engine(string $profile): SizingEngine
    {
        $cls = self::REGISTRY[$profile][1] ?? null;
        if (! $cls) {
            throw new \InvalidArgumentException("Unknown advisor profile: {$profile}");
        }
        return new $cls();
    }

    /** The questionnaire schema for a profile (what the wizard renders). */
    public function schema(string $profile): array
    {
        return $this->profile($profile)->schema();
    }

    /**
     * Deterministic compute: answers → engine brief → sizing result. Pure (no DB), so it powers a live
     * wizard preview as the salesperson types. Returns the brief too, for transparency.
     *
     * @return array{profile:string,brief:array,sizing:array}
     */
    public function compute(string $profile, array $answers, array $config = []): array
    {
        $brief  = $this->profile($profile)->toBrief($answers);
        $sizing = $this->engine($profile)->size($brief, $config);

        return ['profile' => $profile, 'brief' => $brief, 'sizing' => $sizing];
    }

    /**
     * Create or update a captured project, storing the answers and a fresh sizing snapshot.
     *
     * @param array $data  ['id'?, 'profile'?, 'name'?, 'status'?, 'customer_id'?, 'answers', 'config'?]
     */
    public function save(Tenant $tenant, array $data, ?int $userId = null): AdvisorProject
    {
        $profile = (string) ($data['profile'] ?? 'solar');
        if (! $this->has($profile)) {
            $profile = 'solar';
        }
        $answers = is_array($data['answers'] ?? null) ? $data['answers'] : [];
        $config  = is_array($data['config'] ?? null) ? $data['config'] : [];

        $sizing = $this->compute($profile, $answers, $config)['sizing'];

        $attrs = [
            'tenant_id'   => $tenant->id,
            'customer_id' => isset($data['customer_id']) && $data['customer_id'] !== '' ? (int) $data['customer_id'] : null,
            'profile'     => $profile,
            'name'        => ($n = trim((string) ($data['name'] ?? ''))) !== '' ? $n : null,
            'status'      => in_array(($data['status'] ?? 'sized'), ['draft', 'sized', 'proposed', 'won', 'lost'], true) ? $data['status'] : 'sized',
            'answers'     => $answers,
            'sizing'      => $sizing,
        ];

        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id > 0 && $project = AdvisorProject::where('tenant_id', $tenant->id)->find($id)) {
            $project->fill($attrs)->save();
            return $project;
        }

        $attrs['created_by'] = $userId;
        return AdvisorProject::create($attrs);
    }
}
