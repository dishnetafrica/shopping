<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Support;
/** Ids are injected/assigned, never self-generated at random inside entities — so replay is deterministic. */
interface IdGenerator { public function next(string $prefix = ''): string; }
