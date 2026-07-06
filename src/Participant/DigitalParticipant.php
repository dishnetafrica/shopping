<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Participant;

/** A non-human worker: AI employee, API integration, bot, device. Normal aggregate. */
final class DigitalParticipant extends Participant
{
    /** @param string[] $scopedPermissions */
    public function __construct(
        string $tenantId, string $entityId, string $name,
        public readonly string $agentKind,
        private array $scopedPermissions = [],
        ?string $id = null
    ) {
        parent::__construct($tenantId, $entityId, null, null, null, $id);
        $this->displayName = $name;
    }
    public function kind(): string { return 'digital'; }
    public function can(string $permission): bool { return in_array($permission, $this->scopedPermissions, true); }
    public function permissions(): array { return $this->scopedPermissions; }
}
