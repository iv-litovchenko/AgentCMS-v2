<?php

namespace App\Service;

use Symfony\Component\Finder\Finder;

class VaultStorageTocIndexBuilder
{
    public const INDEX_RELATIVE_PATH = 'INDEX.md';

    private VaultService $vaultService;

    private VaultNodeScanner $vaultNodeScanner;

    private NodeDiskManifestRegistry $nodeDiskManifestRegistry;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private VaultManifestReader $manifestReader;

    private AcmsManifestPropertyBridge $acmsManifestPropertyBridge;

    public function __construct(
        VaultService $vaultService,
        VaultNodeScanner $vaultNodeScanner,
        NodeDiskManifestRegistry $nodeDiskManifestRegistry,
        TypeSchemaRegistry $typeSchemaRegistry,
        VaultManifestReader $manifestReader,
        AcmsManifestPropertyBridge $acmsManifestPropertyBridge
    ) {
        $this->vaultService = $vaultService;
        $this->vaultNodeScanner = $vaultNodeScanner;
        $this->nodeDiskManifestRegistry = $nodeDiskManifestRegistry;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->manifestReader = $manifestReader;
        $this->acmsManifestPropertyBridge = $acmsManifestPropertyBridge;
    }

    /**
     * @return string относительный путь INDEX.md от корня vault
     */
    public function writeIndex(string $vaultSlug): string
    {
        $absolute = $this->resolveIndexAbsolutePath($vaultSlug);
        if ($absolute === null) {
            throw new \RuntimeException('Хранилище не найдено.');
        }

        $markdown = $this->buildIndexMarkdown($vaultSlug);
        $this->vaultService->writeFileAtomicPublic($absolute, $markdown);

        return self::INDEX_RELATIVE_PATH;
    }

    public function buildIndexMarkdown(string $vaultSlug): string
    {
        $rows = $this->collectRows($vaultSlug);
        $lines = [];
        $lines[] = '# Индекс оглавлений содержимого хранилища';
        $lines[] = '';
        $lines[] = '| Тип | Путь | Название | Описание |';
        $lines[] = '| --- | --- | --- | --- |';

        $i = 0;
        for ($i = 0; $i < count($rows); $i = $i + 1) {
            $row = $rows[$i];
            $lines[] = '| '
                . $this->escapeTableCell($row['type']) . ' | '
                . $this->escapeTableCell($row['path']) . ' | '
                . $this->escapeTableCell($row['name']) . ' | '
                . $this->escapeTableCell($row['description']) . ' |';
        }

        if (count($rows) === 0) {
            $lines[] = '| — | — | — | Нет файлов |';
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return list<array{type: string, path: string, name: string, description: string}>
     */
    private function collectRows(string $vaultSlug): array
    {
        $base = $this->vaultService->getVaultDirectory($vaultSlug);
        if ($base === null) {
            return [];
        }

        /** @var array<string, array{type: string, path: string, name: string, description: string}> $byPath */
        $byPath = [];

        $finder = new Finder();
        $finder->files()->in($base)->sortByName();

        foreach ($finder as $file) {
            $filename = $file->getFilename();
            if (!$this->nodeDiskManifestRegistry->isNodeManifestFilename($filename)) {
                continue;
            }
            if ($this->isIndexOutputFile($filename)) {
                continue;
            }

            $fullPath = $file->getRealPath();
            if ($fullPath === false) {
                continue;
            }

            $relativeToVault = $this->relativePath($base, $fullPath);
            if ($relativeToVault === null) {
                continue;
            }

            $row = $this->buildRowForManifestFile($vaultSlug, $relativeToVault, $filename, $fullPath);
            if ($row === null) {
                continue;
            }

            $byPath[$row['path']] = $row;
        }

        $rootFinder = new Finder();
        $rootFinder->files()->in($base)->depth('== 0')->sortByName();

        foreach ($rootFinder as $file) {
            $filename = $file->getFilename();
            if ($this->isIndexOutputFile($filename)) {
                continue;
            }
            if ($this->nodeDiskManifestRegistry->isNodeManifestFilename($filename)) {
                continue;
            }

            $fullPath = $file->getRealPath();
            if ($fullPath === false) {
                continue;
            }

            $relativeToVault = $this->relativePath($base, $fullPath);
            if ($relativeToVault === null) {
                continue;
            }

            if (isset($byPath[$relativeToVault])) {
                continue;
            }

            $meta = $this->resolveFileMeta($fullPath);
            $typeLabel = '-';
            $typeId = $this->vaultNodeScanner->resolveRootCreatableFileTypeId($filename, $vaultSlug);
            if ($typeId !== '') {
                $typeDef = $this->typeSchemaRegistry->resolveType($typeId, $vaultSlug);
                if (is_array($typeDef) && isset($typeDef['name']) && is_string($typeDef['name']) && trim($typeDef['name']) !== '') {
                    $typeLabel = trim($typeDef['name']);
                }
            }

            $byPath[$relativeToVault] = [
                'type' => $typeLabel,
                'path' => $relativeToVault,
                'name' => $meta['name'],
                'description' => $meta['description'],
            ];
        }

        $rows = array_values($byPath);
        usort($rows, function (array $a, array $b): int {
            return strcmp($a['path'], $b['path']);
        });

        return $rows;
    }

    /**
     * @return array{type: string, path: string, name: string, description: string}|null
     */
    private function buildRowForManifestFile(
        string $vaultSlug,
        string $relativeToVault,
        string $filename,
        string $fullPath
    ): ?array {
        $diskPath = $this->nodeDiskManifestRegistry->diskPathForRelativeFile($relativeToVault, $filename);

        $typeId = '';
        $parsed = $this->manifestReader->parseFile($fullPath);
        if ($parsed !== null && isset($parsed['properties']) && is_array($parsed['properties'])) {
            $rawTypeId = $this->acmsManifestPropertyBridge->stringProperty($parsed['properties'], 'acms-type');
            if ($rawTypeId !== '') {
                $canonical = $this->typeSchemaRegistry->normalizeTypeId($rawTypeId);
                if ($canonical !== '') {
                    $typeId = $canonical;
                } else {
                    $typeId = $rawTypeId;
                }
            }
        }

        if ($typeId === '' && $diskPath !== '' && !str_contains($relativeToVault, '/')) {
            $typeId = $this->vaultNodeScanner->resolveRootCreatableFileTypeId($filename, $vaultSlug);
        }

        $name = basename($relativeToVault);
        if ($parsed !== null) {
            $properties = $parsed['properties'];
            $acmsName = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-name');
            if ($acmsName !== '') {
                $name = $acmsName;
            }
        }

        $description = '';
        if ($diskPath !== '' && $filename === 'manifest.section.md') {
            $diskNode = $this->vaultNodeScanner->readDiskNode($vaultSlug, $diskPath);
            if ($diskNode !== null) {
                if (isset($diskNode['properties']) && is_array($diskNode['properties'])) {
                    $description = $this->acmsManifestPropertyBridge->stringProperty(
                        $diskNode['properties'],
                        'acms-description'
                    );
                }
                if ($description === '' && isset($diskNode['body']) && is_string($diskNode['body'])) {
                    $description = $this->firstLine($diskNode['body']);
                }
            }
        } else {
            $meta = $this->resolveFileMeta($fullPath);
            $name = $meta['name'];
            $description = $meta['description'];
        }

        $displayPath = $relativeToVault;
        if ($filename === 'manifest.section.md' && $diskPath !== '') {
            $displayPath = $diskPath . '/' . $filename;
        }

        $rawPublicTypeId = '';
        if (is_array($parsed) && isset($parsed['properties']) && is_array($parsed['properties'])) {
            $rawPublicTypeId = $this->acmsManifestPropertyBridge->stringProperty($parsed['properties'], 'acms-type');
        }
        if ($typeId === 'sections/root/vault' || $rawPublicTypeId === 'acms.root.vault') {
            return null;
        }

        $typeLabel = $typeId;
        if ($typeId !== '') {
            $typeDef = $this->typeSchemaRegistry->resolveType($typeId, $vaultSlug);
            if (is_array($typeDef) && isset($typeDef['name']) && is_string($typeDef['name']) && trim($typeDef['name']) !== '') {
                $typeLabel = trim($typeDef['name']);
            }
        }

        return [
            'type' => $typeLabel,
            'path' => $displayPath,
            'name' => $name,
            'description' => $description,
        ];
    }

    /**
     * @return array{name: string, description: string}
     */
    private function resolveFileMeta(string $absoluteFile): array
    {
        $name = basename($absoluteFile);
        $description = '';

        $parsed = $this->manifestReader->parseFile($absoluteFile);
        if ($parsed !== null) {
            $properties = $parsed['properties'];
            $body = $parsed['body'];
            $acmsName = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-name');
            if ($acmsName !== '') {
                $name = $acmsName;
            }
            $acmsDescription = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-description');
            if ($acmsDescription !== '') {
                $description = $acmsDescription;
            } elseif ($body !== '') {
                $description = $this->firstLine($body);
            }
        }

        return [
            'name' => $name,
            'description' => $description,
        ];
    }

    private function isIndexOutputFile(string $filename): bool
    {
        if ($filename === self::INDEX_RELATIVE_PATH) {
            return true;
        }
        if ($filename === 'VAULT-MAP.md') {
            return true;
        }

        return false;
    }

    private function relativePath(string $base, string $absolute): ?string
    {
        $baseReal = realpath($base);
        $fileReal = realpath($absolute);
        if ($baseReal === false || $fileReal === false) {
            return null;
        }

        if (!str_starts_with($fileReal, $baseReal . DIRECTORY_SEPARATOR) && $fileReal !== $baseReal) {
            return null;
        }

        if ($fileReal === $baseReal) {
            return '';
        }

        $relative = substr($fileReal, strlen($baseReal) + 1);
        $relative = str_replace('\\', '/', $relative);

        return $relative;
    }

    private function resolveIndexAbsolutePath(string $vaultSlug): ?string
    {
        $base = $this->vaultService->getVaultDirectory($vaultSlug);
        if ($base === null) {
            return null;
        }

        return $base . '/' . self::INDEX_RELATIVE_PATH;
    }

    private function escapeTableCell(string $value): string
    {
        $value = str_replace("\r", '', $value);
        $value = str_replace('|', '\\|', $value);
        $value = str_replace("\n", ' ', $value);

        return trim($value);
    }

    private function firstLine(string $body): string
    {
        $parts = explode("\n", str_replace("\r", '', $body));
        if (count($parts) === 0) {
            return '';
        }

        return trim($parts[0]);
    }
}