<?php declare(strict_types=1);
spl_autoload_register(function (string $class): void {
    $prefix = 'CloudBSS\\Kernel\\';
    if (!str_starts_with($class, $prefix)) return;
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/../src/' . $rel . '.php';
    if (is_file($file)) require $file;
});
