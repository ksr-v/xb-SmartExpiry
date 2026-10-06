<?php

require dirname(__DIR__) . '/Services/AdminBridgePatcher.php';

use Plugin\SmartExpiry\Services\AdminBridgePatcher;
$root = dirname(__DIR__, 3);
$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smart-expiry-' . bin2hex(random_bytes(6));
$files = [
    'public/assets/admin/index.html',
    'public/assets/admin/assets/index-CEIYH7i8.js',
    'public/assets/admin/locales/en-US.js',
    'public/assets/admin/locales/ru-RU.js',
    'public/assets/admin/locales/zh-CN.js',
];

try {
    foreach ($files as $relative) {
        $source = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $target = $fixture . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Could not create fixture directory.');
        }
        if (!copy($source, $target)) {
            throw new RuntimeException('Could not copy fixture file.');
        }
    }

    $patcher = new AdminBridgePatcher($fixture);
    $firstResult = $patcher->apply();
    if (!in_array($firstResult, ['upgraded from v2', 'upgraded from v3', 'upgraded from v4', 'upgraded from v5', 'upgraded from v6', 'already patched'], true)
        || $patcher->apply() !== 'already patched'
    ) {
        throw new RuntimeException('Legacy upgrade or idempotency check failed.');
    }

    $index = file_get_contents($fixture . '/public/assets/admin/index.html');
    if (substr_count($index, '?v=' . AdminBridgePatcher::MARKER) !== 4) {
        throw new RuntimeException('Admin asset cache keys were not versioned.');
    }

    foreach (array_slice($files, 1) as $relative) {
        $path = $fixture . '/' . $relative;
        file_put_contents($path, file_get_contents($path) . ' ');
    }
    if ($patcher->apply() !== 'already patched') {
        throw new RuntimeException('Version-check bypass rejected a fully marked build.');
    }

    $locale = $fixture . '/public/assets/admin/locales/en-US.js';
    $content = file_get_contents($locale);
    file_put_contents($locale, str_replace(AdminBridgePatcher::MARKER, 'tampered-build', $content));
    $before = array_map(static fn (string $relative): string => hash_file('sha256', $fixture . '/' . $relative), $files);

    try {
        $patcher->apply();
        throw new RuntimeException('Partially patched build was accepted.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'partially patched')) {
            throw $exception;
        }
    }

    $after = array_map(static fn (string $relative): string => hash_file('sha256', $fixture . '/' . $relative), $files);
    if ($before !== $after) {
        throw new RuntimeException('Rejected builds must remain unchanged.');
    }

    foreach ($files as $relative) {
        $source = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $target = $fixture . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $unknown = str_replace('smart-expiry-', 'unknown-build-', file_get_contents($source)) . ' ';
        file_put_contents($target, $unknown);
    }
    $before = array_map(static fn (string $relative): string => hash_file('sha256', $fixture . '/' . $relative), $files);
    try {
        $patcher->apply();
        throw new RuntimeException('An anchor-incompatible admin build was accepted.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'unique renewal function anchor')) {
            throw $exception;
        }
    }
    $after = array_map(static fn (string $relative): string => hash_file('sha256', $fixture . '/' . $relative), $files);
    if ($before !== $after) {
        throw new RuntimeException('Anchor-incompatible builds must remain unchanged.');
    }
    echo "SmartExpiry patcher tests passed\n";
} finally {
    if (is_dir($fixture)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($fixture);
    }
}
