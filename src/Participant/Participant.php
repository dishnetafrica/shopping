<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Participant;

use CloudBSS\Kernel\Support\EnterpriseObject;

/** Anyone or anything that does work. Party | DigitalParticipant. */
abstract class Participant extends EnterpriseObject
{
    protected static function idPrefix(): string { return 'pt'; }
    abstract public function kind(): string;              // 'party' | 'digital'
    abstract public function can(string $permission): bool;
    public function displayName(): string { return $this->displayName; }
    protected string $displayName = '';
}
