<?php

namespace App\Service;

class WorkspaceNodeCreator
{
    private WorkspaceService $workspaceService;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private WorkspaceManifestReader $manifestReader;

    public function __construct(
        WorkspaceService $workspaceService,
        TypeSchemaRegistry $typeSchemaRegistry,
        WorkspaceManifestReader $manifestReader
    ) {
        $this->workspaceService = $workspaceService;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->manifestReader = $manifestReader;
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
            'awn-type' => $nodeTypeId,
            'awn-name' => $name,
            'awn-description' => $description,
            'awn-create' => $now,
            'awn-update' => $now,
        ];

        $manifestPath = $nodeDir . '/manifest.node.md';
        $this->workspaceService->writeFileAtomicPublic($manifestPath, $this->manifestReader->buildFileContent($properties, "\n"));

        $this->bootstrapStorageSlots($nodeDir, $nodeType);

        $diskPath = $this->relativeDiskPath($base, $nodeDir);

        return [
            'disk_path' => $diskPath,
            'manifest_path' => $manifestPath,
        ];
    }

    /**
     * @param array<string, mixed> $nodeType
     */
    private function bootstrapStorageSlots(string $nodeDir, array $nodeType): void
    {
        $slots = $nodeType['storage-slots'] ?? null;
        if (!is_array($slots)) {
            return;
        }

        $storageRoot = $nodeDir . '/awn-storage';
        if (!is_dir($storageRoot)) {
            mkdir($storageRoot, 0775, true);
        }

        foreach ($slots as $slot) {
            if (!is_string($slot) || $slot === '') {
                continue;
            }
            $slot = trim(str_replace('\\', '/', $slot), '/');
            if ($slot === '' || str_contains($slot, '..')) {
                continue;
            }
            $slotDir = $storageRoot . '/' . $slot;
            if (!is_dir($slotDir)) {
                mkdir($slotDir, 0775, true);
            }
        }
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
