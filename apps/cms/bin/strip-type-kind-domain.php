#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$typesDir = dirname(__DIR__) . '/resources/cms/types';
require dirname(__DIR__) . '/vendor/autoload.php';

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($typesDir, FilesystemIterator::SKIP_DOTS)
);

$updated = 0;

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }
    $name = $fileInfo->getFilename();
    if ($name === 'aliases.yaml' || $name === 'aliases.yml') {
        continue;
    }
    if (!preg_match('/\.ya?ml$/i', $name)) {
        continue;
    }

    $parsed = Yaml::parseFile($fileInfo->getPathname());
    if (!is_array($parsed)) {
        continue;
    }

    $changed = false;
    if (array_key_exists('kind', $parsed)) {
        unset($parsed['kind']);
        $changed = true;
    }
    if (array_key_exists('domain', $parsed)) {
        unset($parsed['domain']);
        $changed = true;
    }

    if (!$changed) {
        continue;
    }

    $out = Yaml::dump($parsed, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    file_put_contents($fileInfo->getPathname(), $out);
    $updated++;
}

echo "Stripped kind/domain from {$updated} file(s); status left as-is\n";
