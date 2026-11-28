<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class VaultSlotFolderWriter
{
    private VaultService $vaultService;

    private VaultNodeScanner $vaultNodeScanner;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private VaultManifestReader $manifestReader;

    private VaultRecordCreateExtensionCatalog $recordCreateExtensionCatalog;

    public function __construct(
        VaultService $vaultService,
        VaultNodeScanner $vaultNodeScanner,
        TypeSchemaRegistry $typeSchemaRegistry,
        VaultManifestReader $manifestReader,
        VaultRecordCreateExtensionCatalog $recordCreateExtensionCatalog
    ) {
        $this->vaultService = $vaultService;
        $this->vaultNodeScanner = $vaultNodeScanner;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->manifestReader = $manifestReader;
        $this->recordCreateExtensionCatalog = $recordCreateExtensionCatalog;
    }

    public function isStorageFolderDiskPath(string $folderDiskPath): bool
    {
        $folderDiskPath = trim(str_replace('\\', '/', $folderDiskPath), '/');
        if ($folderDiskPath === '') {
            return false;
        }

        $split = VaultNodeStorage::splitFolderDiskPath($folderDiskPath);

        return $split['folder_suffix'] !== '';
    }

    /**
     * @return list<string>
     */
    public function listRecordCreateExtensions(string $vaultSlug, string $folderDiskPath): array
    {
        $acceptFiles = $this->resolveAcceptFilesForFolder($vaultSlug, $folderDiskPath);

        return $this->typeSchemaRegistry->listRecordCreateExtensions($acceptFiles);
    }

    /**
     * @return list<array{label: string, items: list<array{ext: string, title: string, hint: string, option_label: string}>}>
     */
    public function listRecordCreateExtensionGroups(string $vaultSlug, string $folderDiskPath): array
    {
        $acceptFiles = $this->resolveAcceptFilesForFolder($vaultSlug, $folderDiskPath);

        return $this->typeSchemaRegistry->listRecordCreateExtensionGroups($acceptFiles);
    }

  /**
   * @return array{disk_path: string}
   */
    public function createCategory(
        string $vaultSlug,
        string $folderDiskPath,
        string $slug,
        string $name,
        string $description
    ): array {
        $this->assertStorageFolder($folderDiskPath);
        $slug = trim($slug);
        $name = trim($name);
        $description = trim($description);
        if ($slug === '' || $name === '') {
            throw new \InvalidArgumentException('Укажите slug и название раздела.');
        }
        if (!$this->isValidSlug($slug)) {
            throw new \InvalidArgumentException('Slug: kebab-case, латиница и цифры.');
        }

        $parentAbsolute = $this->resolveWritableDirectory($vaultSlug, $folderDiskPath);
        $targetDir = $parentAbsolute . '/' . $slug;
        if (file_exists($targetDir)) {
            throw new \InvalidArgumentException('Раздел уже существует.');
        }

        if (!mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Не удалось создать папку раздела.');
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $properties = [
            'acms-type' => $this->typeSchemaRegistry->publicTypeId('content/category'),
            'acms-name' => $name,
            'acms-create' => $now,
            'acms-update' => $now,
        ];
        if ($description !== '') {
            $properties['acms-description'] = $description;
        }

        $manifestPath = $targetDir . '/manifest.md';
        $this->vaultService->writeFileAtomicPublic(
            $manifestPath,
            $this->manifestReader->buildFileContent($properties, "\n")
        );

        $diskPath = $folderDiskPath . '/' . $slug;

        return [
            'disk_path' => $diskPath,
        ];
    }

    /**
     * @return array{disk_path: string}
     */
    public function createRecord(
        string $vaultSlug,
        string $folderDiskPath,
        string $title,
        string $slug,
        string $description,
        string $extension
    ): array {
        $this->assertStorageFolder($folderDiskPath);
        $title = trim($title);
        $description = trim($description);
        if ($title === '') {
            throw new \InvalidArgumentException('Укажите название записи.');
        }

        $extension = strtolower(trim($extension));
        if ($extension === '') {
            $extension = 'md';
        }
        $acceptFiles = $this->resolveAcceptFilesForFolder($vaultSlug, $folderDiskPath);
        if (!$this->typeSchemaRegistry->isRecordCreateExtensionAllowed($extension, $acceptFiles)) {
            throw new \InvalidArgumentException('Недопустимое расширение файла для этого слота.');
        }

        $slug = trim($slug);
        if ($slug === '') {
            $slug = 'record-' . (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd-His');
        }
        if (!$this->isValidSlug($slug)) {
            throw new \InvalidArgumentException('Slug: kebab-case, латиница и цифры.');
        }

        $filename = $slug . '.' . $extension;
        $parentAbsolute = $this->resolveWritableDirectory($vaultSlug, $folderDiskPath);
        $filePath = $parentAbsolute . '/' . $filename;
        if (file_exists($filePath)) {
            throw new \InvalidArgumentException('Файл уже существует.');
        }

        $contentTypeId = $this->typeSchemaRegistry->resolveRecordContentTypeIdForExtension($extension);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $properties = [
            'acms-type' => $this->typeSchemaRegistry->publicTypeId($contentTypeId),
            'acms-name' => $title,
            'acms-create' => $now,
            'acms-update' => $now,
        ];
        if ($description !== '') {
            $properties['acms-description'] = $description;
        }

        $body = '';
        if ($extension === 'md') {
            $body = "# " . $title . "\n\n";
        }

        if ($this->recordCreateExtensionCatalog->isBinaryExtension($extension)) {
            $this->vaultService->writeFileAtomicPublic($filePath, '');
        } else {
            $this->vaultService->writeFileAtomicPublic(
                $filePath,
                $this->manifestReader->buildFileContent($properties, $body)
            );
        }

        return [
            'disk_path' => $folderDiskPath . '/' . $filename,
        ];
    }

    /**
     * @param list<UploadedFile> $files
     *
     * @return array{count: int}
     */
    public function uploadFiles(string $vaultSlug, string $folderDiskPath, array $files): array
    {
        $this->assertStorageFolder($folderDiskPath);
        if (count($files) === 0) {
            throw new \InvalidArgumentException('Выберите один или несколько файлов.');
        }

        $parentAbsolute = $this->resolveWritableDirectory($vaultSlug, $folderDiskPath);
        $saved = 0;

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }
            if (!$file->isValid()) {
                continue;
            }

            $original = $file->getClientOriginalName();
            $safeName = $this->sanitizeUploadFilename($original);
            if ($safeName === '') {
                continue;
            }

            $target = $parentAbsolute . '/' . $safeName;
            if (file_exists($target)) {
                $safeName = $this->uniqueFilename($parentAbsolute, $safeName);
                $target = $parentAbsolute . '/' . $safeName;
            }

            $file->move($parentAbsolute, $safeName);
            $saved++;
        }

        if ($saved === 0) {
            throw new \InvalidArgumentException('Не удалось сохранить файлы.');
        }

        return [
            'count' => $saved,
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveAcceptFilesForFolder(string $vaultSlug, string $folderDiskPath): array
    {
        $split = VaultNodeStorage::splitFolderDiskPath($folderDiskPath);
        if ($split['folder_suffix'] === '') {
            return [];
        }

        $ownerNode = $this->vaultNodeScanner->readDiskNode($vaultSlug, $split['node_disk_path']);
        if ($ownerNode === null) {
            return [];
        }

        $typeId = '';
        $properties = $ownerNode['properties'];
        if (is_array($properties) && isset($properties['acms-type']) && is_string($properties['acms-type'])) {
            $typeId = trim($properties['acms-type']);
        }
        if ($typeId === '') {
            return [];
        }

        $slot = $this->typeSchemaRegistry->resolveMultiFileSlotContainingFolderSuffix(
            $vaultSlug,
            $typeId,
            $split['folder_suffix']
        );
        if ($slot === null) {
            return [];
        }

        if (!isset($slot['accept_files']) || !is_array($slot['accept_files'])) {
            return [];
        }

        $acceptFiles = [];
        foreach ($slot['accept_files'] as $item) {
            if (is_string($item) && trim($item) !== '') {
                $acceptFiles[] = trim($item);
            }
        }

        return $acceptFiles;
    }

    private function assertStorageFolder(string $folderDiskPath): void
    {
        if (!$this->isStorageFolderDiskPath($folderDiskPath)) {
            throw new \InvalidArgumentException('Действие доступно только внутри слота (0-storage/ или node-mount).');
        }
    }

    private function resolveWritableDirectory(string $vaultSlug, string $folderDiskPath): string
    {
        $folderDiskPath = trim(str_replace('\\', '/', $folderDiskPath), '/');
        $absolute = $this->vaultNodeScanner->resolveAbsoluteDiskPath($vaultSlug, $folderDiskPath);
        if ($absolute === null || !is_dir($absolute)) {
            throw new \InvalidArgumentException('Папка не найдена.');
        }

        return $absolute;
    }

    private function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }

    private function sanitizeUploadFilename(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name));
        if ($name === '' || str_contains($name, '..')) {
            return '';
        }

        $base = basename($name);
        if ($base === '' || $base === '.' || $base === '..') {
            return '';
        }

        $base = preg_replace('/[^\w.\-()+ @]/u', '_', $base);
        if (!is_string($base) || $base === '') {
            return '';
        }

        return $base;
    }

    private function uniqueFilename(string $directory, string $filename): string
    {
        $dot = strrpos($filename, '.');
        $stem = $filename;
        $ext = '';
        if ($dot !== false && $dot > 0) {
            $stem = substr($filename, 0, $dot);
            $ext = substr($filename, $dot);
        }

        $index = 2;
        while (file_exists($directory . '/' . $filename)) {
            $filename = $stem . '-' . (string) $index . $ext;
            $index++;
        }

        return $filename;
    }
}