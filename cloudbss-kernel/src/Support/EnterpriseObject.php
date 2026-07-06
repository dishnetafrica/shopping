<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Support;

/**
 * Universal Reference Model. Identity, tenant/entity scope, audit, version, tags.
 * Clock + IdGenerator are injected so audit-critical entities are deterministic under replay.
 * (Reference/profile aggregates may pass null and accept system defaults.)
 */
abstract class EnterpriseObject
{
    public readonly string $id;
    public readonly string $tenantId;
    public readonly string $entityId;
    public readonly \DateTimeImmutable $createdAt;
    public readonly ?string $createdBy;
    protected \DateTimeImmutable $updatedAt;
    protected string $status = 'active';
    protected int $version = 1;
    /** @var string[] */ protected array $tags = [];
    protected ?Clock $clock;

    protected function __construct(
        string $tenantId, string $entityId,
        ?Clock $clock = null, ?IdGenerator $ids = null,
        ?string $createdBy = null, ?string $id = null
    ) {
        $this->clock     = $clock;
        $this->id        = $id ?? ($ids ? $ids->next(static::idPrefix()) : Id::new(static::idPrefix()));
        $this->tenantId  = $tenantId;
        $this->entityId  = $entityId;
        $this->createdBy = $createdBy;
        $this->createdAt = $clock ? $clock->now() : new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    protected static function idPrefix(): string { return 'obj'; }
    protected function touch(): void { $this->updatedAt = $this->clock ? $this->clock->now() : new \DateTimeImmutable(); $this->version++; }
    public function version(): int { return $this->version; }
    public function status(): string { return $this->status; }
    public function type(): string { return (new \ReflectionClass($this))->getShortName(); }
}
