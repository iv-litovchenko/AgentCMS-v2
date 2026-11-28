<?php

namespace App\Service;

class VaultHubTocIndexBuilder
{
    public const INDEX_RELATIVE_PATH = 'index.md';

    private VaultService $vaultService;

    private VaultNodeScanner $vaultNodeScanner;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private VaultManifestReader $manifestReader;

    private AcmsManifestPropertyBridge $acmsManifestPropertyBridge;

    public function __construct(
        VaultService $vaultService,
        VaultNodeScanner $vaultNodeScanner,
        TypeSchemaRegistry $typeSchemaRegistry,
        VaultManifestReader $manifestReader,
        AcmsManifestPropertyBridge $acmsManifestPropertyBridge
    ) {
        $this->vaultService = $vaultService;
        $this->vaultNodeScanner = $vaultNodeScanner;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->manifestReader = $manifestReader;
        $this->acmsManifestPropertyBridge = $acmsManifestPropertyBridge;
    }

    public function indexExists(string $vaultSlug, string $hubFolderDiskPath): bool
    {
        $absolute = $this->resolveIndexAbsolutePath($vaultSlug, $hubFolderDiskPath);
        if ($absolute === null) {
            return false;
        }

        return is_file($absolute);
    }

    /**
     * @return string относительный путь index.md от корня vault
     */
    public function writeIndex(
        string $vaultSlug,
        string $hubFolderDiskPath,
        bool $hubZoneFolderOnly
    ): string {
        $absolute = $this->resolveIndexAbsolutePath($vaultSlug, $hubFolderDiskPath);
        if ($absolute === null) {
            throw new \RuntimeException('Папка hub не найдена.');
        }

        $markdown = $this->buildIndexMarkdown($vaultSlug, $hubFolderDiskPath, $hubZoneFolderOnly);
        $this->vaultService->writeFileAtomicPublic($absolute, $markdown);

        $diskPath = trim(str_replace('\\', '/', $hubFolderDiskPath), '/');
        if ($diskPath !== '') {
            $diskPath = $diskPath . '/' . self::INDEX_RELATIVE_PATH;
        } else {
            $diskPath = self::INDEX_RELATIVE_PATH;
        }

        return $diskPath;
    }

    public function buildIndexMarkdown(
        string $vaultSlug,
        string $hubFolderDiskPath,
        bool $hubZoneFolderOnly
    ): string {
        $lines = [];
        $lines[] = '# Индекс оглавлений содержимого хаба';
        $lines[] = '';

        $rows = $this->collectManifestRows($vaultSlug, $hubFolderDiskPath);
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
            $lines[] = '| — | — | — | Нет manifest в зоне |';
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return list<array{path: string, name: string, type: string, description: string}>
     */
    private function collectManifestRows(string $vaultSlug, string $hubFolderDiskPath): array
    {
        $hubFolder = trim(str_replace('\\', '/', $hubFolderDiskPath), '/');
        $items = $this->vaultNodeScanner->listHubZoneDiskSections($vaultSlug, $hubFolder);
        $rows = [];

        foreach ($items as $item) {
            $name = '';
            if (isset($item['name']) && is_string($item['name'])) {
                $name = $item['name'];
            }

            $displayPath = '';
            if (isset($item['manifest_vault_path']) && is_string($item['manifest_vault_path'])) {
                $displayPath = $item['manifest_vault_path'];
            }

            $nodeDiskPath = '';
            if (isset($item['disk_path']) && is_string($item['disk_path'])) {
                $nodeDiskPath = trim(str_replace('\\', '/', $item['disk_path']), '/');
            }

            if ($displayPath === '' && $nodeDiskPath !== '') {
                $manifestFile = 'manifest.section.md';
                if (isset($item['manifest_file']) && is_string($item['manifest_file']) && $item['manifest_file'] !== '') {
                    $manifestFile = $item['manifest_file'];
                }
                $displayPath = $nodeDiskPath . '/' . $manifestFile;
            }

            if ($displayPath === '') {
                continue;
            }

            $typeId = '';
            if (isset($item['type_id']) && is_string($item['type_id'])) {
                $typeId = $item['type_id'];
            }

            $typeLabel = $typeId;
            if ($typeId !== '') {
                $typeDef = $this->typeSchemaRegistry->resolveType($typeId, $vaultSlug);
                if (is_array($typeDef) && isset($typeDef['name']) && is_string($typeDef['name']) && trim($typeDef['name']) !== '') {
                    $typeLabel = trim($typeDef['name']);
                }
            }

            $description = '';
            if ($nodeDiskPath !== '') {
                $diskNode = $this->vaultNodeScanner->readDiskNode($vaultSlug, $nodeDiskPath);
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
            }

            if ($description === '') {
                $absolute = $this->vaultNodeScanner->resolveAbsoluteDiskPath($vaultSlug, $displayPath);
                if ($absolute !== null && is_file($absolute)) {
                    $parsed = $this->manifestReader->parseFile($absolute);
                    if ($parsed !== null) {
                        $description = $this->acmsManifestPropertyBridge->stringProperty(
                            $parsed['properties'],
                            'acms-description'
                        );
                        if ($description === '' && isset($parsed['body']) && is_string($parsed['body'])) {
                            $description = $this->firstLine($parsed['body']);
                        }
                    }
                }
            }

            $rows[] = [
                'path' => $displayPath,
                'name' => $name,
                'type' => $typeLabel,
                'description' => $description,
            ];
        }

        return $rows;
    }

    private function resolveIndexAbsolutePath(string $vaultSlug, string $hubFolderDiskPath): ?string
    {
        $hubFolder = trim(str_replace('\\', '/', $hubFolderDiskPath), '/');
        if ($hubFolder === '' || $hubFolder === '.') {
            return null;
        }

        $hubDir = $this->vaultNodeScanner->resolveAbsoluteDiskPath($vaultSlug, $hubFolder);
        if ($hubDir === null || !is_dir($hubDir)) {
            return null;
        }

        return $hubDir . '/' . self::INDEX_RELATIVE_PATH;
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