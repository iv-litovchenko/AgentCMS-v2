<?php

namespace App\Service;

class VaultNodeCreator
{
    private VaultService $vaultService;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private VaultManifestReader $manifestReader;

    private VaultNodeScanner $vaultNodeScanner;

    public function __construct(
        VaultService $vaultService,
        TypeSchemaRegistry $typeSchemaRegistry,
        VaultManifestReader $manifestReader,
        VaultNodeScanner $vaultNodeScanner
    ) {
        $this->vaultService = $vaultService;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->manifestReader = $manifestReader;
        $this->vaultNodeScanner = $vaultNodeScanner;
    }

    public function bootstrapStorageSlotDirectory(string $vaultSlug, string $nodeDiskPath, string $slotFolder, string $typeId): void
    {
        $slotFolder = trim(str_replace('\\', '/', $slotFolder), '/');
        if ($slotFolder === '' || str_contains($slotFolder, '..')) {
            throw new \InvalidArgumentException('Некорректный слот.');
        }

        $slotRelativeDir = '';
        $slots = $this->typeSchemaRegistry->listStorageSlotsForNodeType($typeId, $vaultSlug);
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
            if (isset($slot['relative_path']) && is_string($slot['relative_path']) && $slot['relative_path'] !== '') {
                $slotRelativeDir = trim($slot['relative_path'], '/');
            }
            break;
        }

        if ($slotRelativeDir === '') {
            throw new \InvalidArgumentException('Слот не поддерживается типом узла.');
        }

        $manifestPath = $this->vaultNodeScanner->resolveDiskNodeAbsolutePath($vaultSlug, $nodeDiskPath);
        if ($manifestPath === null) {
            throw new \InvalidArgumentException('Узел не найден.');
        }

        $nodeDir = dirname($manifestPath);
        $slotDir = VaultNodeStorage::resolveSubtreeAbsolutePath($nodeDir, $slotRelativeDir);
        if ($slotDir === null) {
            throw new \InvalidArgumentException('Некорректный путь слота.');
        }

        if (!is_dir($slotDir)) {
            if (!mkdir($slotDir, 0775, true) && !is_dir($slotDir)) {
                throw new \RuntimeException('Не удалось создать папку слота.');
            }
        }
    }

    public function bootstrapSingleFileSlot(string $vaultSlug, string $nodeDiskPath, string $relativeFilePath, string $typeId): void
    {
        $relativeFilePath = trim(str_replace('\\', '/', $relativeFilePath), '/');
        if ($relativeFilePath === '' || str_contains($relativeFilePath, '..') || str_contains($relativeFilePath, '/')) {
            throw new \InvalidArgumentException('Некорректный путь файла слота.');
        }

        $allowed = false;
        $slots = $this->typeSchemaRegistry->listStorageSlotsForNodeType($typeId, $vaultSlug);
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

        $manifestPath = $this->vaultNodeScanner->resolveDiskNodeAbsolutePath($vaultSlug, $nodeDiskPath);
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
        $this->vaultService->writeFileAtomicPublic($filePath, $initial);
    }

    /**
     * @return array{disk_path: string, manifest_path: string}
     */
    public function createInHub(
        string $vaultSlug,
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

        $hubType = $this->typeSchemaRegistry->resolveType('hubs/' . $hubFamily, $vaultSlug);
        if ($hubType === null) {
            throw new \InvalidArgumentException('Зона hub не найдена.');
        }

        $nodeType = $this->typeSchemaRegistry->resolveType($nodeTypeId, $vaultSlug);
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

        $base = $this->vaultService->getVaultDirectory($vaultSlug);
        if ($base === null) {
            throw new \RuntimeException('Vault не найден на диске.');
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

        $manifestPath = $nodeDir . '/manifest.section.md';
        $this->vaultService->writeFileAtomicPublic($manifestPath, $this->manifestReader->buildFileContent($properties, "\n"));

        $diskPath = $this->relativeDiskPath($base, $nodeDir);

        return [
            'disk_path' => $diskPath,
            'manifest_path' => $manifestPath,
        ];
    }

    private function relativeDiskPath(string $vaultBase, string $nodeDir): string
    {
        $vaultReal = realpath($vaultBase);
        $nodeReal = realpath($nodeDir);
        if ($vaultReal === false || $nodeReal === false) {
            return '';
        }

        $prefix = rtrim($vaultReal, '/') . '/';
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
