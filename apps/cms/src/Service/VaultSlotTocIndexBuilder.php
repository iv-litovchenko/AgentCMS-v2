<?php

namespace App\Service;

use Symfony\Component\Finder\Finder;

class VaultSlotTocIndexBuilder
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

    public function indexExists(string $vaultSlug, string $nodeDiskPath): bool
    {
        $absolute = $this->resolveIndexAbsolutePath($vaultSlug, $nodeDiskPath);
        if ($absolute === null) {
            return false;
        }

        return is_file($absolute);
    }

  /**
   * @return string относительный путь index.md от корня vault
   */
    public function writeIndex(string $vaultSlug, string $nodeDiskPath, string $typeId): string
    {
        $absolute = $this->resolveIndexAbsolutePath($vaultSlug, $nodeDiskPath);
        if ($absolute === null) {
            throw new \RuntimeException('Узел не найден.');
        }

        $markdown = $this->buildIndexMarkdown($vaultSlug, $nodeDiskPath, $typeId);
        $this->vaultService->writeFileAtomicPublic($absolute, $markdown);

        $diskPath = $nodeDiskPath;
        if ($diskPath !== '') {
            $diskPath = $diskPath . '/' . self::INDEX_RELATIVE_PATH;
        } else {
            $diskPath = self::INDEX_RELATIVE_PATH;
        }

        return $diskPath;
    }

    /**
     * @return list<array{slot: string, path: string, name: string, description: string, size: string, lines: string}>
     */
    public function collectRowsForNode(string $vaultSlug, string $nodeDiskPath, string $typeId): array
    {
        return $this->collectRows($vaultSlug, $nodeDiskPath, $typeId);
    }

    public function buildIndexMarkdown(string $vaultSlug, string $nodeDiskPath, string $typeId): string
    {
        $rows = $this->collectRows($vaultSlug, $nodeDiskPath, $typeId);
        $lines = [];
        $lines[] = '# Индекс оглавлений содержимого слотов';
        $lines[] = '';

        $lines[] = '| Слот | Путь | Название | Описание | Размер файла | Кол-во строк |';
        $lines[] = '| --- | --- | --- | --- | --- | --- |';

        $i = 0;
        for ($i = 0; $i < count($rows); $i = $i + 1) {
            $row = $rows[$i];
            $lines[] = '| '
                . $this->escapeTableCell($row['slot']) . ' | '
                . $this->escapeTableCell($row['path']) . ' | '
                . $this->escapeTableCell($row['name']) . ' | '
                . $this->escapeTableCell($row['description']) . ' | '
                . $this->escapeTableCell($row['size']) . ' | '
                . $this->escapeTableCell($row['lines']) . ' |';
        }

        if (count($rows) === 0) {
            $lines[] = '| — | — | — | Нет файлов в слотах | — | — |';
        }

        $lines[] = '';

        foreach ($this->buildContentTypesCatalogLines($vaultSlug, $typeId) as $catalogLine) {
            $lines[] = $catalogLine;
        }
        foreach ($this->buildSlotsCatalogLines($vaultSlug, $typeId) as $catalogLine) {
            $lines[] = $catalogLine;
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function buildSlotsCatalogLines(string $vaultSlug, string $typeId): array
    {
        $lines = [];
        $lines[] = '## Список типов слотов';
        $lines[] = '';

        $slots = $this->typeSchemaRegistry->listStorageSlotsForNodeType($typeId, $vaultSlug);
        if (count($slots) === 0) {
            $lines[] = '_Нет слотов в типе узла._';
            $lines[] = '';

            return $lines;
        }

        $lines[] = '| Ключ | Название | Путь | Принимает |';
        $lines[] = '| --- | --- | --- | --- |';

        $i = 0;
        for ($i = 0; $i < count($slots); $i = $i + 1) {
            $slot = $slots[$i];
            if (!is_array($slot)) {
                continue;
            }

            $name = '';
            if (isset($slot['name']) && is_string($slot['name'])) {
                $name = trim($slot['name']);
            }

            $folder = '';
            if (isset($slot['folder']) && is_string($slot['folder'])) {
                $folder = trim($slot['folder']);
            }

            $path = '';
            if (isset($slot['display_path']) && is_string($slot['display_path'])) {
                $path = trim($slot['display_path']);
            }
            if ($path === '' && isset($slot['relative_path']) && is_string($slot['relative_path'])) {
                $path = trim($slot['relative_path']);
            }

            $acceptLabel = '';
            if (isset($slot['accept_files_label']) && is_string($slot['accept_files_label'])) {
                $acceptLabel = trim($slot['accept_files_label']);
            }

            $lines[] = '| '
                . $this->escapeTableCell($folder) . ' | '
                . $this->escapeTableCell($name) . ' | '
                . $this->escapeTableCell($path) . ' | '
                . $this->escapeTableCell($acceptLabel) . ' |';
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function buildContentTypesCatalogLines(string $vaultSlug, string $typeId): array
    {
        $lines = [];
        $lines[] = '## Список типов контента';
        $lines[] = '';

        $types = $this->typeSchemaRegistry->listAllowedContentTypesForNodeType($typeId, $vaultSlug);
        if (count($types) === 0) {
            $lines[] = '_Типы allowed-content не заданы в слотах._';
            $lines[] = '';

            return $lines;
        }

        $lines[] = '| Тип | Название |';
        $lines[] = '| --- | --- |';

        $i = 0;
        for ($i = 0; $i < count($types); $i = $i + 1) {
            $item = $types[$i];
            if (!is_array($item)) {
                continue;
            }

            $name = '';
            if (isset($item['name']) && is_string($item['name'])) {
                $name = trim($item['name']);
            }

            $typeIdLabel = '';
            if (isset($item['type_id']) && is_string($item['type_id'])) {
                $typeIdLabel = trim($item['type_id']);
            }

            $lines[] = '| '
                . $this->escapeTableCell($typeIdLabel) . ' | '
                . $this->escapeTableCell($name) . ' |';
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<array{slot: string, path: string, name: string, description: string, size: string, lines: string}>
     */
    private function collectRows(string $vaultSlug, string $nodeDiskPath, string $typeId): array
    {
        $slots = $this->typeSchemaRegistry->listStorageSlotsForNodeType($typeId, $vaultSlug);
        $rows = [];

        foreach ($slots as $slot) {
            $slotName = '';
            if (isset($slot['name']) && is_string($slot['name'])) {
                $slotName = $slot['name'];
            }
            if ($slotName === '' && isset($slot['folder']) && is_string($slot['folder'])) {
                $slotName = $slot['folder'];
            }

            $isSingle = false;
            if (isset($slot['single_file']) && $slot['single_file'] === true) {
                $isSingle = true;
            }

            $relativePath = '';
            if (isset($slot['relative_path']) && is_string($slot['relative_path'])) {
                $relativePath = $slot['relative_path'];
            }

            if ($isSingle) {
                $content = $this->vaultNodeScanner->readFileRelativeToDiskNode($vaultSlug, $nodeDiskPath, $relativePath);
                if ($content === null) {
                    continue;
                }
                $fileDiskPath = $nodeDiskPath;
                if ($relativePath !== '') {
                    if ($fileDiskPath !== '') {
                        $fileDiskPath = $fileDiskPath . '/' . $relativePath;
                    } else {
                        $fileDiskPath = $relativePath;
                    }
                }
                $absolute = $this->vaultNodeScanner->resolveAbsoluteDiskPath($vaultSlug, $fileDiskPath);
                if ($absolute === null || !is_file($absolute)) {
                    continue;
                }
                $meta = $this->resolveFileMeta($absolute);
                $rows[] = $this->buildRow($vaultSlug, $slotName, $relativePath, $absolute, $meta);
                continue;
            }

            $displayPath = '';
            if (isset($slot['display_path']) && is_string($slot['display_path'])) {
                $displayPath = rtrim($slot['display_path'], '/');
            }
            if ($displayPath === '' && isset($slot['folder']) && is_string($slot['folder'])) {
                $displayPath = VaultNodeStorage::DIR_NAME . '/' . $slot['folder'];
            }

            $absoluteDir = $this->vaultNodeScanner->resolveAbsoluteDiskPath(
                $vaultSlug,
                $nodeDiskPath !== '' ? $nodeDiskPath . '/' . $displayPath : $displayPath
            );
            if ($absoluteDir === null || !is_dir($absoluteDir)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($absoluteDir)->ignoreDotFiles(true)->sortByName();

            foreach ($finder as $file) {
                $fileAbsolute = $file->getRealPath();
                if ($fileAbsolute === false) {
                    continue;
                }
                $relToNode = $this->relativePathFromNode($vaultSlug, $nodeDiskPath, $fileAbsolute);
                if ($relToNode === '') {
                    continue;
                }
                if ($relToNode === self::INDEX_RELATIVE_PATH) {
                    continue;
                }
                $baseName = basename($fileAbsolute);
                if ($baseName === 'manifest.section.md' || $baseName === 'manifest.md') {
                    continue;
                }

                $meta = $this->resolveFileMeta($fileAbsolute);
                $rows[] = $this->buildRow($vaultSlug, $slotName, $relToNode, $fileAbsolute, $meta);
            }
        }

        usort($rows, function (array $a, array $b): int {
            return strcmp($a['path'], $b['path']);
        });

        return $rows;
    }

    /**
     * @param array{name: string, description: string} $meta
     *
     * @return array{slot: string, path: string, name: string, description: string, size: string, lines: string}
     */
    private function buildRow(string $vaultSlug, string $slotName, string $path, string $absoluteFile, array $meta): array
    {
        $sizeBytes = filesize($absoluteFile);
        $sizeLabel = '—';
        if ($sizeBytes !== false) {
            $sizeLabel = $this->formatBytes($sizeBytes);
        }

        $lineCount = 0;
        $content = file_get_contents($absoluteFile);
        if (is_string($content)) {
            $lineCount = $this->countLines($content);
        }

        return [
            'slot' => $slotName,
            'path' => VaultTocIndexDisplayPath::withVaultPrefix($vaultSlug, $path),
            'name' => $meta['name'],
            'description' => $meta['description'],
            'size' => $sizeLabel,
            'lines' => (string) $lineCount,
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

    private function relativePathFromNode(string $vaultSlug, string $nodeDiskPath, string $fileAbsolute): string
    {
        $manifestPath = $this->vaultNodeScanner->resolveDiskNodeAbsolutePath($vaultSlug, $nodeDiskPath);
        if ($manifestPath === null) {
            return '';
        }

        $nodeDir = dirname($manifestPath);
        $nodeReal = realpath($nodeDir);
        $fileReal = realpath($fileAbsolute);
        if ($nodeReal === false || $fileReal === false) {
            return '';
        }

        if (!str_starts_with($fileReal, $nodeReal . DIRECTORY_SEPARATOR) && $fileReal !== $nodeReal) {
            return '';
        }

        $relative = substr($fileReal, strlen($nodeReal) + 1);
        $relative = str_replace('\\', '/', $relative);

        return $relative;
    }

    private function resolveIndexAbsolutePath(string $vaultSlug, string $nodeDiskPath): ?string
    {
        $manifestPath = $this->vaultNodeScanner->resolveDiskNodeAbsolutePath($vaultSlug, $nodeDiskPath);
        if ($manifestPath === null) {
            return null;
        }

        return dirname($manifestPath) . '/' . self::INDEX_RELATIVE_PATH;
    }

    private function escapeTableCell(string $value): string
    {
        $value = str_replace("\r", '', $value);
        $value = str_replace('|', '\\|', $value);
        $value = str_replace("\n", ' ', $value);

        return trim($value);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            $kb = round($bytes / 1024, 1);

            return $kb . ' KB';
        }
        $mb = round($bytes / 1048576, 2);

        return $mb . ' MB';
    }

    private function countLines(string $content): int
    {
        if ($content === '') {
            return 0;
        }
        $lines = substr_count($content, "\n");
        if (!str_ends_with($content, "\n")) {
            $lines = $lines + 1;
        }

        return $lines;
    }

    private function firstLine(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        $parts = explode("\n", $body);
        if (count($parts) === 0) {
            return '';
        }

        return trim($parts[0]);
    }
}