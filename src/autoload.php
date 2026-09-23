<?php

declare(strict_types=1);

// PSR-4 autoloader: namespace LuaPhp\ maps to this directory (src/).
spl_autoload_register(static function (string $className): void {
    $namespacePrefix = 'LuaPhp\\';
    if (strncmp($className, $namespacePrefix, strlen($namespacePrefix)) !== 0) {
        return;
    }
    $relativeClassName = substr($className, strlen($namespacePrefix));
    $classFile = __DIR__ . '/' . str_replace('\\', '/', $relativeClassName) . '.php';
    if (is_file($classFile)) {
        require $classFile;
    }
});
