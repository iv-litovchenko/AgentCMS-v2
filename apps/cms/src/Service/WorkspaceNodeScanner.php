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
                $typeId = $this->acmsManifestPropertyBridge->stringProperty($parsed['properties'], 'acms-type');
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
