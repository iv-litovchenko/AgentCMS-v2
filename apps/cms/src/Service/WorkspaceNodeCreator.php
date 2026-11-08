<?php

namespace App\Service;

class WorkspaceNodeCreator
{
    private WorkspaceService $workspaceService;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private WorkspaceManifestReader $manifestReader;

    private WorkspaceNodeScanner $workspaceNodeScanner;

    public function __construct(
        WorkspaceService $workspaceService,
        TypeSchemaRegistry $typeSchemaRegistry,
        WorkspaceManifestReader $manifestReader,
        WorkspaceNodeScanner $workspaceNodeScanner
    ) {
        $this->workspaceService = $workspaceService;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->manifestReader = $manifestReader;
        $this->workspaceNodeScanner = $workspaceNodeScanner;
    }

    public function bootstrapStorageSlotDirectory(string $workspaceSlug, string $nodeDiskPath, string $slotFolder, string $typeId): void
    {
        $slotFolder = trim(str_replace('\\', '/', $slotFolder), '/');
        if ($slotFolder === '' || str_contains($slotFolder, '..')) {
            throw new \InvalidArgumentException('Некорректный слот.');
        }

        $allowed = false;
        $slots = $this->typeSchemaRegistry->listStorageSlotsForNodeType($typeId, $workspaceSlug);
        foreach ($slots as $slot) {
            if (!isset($slot['folder']) || !is_string($slot['folder'])) {
                continue;
            }
            if ($slot['folder'] !== $slotFolder) {
                continue;
            }
            if (isset($slot['single_file']) && $slot['single_file'] === true) {
                throw new \InvalidArgumentException('Слот не является папкой.');
            }
            $allowed = true;
            break;
        }

        if (!$allowed) {
            throw new \InvalidArgumentException('Слот не поддерживается типом узла.');
        }

        $manifestPath = $this->workspaceNodeScanner->resolveDiskNodeAbsolutePath($workspaceSlug, $nodeDiskPath);
        if ($manifestPath === null) {
            throw new \InvalidArgumentException('Узел не найден.');
        }

        $nodeDir = dirname($manifestPath);
        $storageRoot = $nodeDir . '/' . WorkspaceNodeStorage::DIR_NAME;
        if (!is_dir($storageRoot)) {
            if (!mkdir($storageRoot, 0775, true) && !is_dir($storageRoot)) {
                throw new \RuntimeException('Не удалось создать 0-storage.');
            }
        }

        $slotDir = $storageRoot . '/' . $slotFolder;
        if (!is_dir($slotDir)) {
            if (!mkdir($slotDir, 0775, true) && !is_dir($slotDir)) {
                throw new \RuntimeException('Не удалось создать папку слота.');
            }
        }
    }

    public function bootstrapSingleFileSlot(string $workspaceSlug, string $nodeDiskPath, string $relativeFilePath, string $typeId): void
    {
        $relativeFilePath = trim(str_replace('\\', '/', $relativeFilePath), '/');
        if ($relativeFilePath === '' || str_contains($relativeFilePath, '..') || str_contains($relativeFilePath, '/')) {
            throw new \InvalidArgumentException('Некорректный путь файла слота.');
        }

        $allowed = false;
        $slots = $this->typeSchemaRegistry->listStorageSlotsForNodeType($typeId, $workspaceSlug);
        foreach ($slots as $slot) {
            if (!isset($slot['single_file']) || $slot['single_file'] !== true) {
                continue;
            }
            if (!isset($slot['relative_path']) || !is_string($slot['relative_path'])) {
                continue;
            }
            if ($slot['relative_path'] !== $relativeFilePath) {
                continue;
            }
            $allowed = true;
            break;
        }

        if (!$allowed) {
            throw new \InvalidArgumentException('Однофайловый слот не поддерживается типом узла.');
        }

        $manifestPath = $this->workspaceNodeScanner->resolveDiskNodeAbsolutePath($workspaceSlug, $nodeDiskPath);
        if ($manifestPath === null) {
            throw new \InvalidArgumentException('Узел не найден.');
        }

        $nodeDir = dirname($manifestPath);
        $filePath = $nodeDir . '/' . $relativeFilePath;
        if (is_file($filePath)) {
            throw new \InvalidArgumentException('Файл уже существует.');
        }

        $parentDir = dirname($filePath);
        if (!is_dir($parentDir)) {
            if (!mkdir($parentDir, 0775, true) && !is_dir($parentDir)) {
                throw new \RuntimeException('Не удалось создать каталог.');
            }
        }

        $initial = "\n";
        $this->workspaceService->writeFileAtomicPublic($filePath, $initial);
    }

    /**
     * @return array{disk_path: string, manifest_path: string}
     */
    public function createInHub(
        string $workspaceSlug,
        string $hubFamily,
        string $nodeTypeId,
        string $nodeSlug,
        string $name,
        string $description = ''
    ): array {
        $hubFamily = trim($hubFamily);
        $nodeTypeId = trim($nodeTypeId);
        $nodeSlug = trim($nodeSlug);
        $name = trim($name);

        if ($hubFamily === '' || $nodeTypeId === '' || $nodeSlug === '' || $name === '') {
            throw new \InvalidArgumentException('Заполните тип, slug и имя.');
        }

        if (!$this->isValidSlug($nodeSlug)) {
            throw new \InvalidArgumentException('Slug: kebab-case, латиница и цифры.');
        }

        $hubType = $this->typeSchemaRegistry->resolveType('hubs/' . $hubFamily, $workspaceSlug);
        if ($hubType === null) {
            throw new \InvalidArgumentException('Зона hub не найдена.');
        }

        $nodeType = $this->typeSchemaRegistry->resolveType($nodeTypeId, $workspaceSlug);
        if ($nodeType === null) {
            throw new \InvalidArgumentException('Тип node не найден.');
        }

        $nodeHub = '';
        if (isset($nodeType['hub']) && is_string($nodeType['hub'])) {
            $nodeHub = $nodeType['hub'];
        }
        if ($nodeHub !== $hubFamily) {
            throw new \InvalidArgumentException('Тип node не относится к этой зоне hub.');
        }

        $hubFolder = '';
        if (isset($hubType['folder']) && is_string($hubType['folder'])) {
            $hubFolder = $hubType['folder'];
        }

        $base = $this->workspaceService->getWorkspaceDirectory($workspaceSlug);
        if ($base === null) {
            throw new \RuntimeException('Workspace не найден на диске.');
        }

        $nodeDir = $base;
        if ($hubFolder !== '' && $hubFolder !== '.') {
            $hubDir = $base . '/' . $hubFolder;
            if (!is_dir($hubDir)) {
                if (!mkdir($hubDir, 0775, true) && !is_dir($hubDir)) {
                    throw new \RuntimeException('Не удалось создать каталог зоны hub.');
                }
            }
            $nodeDir = $hubDir . '/' . $nodeSlug;
        } else {
            $nodeDir = $base . '/' . $nodeSlug;
        }

        if (is_dir($nodeDir)) {
            throw new \InvalidArgumentException('Папка узла уже существует.');
        }

        if (!mkdir($nodeDir, 0775, true) && !is_dir($nodeDir)) {
            throw new \RuntimeException('Не удалось создать папку узла.');
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $properties = [
            'acms-type' => $this->typeSchemaRegistry->publicTypeId($nodeTypeId),
            'acms-name' => $name,
            'acms-description' => $description,
            'acms-create' => $now,
            'acms-update' => $now,
        ];

        $manifestPath = $nodeDir . '/manifest.node.md';
        $this->workspaceService->writeFileAtomicPublic($manifestPath, $this->manifestReader->buildFileContent($properties, "\n"));

        $diskPath = $this->relativeDiskPath($base, $nodeDir);

        return [
            'disk_path' => $diskPath,
            'manifest_path' => $manifestPath,
        ];
    }

    private function relativeDiskPath(string $workspaceBase, string $nodeDir): string
    {
        $workspaceReal = realpath($workspaceBase);
        $nodeReal = realpath($nodeDir);
        if ($workspaceReal === false || $nodeReal === false) {
            return '';
        }

        $prefix = rtrim($workspaceReal, '/') . '/';
        if (!str_starts_with($nodeReal, $prefix)) {
            return '';
        }

        return substr($nodeReal, strlen($prefix));
    }

    private function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }
}
