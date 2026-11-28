<?php

namespace App\Service;

use Symfony\Component\Finder\Finder;

class VaultNodeScanner
{
    private VaultService $vaultService;

    private VaultManifestReader $manifestReader;

    private NodeDiskManifestRegistry $nodeDiskManifestRegistry;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private AcmsManifestPropertyBridge $acmsManifestPropertyBridge;

    public function __construct(
        VaultService $vaultService,
        VaultManifestReader $manifestReader,
        NodeDiskManifestRegistry $nodeDiskManifestRegistry,
        TypeSchemaRegistry $typeSchemaRegistry,
        AcmsManifestPropertyBridge $acmsManifestPropertyBridge
    ) {
        $this->vaultService = $vaultService;
        $this->manifestReader = $manifestReader;
        $this->nodeDiskManifestRegistry = $nodeDiskManifestRegistry;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->acmsManifestPropertyBridge = $acmsManifestPropertyBridge;
    }

    /**
     * @param list<array<string, mixed>> $groups
     *
     * @return list<array<string, mixed>>
     */
    public function attachDiskNodesToHubTree(array $groups, string $vaultSlug): array
    {
        foreach ($groups as $index => $group) {
            $sectionChildren = true;
            if (isset($group['tree_section_children']) && $group['tree_section_children'] === false) {
                $sectionChildren = false;
            }
            if (!$sectionChildren) {
                $groups[$index]['items'] = [];
                continue;
            }
            $folder = '';
            if (isset($group['folder']) && is_string($group['folder'])) {
                $folder = $group['folder'];
            }
            $items = $this->listNodeFilesInHubFolder($vaultSlug, $folder);
            $groups[$index]['items'] = $this->filterItemsExcludedByNestedHubZones($items, $folder, $groups);
        }

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $groups
     *
     * @return list<array<string, mixed>>
     */
    public function attachNestedDiskNodesToHubTree(array $groups, string $vaultSlug): array
    {
        $groups = $this->attachDiskNodesToHubTree($groups, $vaultSlug);
        foreach ($groups as $index => $group) {
            $items = [];
            if (isset($group['items']) && is_array($group['items'])) {
                $items = $group['items'];
            }
            $groups[$index]['items'] = $this->nestDiskNodeItems($items);
        }

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    public function nestDiskNodeItems(array $items): array
    {
        if (count($items) === 0) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $nodes */
        $nodes = [];
        foreach ($items as $item) {
            $path = '';
            if (isset($item['disk_path']) && is_string($item['disk_path'])) {
                $path = trim(str_replace('\\', '/', $item['disk_path']), '/');
            }
            $nodes[$path] = $item;
            $nodes[$path]['children'] = [];
        }

        /** @var list<string> $rootPaths */
        $rootPaths = [];
        foreach (array_keys($nodes) as $path) {
            $parent = $this->parentDiskPath($path);
            if ($parent === '' || !isset($nodes[$parent])) {
                $rootPaths[] = $path;
            } else {
                $childList = $nodes[$parent]['children'];
                if (!is_array($childList)) {
                    $childList = [];
                }
                $childList[] = $path;
                $nodes[$parent]['children'] = $childList;
            }
        }

        usort($rootPaths, function (string $a, string $b) use ($nodes): int {
            if (!isset($nodes[$a]) || !isset($nodes[$b])) {
                return 0;
            }

            return $this->compareHubTreeDiskItems($nodes[$a], $nodes[$b]);
        });

        $result = [];
        foreach ($rootPaths as $path) {
            $result[] = $this->buildNestedHubTreeNode($nodes, $path);
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     *
     * @return array<string, mixed>
     */
    private function buildNestedHubTreeNode(array $nodes, string $path): array
    {
        $node = $nodes[$path];
        $childPaths = [];
        if (isset($node['children']) && is_array($node['children'])) {
            $childPaths = $node['children'];
        }

        $children = [];
        foreach ($childPaths as $childPath) {
            if (!is_string($childPath)) {
                continue;
            }
            if (!isset($nodes[$childPath])) {
                continue;
            }
            $children[] = $this->buildNestedHubTreeNode($nodes, $childPath);
        }

        usort($children, function (array $a, array $b): int {
            return $this->compareHubTreeDiskItems($a, $b);
        });

        unset($node['children']);
        $node['children'] = $children;

        return $node;
    }

    private function parentDiskPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return '';
        }
        $pos = strrpos($path, '/');
        if ($pos === false) {
            return '';
        }

        return substr($path, 0, $pos);
    }

    /**
     * @return list<array{disk_path: string, hub_path: string, name: string, manifest_file: string, type_id: string}>
     */
    public function listNodeFilesInHubFolder(string $vaultSlug, string $hubFolder): array
    {
        $base = $this->vaultService->getVaultDirectory($vaultSlug);
        if ($base === null) {
            return [];
        }

        $scanRoot = $base;
        $hubFolder = trim($hubFolder);
        $isVaultRoot = ($hubFolder === '' || $hubFolder === '.');
        if (!$isVaultRoot) {
            $scanRoot = $base . '/' . $hubFolder;
        }

        if (!is_dir($scanRoot)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($scanRoot)->sortByName();
        if ($isVaultRoot) {
            $finder->depth('== 0');
            $finder->ignoreDotFiles(false);
        }

        $items = [];
        foreach ($finder as $file) {
            $filename = $file->getFilename();
            if (!$this->nodeDiskManifestRegistry->isNodeManifestFilename($filename)) {
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

            $diskPath = $this->nodeDiskManifestRegistry->diskPathForRelativeFile($relativeToVault, $filename);
            $parsed = $this->manifestReader->parseFile($fullPath);
            $name = $this->resolveInstanceName($parsed, $diskPath, $filename);

            $typeId = '';
            if (is_array($parsed) && isset($parsed['properties']) && is_array($parsed['properties'])) {
                $rawTypeId = $this->acmsManifestPropertyBridge->stringProperty($parsed['properties'], 'acms-type');
                if ($rawTypeId !== '') {
                    $canonicalTypeId = $this->typeSchemaRegistry->normalizeTypeId($rawTypeId);
                    if ($canonicalTypeId !== '') {
                        $typeId = $canonicalTypeId;
                    } else {
                        $typeId = $rawTypeId;
                    }
                }
            }
            if ($typeId === '' && $isVaultRoot) {
                $typeId = $this->resolveRootMarkdownFileTypeId($filename, $vaultSlug);
            }

            if ($isVaultRoot && $name === $filename && $typeId !== '') {
                $typeDef = $this->typeSchemaRegistry->resolveType($typeId, $vaultSlug);
                if (is_array($typeDef) && isset($typeDef['name']) && is_string($typeDef['name']) && trim($typeDef['name']) !== '') {
                    $name = trim($typeDef['name']);
                }
            }

            $treeUi = $this->typeSchemaRegistry->resolveTreeUiForTypeId($typeId, $vaultSlug);
            $allowsChildren = $this->typeSchemaRegistry->sectionAllowsSectionChildren($typeId, $vaultSlug);

            $items[] = [
                'disk_path' => $diskPath,
                'hub_path' => $diskPath,
                'name' => $name,
                'manifest_file' => $filename,
                'type_id' => $typeId,
                'tree_icon' => $treeUi['icon'],
                'tree_icon_class' => $treeUi['icon_class'],
                'allows_section_children' => $allowsChildren,
            ];
        }

        usort($items, function (array $a, array $b): int {
            return $this->compareHubTreeDiskItems($a, $b);
        });

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param string $hubFolder
     *
     * @return list<array<string, mixed>>
     */
    public function filterItemsToHubZoneRoots(array $items, string $hubFolder): array
    {
        $hubFolder = trim(str_replace('\\', '/', $hubFolder), '/');
        $filtered = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $diskPath = '';
            if (isset($item['disk_path']) && is_string($item['disk_path'])) {
                $diskPath = trim(str_replace('\\', '/', $item['disk_path']), '/');
            }
            if ($diskPath === '') {
                continue;
            }

            $relative = $diskPath;
            if ($hubFolder !== '' && $hubFolder !== '.') {
                $prefix = $hubFolder . '/';
                if (!str_starts_with($diskPath, $prefix)) {
                    continue;
                }
                $relative = substr($diskPath, strlen($prefix));
            }

            if ($relative === '') {
                continue;
            }
            if (str_contains($relative, '/')) {
                continue;
            }

            $filtered[] = $item;
        }

        usort($filtered, function (array $a, array $b): int {
            return $this->compareHubTreeDiskItems($a, $b);
        });

        return $filtered;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $groups
     *
     * @return list<array<string, mixed>>
     */
    private function filterItemsExcludedByNestedHubZones(array $items, string $hubFolder, array $groups): array
    {
        $hubFolder = trim(str_replace('\\', '/', $hubFolder), '/');
        $excludePrefixes = [];
        foreach ($groups as $other) {
            if (!is_array($other)) {
                continue;
            }
            $otherFolder = '';
            if (isset($other['folder']) && is_string($other['folder'])) {
                $otherFolder = trim(str_replace('\\', '/', $other['folder']), '/');
            }
            if ($otherFolder === '' || $otherFolder === $hubFolder) {
                continue;
            }
            if ($hubFolder === '' || str_starts_with($otherFolder, $hubFolder . '/')) {
                $excludePrefixes[] = $otherFolder . '/';
            }
        }
        if (count($excludePrefixes) === 0) {
            return $items;
        }

        $filtered = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $diskPath = '';
            if (isset($item['disk_path']) && is_string($item['disk_path'])) {
                $diskPath = trim(str_replace('\\', '/', $item['disk_path']), '/');
            }
            if ($diskPath === '') {
                continue;
            }
            $skip = false;
            foreach ($excludePrefixes as $prefix) {
                if (str_starts_with($diskPath, $prefix)) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function compareHubTreeDiskItems(array $a, array $b): int
    {
        $containerA = false;
        if (isset($a['allows_section_children']) && $a['allows_section_children'] === true) {
            $containerA = true;
        }
        $containerB = false;
        if (isset($b['allows_section_children']) && $b['allows_section_children'] === true) {
            $containerB = true;
        }
        if ($containerA !== $containerB) {
            if ($containerA) {
                return -1;
            }

            return 1;
        }

        $nameA = '';
        $nameB = '';
        if (isset($a['name']) && is_string($a['name'])) {
            $nameA = $a['name'];
        }
        if (isset($b['name']) && is_string($b['name'])) {
            $nameB = $b['name'];
        }

        return strcmp($nameA, $nameB);
    }

    /**
     * @return array{properties: array<string, mixed>, body: string, manifest_file: string, disk_path: string}|null
     */
    public function readDiskNode(string $vaultSlug, string $diskPath): ?array
    {
        $base = $this->vaultService->getVaultDirectory($vaultSlug);
        if ($base === null) {
            return null;
        }

        $diskPath = trim(str_replace('\\', '/', $diskPath), '/');
        $candidates = $this->resolveReadCandidates($base, $diskPath);

        foreach ($candidates as $path) {
            $parsed = $this->manifestReader->parseFile($path);
            if ($parsed !== null) {
                return [
                    'properties' => $parsed['properties'],
                    'body' => $parsed['body'],
                    'manifest_file' => basename($path),
                    'disk_path' => $diskPath,
                ];
            }
        }

        return null;
    }

    public function resolveDiskNodeAbsolutePath(string $vaultSlug, string $diskPath): ?string
    {
        $base = $this->vaultService->getVaultDirectory($vaultSlug);
        if ($base === null) {
            return null;
        }

        $diskPath = trim(str_replace('\\', '/', $diskPath), '/');
        foreach ($this->resolveReadCandidates($base, $diskPath) as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function readFileRelativeToDiskNode(string $vaultSlug, string $diskPath, string $relativePath): ?string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $manifestPath = $this->resolveDiskNodeAbsolutePath($vaultSlug, $diskPath);
        if ($manifestPath === null) {
            return null;
        }

        $nodeDir = dirname($manifestPath);
        $filePath = $nodeDir . '/' . $relativePath;
        if (!is_file($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $maxBytes = 524288;
        if (strlen($content) > $maxBytes) {
            return substr($content, 0, $maxBytes) . "\n…";
        }

        return $content;
    }

    public function countFilesInDiskNodeSubtree(string $vaultSlug, string $diskPath, string $relativeDir): int
    {
        $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
        if ($relativeDir === '' || str_contains($relativeDir, '..')) {
            return 0;
        }

        $manifestPath = $this->resolveDiskNodeAbsolutePath($vaultSlug, $diskPath);
        if ($manifestPath === null) {
            return 0;
        }

        $nodeDir = dirname($manifestPath);
        $dirPath = VaultNodeStorage::resolveSubtreeAbsolutePath($nodeDir, $relativeDir);
        if ($dirPath === null || !is_dir($dirPath)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($dirPath)->ignoreDotFiles(true);

        $count = 0;
        foreach ($finder as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    public function resolveAbsoluteDiskPath(string $vaultSlug, string $diskPath): ?string
    {
        $base = $this->vaultService->getVaultDirectory($vaultSlug);
        if ($base === null) {
            return null;
        }

        $diskPath = trim(str_replace('\\', '/', $diskPath), '/');
        if (str_contains($diskPath, '..')) {
            return null;
        }

        $realBase = realpath($base);
        if ($realBase === false) {
            return null;
        }

        $absolute = $base . '/' . $diskPath;
        if (!file_exists($absolute)) {
            $mapped = $this->mapCanonicalStoragePathToDisk($vaultSlug, $diskPath);
            if ($mapped !== null) {
                $absolute = $mapped;
            }
        }

        if (!file_exists($absolute)) {
            return null;
        }

        $real = realpath($absolute);
        if ($real === false) {
            return null;
        }
        if (!str_starts_with($real, $realBase)) {
            return null;
        }

        return $real;
    }

    /**
     * @return array{disk_path: string, parent_disk_path: string, label: string, entries: list<array{name: string, disk_path: string, is_dir: bool}>}|null
     */
    public function readDiskDirectoryListing(string $vaultSlug, string $diskPath): ?array
    {
        $diskPath = trim(str_replace('\\', '/', $diskPath), '/');
        $absolute = $this->resolveAbsoluteDiskPath($vaultSlug, $diskPath);
        if ($absolute === null || !is_dir($absolute)) {
            return null;
        }

        $parentDiskPath = '';
        if ($diskPath !== '') {
            $parentDiskPath = dirname($diskPath);
            if ($parentDiskPath === '.') {
                $parentDiskPath = '';
            }
        }

        $label = basename($absolute);
        if ($label === '' || $label === '.') {
            $label = $diskPath;
        }

        $entries = [];
        $handle = opendir($absolute);
        if ($handle === false) {
            return null;
        }

        while (true) {
            $name = readdir($handle);
            if ($name === false) {
                break;
            }
            if ($name === '.' || $name === '..') {
                continue;
            }

            $childDiskPath = $diskPath === '' ? $name : $diskPath . '/' . $name;
            $childAbsolute = $absolute . '/' . $name;
            $isDir = is_dir($childAbsolute);
            $entries[] = [
                'name' => $name,
                'disk_path' => $childDiskPath,
                'is_dir' => $isDir,
            ];
        }
        closedir($handle);

        usort($entries, function (array $a, array $b): int {
            $aDir = false;
            if (isset($a['is_dir']) && $a['is_dir'] === true) {
                $aDir = true;
            }
            $bDir = false;
            if (isset($b['is_dir']) && $b['is_dir'] === true) {
                $bDir = true;
            }
            if ($aDir !== $bDir) {
                if ($aDir) {
                    return -1;
                }

                return 1;
            }

            $aName = '';
            if (isset($a['name']) && is_string($a['name'])) {
                $aName = $a['name'];
            }
            $bName = '';
            if (isset($b['name']) && is_string($b['name'])) {
                $bName = $b['name'];
            }

            return strcasecmp($aName, $bName);
        });

        return [
            'disk_path' => $diskPath,
            'parent_disk_path' => $parentDiskPath,
            'label' => $label,
            'entries' => $entries,
        ];
    }

    private function mapCanonicalStoragePathToDisk(string $vaultSlug, string $diskPath): ?string
    {
        $split = VaultNodeStorage::splitFolderDiskPath($diskPath);
        if ($split['folder_suffix'] === '') {
            return null;
        }

        $manifestPath = $this->resolveDiskNodeAbsolutePath($vaultSlug, $split['node_disk_path']);
        if ($manifestPath === null) {
            return null;
        }

        $nodeDir = dirname($manifestPath);
        $resolved = VaultNodeStorage::resolveSubtreeAbsolutePath($nodeDir, $split['folder_suffix']);
        if ($resolved === null || !file_exists($resolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * Путь ведёт на корень многофайлового слота (0-storage/{folder}), папки ещё нет.
     *
     * @return array{node_disk_path: string, slot_disk_path: string, slot_folder: string, slot: array<string, mixed>}|null
     */
    public function resolveMissingStorageSlot(string $vaultSlug, string $diskPath): ?array
    {
        $diskPath = trim(str_replace('\\', '/', $diskPath), '/');
        if ($diskPath === '' || str_contains($diskPath, '..')) {
            return null;
        }

        if ($this->resolveAbsoluteDiskPath($vaultSlug, $diskPath) !== null) {
            return null;
        }

        $split = VaultNodeStorage::splitFolderDiskPath($diskPath);
        if ($split['node_disk_path'] === '' || $split['folder_suffix'] === '') {
            return null;
        }

        $folderSuffix = $split['folder_suffix'];
        $ownerNode = $this->readDiskNode($vaultSlug, $split['node_disk_path']);
        if ($ownerNode === null) {
            return null;
        }

        $typeId = $this->acmsManifestPropertyBridge->stringProperty($ownerNode['properties'], 'acms-type');
        $slots = $this->typeSchemaRegistry->listStorageSlotsForNodeType($typeId, $vaultSlug);
        $slotMeta = null;
        $slotFolder = '';
        foreach ($slots as $slot) {
            if (!isset($slot['folder']) || !is_string($slot['folder'])) {
                continue;
            }
            $isSingle = false;
            if (isset($slot['single_file']) && $slot['single_file'] === true) {
                $isSingle = true;
            }
            if ($isSingle) {
                continue;
            }

            $slotRelative = '';
            if (isset($slot['relative_path']) && is_string($slot['relative_path'])) {
                $slotRelative = trim($slot['relative_path'], '/');
            }
            if ($slotRelative === '' || $slotRelative !== $folderSuffix) {
                continue;
            }

            $slotMeta = $slot;
            $slotFolder = $slot['folder'];
            break;
        }

        if ($slotMeta === null || $slotFolder === '') {
            return null;
        }

        return [
            'node_disk_path' => $split['node_disk_path'],
            'slot_disk_path' => $diskPath,
            'slot_folder' => $slotFolder,
            'slot' => $slotMeta,
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveReadCandidates(string $base, string $diskPath): array
    {
        $candidates = [];

        if ($diskPath === '') {
            $candidates[] = $base . '/manifest.section.md';
            foreach ($this->nodeDiskManifestRegistry->fixed() as $fixed) {
                $candidates[] = $base . '/' . $fixed;
            }

            return $candidates;
        }

        $directFile = $base . '/' . $diskPath;
        if (is_file($directFile)) {
            $candidates[] = $directFile;
        }

        $candidates[] = $base . '/' . $diskPath . '/manifest.section.md';
        $candidates[] = $base . '/' . $diskPath . '.section.md';

        $basename = basename($diskPath);
        if ($this->nodeDiskManifestRegistry->isNodeManifestFilename($basename)) {
            $candidates[] = $base . '/' . $diskPath;
        }

        return $candidates;
    }

    /**
     * @param array{properties: array<string, mixed>, body: string}|null $parsed
     */
    public function resolveRootCreatableFileTypeId(string $filename, string $vaultSlug): string
    {
        return $this->resolveRootMarkdownFileTypeId($filename, $vaultSlug);
    }

    /**
     * @return array<string, true>
     */
    public function mapExistingRootCreatableFiles(string $vaultSlug): array
    {
        $result = [];
        $base = $this->vaultService->getVaultDirectory($vaultSlug);
        if ($base === null) {
            return $result;
        }

        foreach ($this->nodeDiskManifestRegistry->listRootCreatableFiles($vaultSlug) as $entry) {
            if (!isset($entry['file']) || !is_string($entry['file'])) {
                continue;
            }
            $file = trim($entry['file']);
            if ($file === '') {
                continue;
            }
            if (is_file($base . '/' . $file)) {
                $result[$file] = true;
            }
        }

        return $result;
    }

    private function resolveRootMarkdownFileTypeId(string $filename, string $vaultSlug): string
    {
        $filename = trim($filename);
        if ($filename === '') {
            return '';
        }

        foreach ($this->nodeDiskManifestRegistry->listRootCreatableFiles($vaultSlug) as $entry) {
            if (!isset($entry['file']) || !is_string($entry['file'])) {
                continue;
            }
            if ($entry['file'] !== $filename) {
                continue;
            }
            if (isset($entry['type_id']) && is_string($entry['type_id']) && $entry['type_id'] !== '') {
                return $entry['type_id'];
            }
        }

        return 'sections/root/_file';
    }

    private function resolveInstanceName(?array $parsed, string $diskPath, string $filename): string
    {
        if (is_array($parsed)) {
            $properties = $parsed['properties'];
            $acmsName = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-name');
            if ($acmsName !== '') {
                return $acmsName;
            }
            if (isset($properties['name']) && is_string($properties['name']) && $properties['name'] !== '') {
                return $properties['name'];
            }
        }

        if ($diskPath !== '' && !str_contains($diskPath, '/')) {
            if (str_ends_with($diskPath, '.md')) {
                return preg_replace('/\.md$/', '', $diskPath) ?? $diskPath;
            }
        }

        if ($diskPath !== '') {
            $parts = explode('/', $diskPath);
            $last = $parts[count($parts) - 1];
            if ($last !== '') {
                return $last;
            }
        }

        if ($filename === 'manifest.section.md') {
            return 'manifest';
        }

        if (str_ends_with($filename, '.md')) {
            return preg_replace('/\.md$/', '', $filename) ?? $filename;
        }

        return preg_replace('/\.section\.md$/', '', $filename) ?? $filename;
    }

    private function relativePath(string $base, string $fullPath): ?string
    {
        $baseReal = realpath($base);
        $fileReal = realpath($fullPath);
        if ($baseReal === false || $fileReal === false) {
            return null;
        }

        $baseReal = rtrim($baseReal, '/') . '/';
        if (!str_starts_with($fileReal, $baseReal)) {
            return null;
        }

        return substr($fileReal, strlen($baseReal));
    }
}
