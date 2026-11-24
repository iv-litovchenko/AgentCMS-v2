<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class WorkspaceSlotFolderWriter
{
    private WorkspaceService $workspaceService;

    private WorkspaceNodeScanner $workspaceNodeScanner;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private WorkspaceManifestReader $manifestReader;

    public function __construct(
        WorkspaceService $workspaceService,
        WorkspaceNodeScanner $workspaceNodeScanner,
        TypeSchemaRegistry $typeSchemaRegistry,
        WorkspaceManifestReader $manifestReader
    ) {
        $this->workspaceService = $workspaceService;
        $this->workspaceNodeScanner = $workspaceNodeScanner;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->manifestReader = $manifestReader;
    }

    public function isStorageFolderDiskPath(string $folderDiskPath): bool
    {
        $folderDiskPath = trim(str_replace('\\', '/', $folderDiskPath), '/');
        if ($folderDiskPath === '') {
            return false;
        }

        $split = WorkspaceNodeStorage::splitFolderDiskPath($folderDiskPath);

        return $split['folder_suffix'] !== '';
    }

  /**
   * @return array{disk_path: string}
   */
    public function createCategory(
        string $workspaceSlug,
        string $folderDiskPath,
        string $slug,
        string $name
    ): array {
        $this->assertStorageFolder($folderDiskPath);
        $slug = trim($slug);
        $name = trim($name);
        if ($slug === '' || $name === '') {
            throw new \InvalidArgumentException('Укажите slug и название раздела.');
        }
        if (!$this->isValidSlug($slug)) {
            throw new \InvalidArgumentException('Slug: kebab-case, латиница и цифры.');
        }

        $parentAbsolute = $this->resolveWritableDirectory($workspaceSlug, $folderDiskPath);
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

        $manifestPath = $targetDir . '/manifest.md';
        $this->workspaceService->writeFileAtomicPublic(
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
        string $workspaceSlug,
        string $folderDiskPath,
        string $title,
        string $slug
    ): array {
        $this->assertStorageFolder($folderDiskPath);
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Укажите название записи.');
        }

        $slug = trim($slug);
        if ($slug === '') {
            $slug = 'record-' . (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd-His');
        }
        if (!$this->isValidSlug($slug)) {
            throw new \InvalidArgumentException('Slug: kebab-case, латиница и цифры.');
        }

        $filename = $slug . '.md';
        $parentAbsolute = $this->resolveWritableDirectory($workspaceSlug, $folderDiskPath);
        $filePath = $parentAbsolute . '/' . $filename;
        if (file_exists($filePath)) {
            throw new \InvalidArgumentException('Файл уже существует.');
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $properties = [
            'acms-type' => $this->typeSchemaRegistry->publicTypeId('content/record'),
            'acms-name' => $title,
            'acms-create' => $now,
            'acms-update' => $now,
        ];

        $body = "# " . $title . "\n\n";
        $this->workspaceService->writeFileAtomicPublic(
            $filePath,
            $this->manifestReader->buildFileContent($properties, $body)
        );

        return [
            'disk_path' => $folderDiskPath . '/' . $filename,
        ];
    }

    /**
     * @param list<UploadedFile> $files
     *
     * @return array{count: int}
     */
    public function uploadFiles(string $workspaceSlug, string $folderDiskPath, array $files): array
    {
        $this->assertStorageFolder($folderDiskPath);
        if (count($files) === 0) {
            throw new \InvalidArgumentException('Выберите один или несколько файлов.');
        }

        $parentAbsolute = $this->resolveWritableDirectory($workspaceSlug, $folderDiskPath);
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

    private function assertStorageFolder(string $folderDiskPath): void
    {
        if (!$this->isStorageFolderDiskPath($folderDiskPath)) {
            throw new \InvalidArgumentException('Действие доступно только внутри слота (0-storage).');
        }
    }

    private function resolveWritableDirectory(string $workspaceSlug, string $folderDiskPath): string
    {
        $folderDiskPath = trim(str_replace('\\', '/', $folderDiskPath), '/');
        $absolute = $this->workspaceNodeScanner->resolveAbsoluteDiskPath($workspaceSlug, $folderDiskPath);
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