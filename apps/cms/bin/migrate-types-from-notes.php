#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(__DIR__, 3);
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "composer autoload not found\n");
    exit(1);
}
require $autoload;

$sourceDir = $projectRoot . '/notes/awn-system/types';
$destDir = dirname(__DIR__) . '/resources/cms/types';

if (!is_dir($sourceDir)) {
    fwrite(STDERR, "Source not found: {$sourceDir}\n");
    exit(1);
}

/** @var array<string, string> $idMap */
$idMap = buildIdMap($sourceDir);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)
);

$written = 0;
$skipped = 0;

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }
    $basename = $fileInfo->getFilename();
    if (!preg_match('/\.ya?ml$/i', $basename)) {
        continue;
    }

    $relative = substr($fileInfo->getPathname(), strlen(rtrim($sourceDir, '/')) + 1);
    $relative = str_replace('\\', '/', $relative);
    $pathId = preg_replace('/\.(yaml|yml)$/i', '', $relative);
    if (!is_string($pathId)) {
        $skipped++;
        continue;
    }

    $destRelative = preg_replace('/\.ya?ml$/i', '.yaml', $relative);
    $destPath = $destDir . '/' . $destRelative;

    $raw = file_get_contents($fileInfo->getPathname());
    if ($raw === false) {
        $skipped++;
        continue;
    }

    if ($relative === 'fields/materials.yml') {
        $raw = fixMaterialsDescription($raw);
    }

    try {
        $parsed = Yaml::parse($raw);
    } catch (ParseException $exception) {
        fwrite(STDERR, "Parse failed {$relative}: " . $exception->getMessage() . "\n");
        $skipped++;
        continue;
    }

    if (!is_array($parsed)) {
        $skipped++;
        continue;
    }

    $parsed = transformNode($parsed, $idMap);
    unset($parsed['id']);
    unset($parsed['kind']);
    unset($parsed['domain']);

    $dir = dirname($destPath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot mkdir {$dir}\n");
        $skipped++;
        continue;
    }

    $out = Yaml::dump($parsed, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    file_put_contents($destPath, $out);
    $written++;
}

$aliasLines = [];
foreach ($idMap as $legacy => $path) {
    if ($legacy === $path) {
        continue;
    }
    $aliasLines[$legacy] = $path;
}
ksort($aliasLines);

$aliasHeader = "# Автогенерация migrate-types-from-notes.php — dot-id → путь\n";
$aliasBody = Yaml::dump($aliasLines, 2, 2);
file_put_contents($destDir . '/aliases.yaml', $aliasHeader . $aliasBody);

echo "Written: {$written}, skipped: {$skipped}, aliases: " . count($aliasLines) . "\n";

/**
 * @return array<string, string>
 */
function buildIdMap(string $sourceDir): array
{
    $map = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || !preg_match('/\.ya?ml$/i', $fileInfo->getFilename())) {
            continue;
        }

        $relative = substr($fileInfo->getPathname(), strlen(rtrim($sourceDir, '/')) + 1);
        $relative = str_replace('\\', '/', $relative);
        $pathId = preg_replace('/\.(yaml|yml)$/i', '', $relative);
        if (!is_string($pathId)) {
            continue;
        }

        $map[$pathId] = $pathId;

        $raw = file_get_contents($fileInfo->getPathname());
        if ($raw === false) {
            continue;
        }
        if ($relative === 'fields/materials.yml') {
            $raw = fixMaterialsDescription($raw);
        }

        try {
            $parsed = Yaml::parse($raw);
        } catch (ParseException $exception) {
            continue;
        }

        if (is_array($parsed) && isset($parsed['id']) && is_string($parsed['id'])) {
            $legacy = trim($parsed['id']);
            if ($legacy !== '') {
                $map[$legacy] = $pathId;
            }
        }
    }

    return $map;
}

function fixMaterialsDescription(string $raw): string
{
    return (string) preg_replace(
        '/^description: (.+)$/m',
        'description: "$1"',
        $raw,
        1
    );
}

/**
 * @param mixed $node
 *
 * @return mixed
 */
function transformNode($node, array $idMap)
{
    if (is_array($node)) {
        $out = [];
        foreach ($node as $key => $value) {
            $out[$key] = transformNode($value, $idMap);
        }

        return $out;
    }

    if (is_string($node)) {
        $trimmed = trim($node);
        if ($trimmed !== '' && isset($idMap[$trimmed])) {
            return $idMap[$trimmed];
        }
        if (str_starts_with($trimmed, 'ref:')) {
            $refId = substr($trimmed, 4);
            if (isset($idMap[$refId])) {
                return 'ref:' . $idMap[$refId];
            }
        }
    }

    return $node;
}
