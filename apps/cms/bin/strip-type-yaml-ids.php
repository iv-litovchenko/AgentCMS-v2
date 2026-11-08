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
    if (!array_key_exists('id', $parsed)) {
        continue;
    }

    unset($parsed['id']);
    $out = Yaml::dump($parsed, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    file_put_contents($fileInfo->getPathname(), $out);
    $updated++;
}

$workspaceRoot = dirname(__DIR__, 2) . '/workspaces';
if (is_dir($workspaceRoot)) {
    $wsIter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspaceRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($wsIter as $fileInfo) {
        if (!$fileInfo->isFile() || !preg_match('/\/types\/.+\.ya?ml$/i', $fileInfo->getPathname())) {
            continue;
        }
        $parsed = Yaml::parseFile($fileInfo->getPathname());
        if (!is_array($parsed) || !array_key_exists('id', $parsed)) {
            continue;
        }
        unset($parsed['id']);
        file_put_contents($fileInfo->getPathname(), Yaml::dump($parsed, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        $updated++;
    }
}

echo "Removed id from {$updated} file(s)\n";
