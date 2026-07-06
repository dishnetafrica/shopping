<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Support;
final class RandomIdGenerator implements IdGenerator { public function next(string $prefix = ''): string { return Id::new($prefix); } }
