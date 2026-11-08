<?php

namespace App\Service;

use Symfony\Component\Finder\Finder;

class WorkspaceNodeScanner
{
    private WorkspaceService $workspaceService;

    private WorkspaceManifestReader $manifestReader;

    private NodeDiskManifestRegistry $nodeDiskManifestRegistry;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private AcmsManifestPropertyBridge $acmsManifestPropertyBridge;

    public function __construct(
        WorkspaceService $workspaceService,
        WorkspaceManifestReader $manifestReader,
        NodeDiskManifestRegistry $nodeDiskManifestRegistry,
        TypeSchemaRegistry $typeSchemaRegistry,
        AcmsManifestPropertyBridge $acmsManifestPropertyBridge
    ) {
        $this->workspaceService = $workspaceService;
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
    public function attachDiskNodesToHubTree(array $groups, string $workspaceSlug): array
    {
        foreach ($groups as $index => $group) {
            $folder = '';
            if (isset($group['folder']) && is_string($group['folder'])) {
                $folder = $group['folder'];
            }
            $groups[$index]['items'] = $this->listNodeFilesInHubFolder($workspaceSlug, $folder);
        }

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $groups
     *
     * @return list<array<string, mixed>>
     */
    public function attachNestedDiskNodesToHubTree(array $groups, string $workspaceSlug): array
    {
        $groups = $this->attachDiskNodesToHubTree($groups, $workspaceSlug);
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
            $nameA = '';
            $nameB = '';
            if (isset($nodes[$a]['name']) && is_string($nodes[$a]['name'])) {
                $nameA = $nodes[$a]['name'];
            }
            if (isset($nodes[$b]['name']) && is_string($nodes[$b]['name'])) {
                $nameB = $nodes[$b]['name'];
            }

            return strcmp($nameA, $nameB);
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
            $nameA = '';
            $nameB = '';
            if (isset($a['name']) && is_string($a['name'])) {
                $nameA = $a['name'];
            }
            if (isset($b['name']) && is_string($b['name'])) {
                $nameB = $b['name'];
            }

            return strcmp($nameA, $nameB);
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
    public function listNodeFilesInHubFolder(string $workspaceSlug, string $hubFolder): array
    {
        $base = $this->workspaceService->getWorkspaceDirectory($workspaceSlug);
        if ($base === null) {
            return [];
        }

        $scanRoot = $base;
        $hubFolder = trim($hubFolder);
        $isWorkspaceRoot = ($hubFolder === '' || $hubFolder === '.');
        if (!$isWorkspaceRoot) {
            $scanRoot = $base . '/' . $hubFolder;
        }

        if (!is_dir($scanRoot)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($scanRoot)->sortByName();
        if ($isWorkspaceRoot) {
            $finder->depth('== 0');
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

            $relativeToWorkspace = $this->relativePath($base, $fullPath);
            if ($relativeToWorkspace === null) {
                continue;
            }

            $diskPath = $this->nodeDiskManifestRegistry->diskPathForRelativeFile($relativeToWorkspace, $filename);
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
            if ($typeId === '' && $isWorkspaceRoot) {
                $typeId = $this->resolveRootMarkdownFileTypeId($filename, $workspaceSlug);
            }

            $treeUi = $this->typeSchemaRegistry->resolveTreeUiForTypeId($typeId, $workspaceSlug);

            $items[] = [
                'disk_path' => $diskPath,
                'hub_path' => $diskPath,
                'name' => $name,
                'manifest_file' => $filename,
                'type_id' => $typeId,
                'tree_icon' => $treeUi['icon'],
                'tree_icon_class' => $treeUi['icon_class'],
            ];
        }

        usort($items, function (array $a, array $b): int {
            return strcmp($a['name'], $b['name']);
        });

        return $items;
    }

    /**
     * @return array{properties: array<string, mixed>, body: string, manifest_file: string, disk_path: string}|null
     */
    public function readDiskNode(string $workspaceSlug, string $diskPath): ?array
    {
        $base = $this->workspaceService->getWorkspaceDirectory($workspaceSlug);
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

    public function resolveDiskNodeAbsolutePath(string $workspaceSlug, string $diskPath): ?string
    {
        $base = $this->workspaceService->getWorkspaceDirectory($workspaceSlug);
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

    public function readFileRelativeToDiskNode(string $workspaceSlug, string $diskPath, string $relativePath): ?string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $manifestPath = $this->resolveDiskNodeAbsolutePath($workspaceSlug, $diskPath);
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

    public function countFilesInDiskNodeSubtree(string $workspaceSlug, string $diskPath, string $relativeDir): int
    {
        $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
        if ($relativeDir === '' || str_contains($relativeDir, '..')) {
            return 0;
        }

        $manifestPath = $this->resolveDiskNodeAbsolutePath($workspaceSlug, $diskPath);
        if ($manifestPath === null) {
            return 0;
        }

        $nodeDir = dirname($manifestPath);
        $dirPath = WorkspaceNodeStorage::resolveSubtreeAbsolutePath($nodeDir, $relativeDir);
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

    public function resolveAbsoluteDiskPath(string $workspaceSlug, string $diskPath): ?string
    {
        $base = $this->workspaceService->getWorkspaceDirectory($workspaceSlug);
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
            $mapped = $this->mapCanonicalStoragePathToDisk($workspaceSlug, $diskPath);
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
    public function readDiskDirectoryListing(string $workspaceSlug, string $diskPath): ?array
    {
        $diskPath = trim(str_replace('\\', '/', $diskPath), '/');
        $absolute = $this->resolveAbsoluteDiskPath($workspaceSlug, $diskPath);
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

    private function mapCanonicalStoragePathToDisk(string $workspaceSlug, string $diskPath): ?string
    {
        $split = WorkspaceNodeStorage::splitFolderDiskPath($diskPath);
        if ($split['folder_suffix'] === '') {
            return null;
        }

        $manifestPath = $this->resolveDiskNodeAbsolutePath($workspaceSlug, $split['node_disk_path']);
        if ($manifestPath === null) {
            return null;
        }

        $nodeDir = dirname($manifestPath);
        $resolved = WorkspaceNodeStorage::resolveSubtreeAbsolutePath($nodeDir, $split['folder_suffix']);
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
    public function resolveMissingStorageSlot(string $workspaceSlug, string $diskPath): ?array
    {
        $diskPath = trim(str_replace('\\', '/', $diskPath), '/');
        if ($diskPath === '' || str_contains($diskPath, '..')) {
            return null;
        }

        if ($this->resolveAbsoluteDiskPath($workspaceSlug, $diskPath) !== null) {
            return null;
        }

        $split = WorkspaceNodeStorage::splitFolderDiskPath($diskPath);
        if ($split['node_disk_path'] === '' || $split['folder_suffix'] === '') {
            return null;
        }

        $suffixParts = explode('/', $split['folder_suffix']);
        if (count($suffixParts) !== 2) {
            return null;
        }
        if ($suffixParts[0] !== WorkspaceNodeStorage::DIR_NAME) {
            return null;
        }

        $slotFolder = $suffixParts[1];
        if ($slotFolder === '' || str_contains($slotFolder, '..')) {
            return null;
        }

        $ownerNode = $this->readDiskNode($workspaceSlug, $split['node_disk_path']);
        if ($ownerNode === null) {
            return null;
        }

        $typeId = $this->acmsManifestPropertyBridge->stringProperty($ownerNode['properties'], 'acms-type');
        $slots = $this->typeSchemaRegistry->listStorageSlotsForNodeType($typeId, $workspaceSlug);
        $slotMeta = null;
        foreach ($slots as $slot) {
            if (!isset($slot['folder']) || !is_string($slot['folder'])) {
                continue;
            }
            if ($slot['folder'] !== $slotFolder) {
                continue;
            }
            $isSingle = false;
            if (isset($slot['single_file']) && $slot['single_file'] === true) {
                $isSingle = true;
            }
            if ($isSingle) {
                return null;
            }
            $slotMeta = $slot;
            break;
        }

        if ($slotMeta === null) {
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
            $candidates[] = $base . '/manifest.node.md';
            foreach ($this->nodeDiskManifestRegistry->fixed() as $fixed) {
                $candidates[] = $base . '/' . $fixed;
            }

            return $candidates;
        }

        $directFile = $base . '/' . $diskPath;
        if (is_file($directFile)) {
            $candidates[] = $directFile;
        }

        $candidates[] = $base . '/' . $diskPath . '/manifest.node.md';
        $candidates[] = $base . '/' . $diskPath . '.node.md';

        $basename = basename($diskPath);
        if ($this->nodeDiskManifestRegistry->isNodeManifestFilename($basename)) {
            $candidates[] = $base . '/' . $diskPath;
        }

        return $candidates;
    }

    /**
     * @param array{properties: array<string, mixed>, body: string}|null $parsed
     */
    private function resolveRootMarkdownFileTypeId(string $filename, string $workspaceSlug): string
    {
        $filename = trim($filename);
        if ($filename === '') {
            return '';
        }

        foreach ($this->nodeDiskManifestRegistry->listRootCreatableFiles($workspaceSlug) as $entry) {
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

        return 'nodes/root/_file';
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

        if ($filename === 'manifest.node.md') {
            return 'manifest';
        }

        if (str_ends_with($filename, '.md')) {
            return preg_replace('/\.md$/', '', $filename) ?? $filename;
        }

        return preg_replace('/\.node\.md$/', '', $filename) ?? $filename;
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
