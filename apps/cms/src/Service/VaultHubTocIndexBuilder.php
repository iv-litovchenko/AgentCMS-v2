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

        $family = $this->resolveHubFamilyForFolder($vaultSlug, $hubFolderDiskPath);
        if ($family !== '') {
            $hubGroup = $this->typeSchemaRegistry->findHubTreeGroupByFamily($family, $vaultSlug);
            if ($hubGroup !== null) {
                foreach ($this->buildHubTypesSectionLines($vaultSlug, $hubGroup) as $typeLine) {
                    $lines[] = $typeLine;
                }
            }
        }

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
                'path' => VaultTocIndexDisplayPath::withVaultPrefix($vaultSlug, $displayPath),
                'name' => $name,
                'type' => $typeLabel,
                'description' => $description,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $hubGroup
     *
     * @return list<string>
     */
    private function buildHubTypesSectionLines(string $vaultSlug, array $hubGroup): array
    {
        $lines = [];
        $lines[] = '## Типы разделов хаба';
        $lines[] = '';

        $typeItems = [];
        if (isset($hubGroup['type_items']) && is_array($hubGroup['type_items'])) {
            $typeItems = $hubGroup['type_items'];
        }

        if (count($typeItems) === 0) {
            $lines[] = '_Нет зарегистрированных типов для этого хаба._';
            $lines[] = '';

            return $lines;
        }

        $lines[] = '| Тип | Название |';
        $lines[] = '| --- | --- |';

        $i = 0;
        for ($i = 0; $i < count($typeItems); $i = $i + 1) {
            $item = $typeItems[$i];
            if (!is_array($item)) {
                continue;
            }

            $name = '';
            if (isset($item['name']) && is_string($item['name'])) {
                $name = trim($item['name']);
            }

            $typeId = '';
            if (isset($item['id']) && is_string($item['id'])) {
                $typeId = trim($item['id']);
            }

            $typeLabel = $typeId;
            if ($typeId !== '') {
                $typeLabel = $this->typeSchemaRegistry->publicTypeId($typeId);
            }

            $lines[] = '| '
                . $this->escapeTableCell($typeLabel) . ' | '
                . $this->escapeTableCell($name) . ' |';
        }

        $lines[] = '';

        return $lines;
    }

    private function resolveHubFamilyForFolder(string $vaultSlug, string $hubFolderDiskPath): string
    {
        $hubFolder = trim(str_replace('\\', '/', $hubFolderDiskPath), '/');
        foreach ($this->typeSchemaRegistry->listHubTreeGroups($vaultSlug) as $group) {
            $folder = '';
            if (isset($group['folder']) && is_string($group['folder'])) {
                $folder = trim(str_replace('\\', '/', $group['folder']), '/');
            }
            if ($folder === $hubFolder) {
                if (isset($group['family']) && is_string($group['family'])) {
                    return trim($group['family']);
                }
            }
        }

        return '';
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