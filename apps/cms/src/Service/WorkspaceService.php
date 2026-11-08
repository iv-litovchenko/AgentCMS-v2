<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class WorkspaceService
{
    private string $workspacesDir;

    private WorkspaceManifestReader $manifestReader;

    public function __construct(string $workspacesDir, WorkspaceManifestReader $manifestReader)
    {
        $this->workspacesDir = rtrim($workspacesDir, '/');
        $this->manifestReader = $manifestReader;
    }

    /**
     * @return list<array{id: string, slug: string, name: string, folder: string, location: string, description: string, avatar: string, sort_order: int, created_at: string, updated_at: string}>
     */
    public function list(): array
    {
        $this->ensureDirectory();

        $items = [];
        $entries = scandir($this->workspacesDir);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if (!$this->isWorkspaceDirName($entry)) {
                continue;
            }

            $workspace = $this->readWorkspaceDir($entry);
            if ($workspace !== null) {
                $items[] = $workspace;
            }
        }

        usort($items, function (array $a, array $b): int {
            if ($a['sort_order'] !== $b['sort_order']) {
                if ($a['sort_order'] < $b['sort_order']) {
                    return -1;
                }

                return 1;
            }

            return strcmp($a['name'], $b['name']);
        });

        return $items;
    }

    /**
     * @return list<array{id: string, slug: string, name: string, description: string, path: string, initials: string, location: string, avatar: string}>
     */
    public function listForDisplay(): array
    {
        $storages = [];
        foreach ($this->list() as $workspace) {
            $storages[] = [
                'id' => $workspace['slug'],
                'slug' => $workspace['slug'],
                'name' => $workspace['name'],
                'description' => $workspace['description'],
                'path' => $workspace['folder'],
                'initials' => $this->initialsFromName($workspace['name']),
                'location' => $workspace['location'],
                'avatar' => $workspace['avatar'],
            ];
        }

        return $storages;
    }

    /**
     * @return array{id: string, slug: string, name: string, folder: string, location: string, description: string, avatar: string, sort_order: int, created_at: string, updated_at: string}|null
     */
    public function findById(string $id): ?array
    {
        return $this->findBySlug($id);
    }

    /**
     * @return array{id: string, slug: string, name: string, folder: string, location: string, description: string, avatar: string, sort_order: int, created_at: string, updated_at: string}|null
     */
    public function findBySlug(string $slug): ?array
    {
        if (!$this->isValidSlug($slug)) {
            return null;
        }

        if (!is_dir($this->workspacePath($slug))) {
            return null;
        }

        return $this->readWorkspaceDir($slug);
    }

    /**
     * @param array{name: string, slug: string, location: string, sort_order?: int} $input
     */
    public function create(array $input, ?UploadedFile $avatarFile = null): array
    {
        $this->ensureDirectory();

        $name = trim($input['name']);
        $slug = strtolower(trim($input['slug']));
        if (!$this->isValidSlug($slug)) {
            throw new \InvalidArgumentException('Ключ папки: латиница, цифры и дефис (например demo-main).');
        }
        if (is_dir($this->workspacePath($slug))) {
            throw new \InvalidArgumentException('Папка «' . $slug . '» уже существует в workspaces/.');
        }

        $location = $input['location'];
        if ($location !== 'local' && $location !== 'server') {
            $location = 'local';
        }

        $sortOrder = $this->normalizeSortOrder($input['sort_order'] ?? 0);
        $now = $this->nowIso();

        $avatar = '';
        if ($avatarFile !== null) {
            $avatar = $this->storeUploadedAvatar($slug, $avatarFile);
        }

        if (!mkdir($this->workspacePath($slug), 0775, true) && !is_dir($this->workspacePath($slug))) {
            throw new \RuntimeException('Не удалось создать каталог workspace.');
        }

        $properties = [
            'awn-type' => 'nodes/root/ws',
            'awn-name' => $name,
            'awn-description' => '',
            'cms-location' => $location,
            'awn-sort' => $sortOrder,
            'awn-create' => $now,
            'awn-update' => $now,
        ];
        if ($avatar !== '') {
            $properties['cms-avatar'] = $avatar;
        }

        $manifestPath = $this->manifestPath($slug);
        $this->writeFileAtomic($manifestPath, $this->manifestReader->buildFileContent($properties, "\n"));

        $workspace = $this->readWorkspaceDir($slug);
        if ($workspace === null) {
            throw new \RuntimeException('Workspace создан, но manifest не прочитан.');
        }

        return $workspace;
    }

    /**
     * @param array{name: string, slug: string, location: string, sort_order?: int} $input
     *
     * @return array{id: string, slug: string, name: string, folder: string, location: string, description: string, avatar: string, sort_order: int, created_at: string, updated_at: string}|null
     */
    public function update(string $id, array $input, ?UploadedFile $avatarFile = null): ?array
    {
        $existing = $this->findBySlug($id);
        if ($existing === null) {
            return null;
        }

        $name = trim($input['name']);
        $newSlug = strtolower(trim($input['slug']));
        if (!$this->isValidSlug($newSlug)) {
            throw new \InvalidArgumentException('Ключ папки: латиница, цифры и дефис.');
        }

        $location = $input['location'];
        if ($location !== 'local' && $location !== 'server') {
            $location = 'local';
        }

        $sortOrder = $this->normalizeSortOrder($input['sort_order'] ?? $existing['sort_order']);

        if ($newSlug !== $existing['slug'] && is_dir($this->workspacePath($newSlug))) {
            throw new \InvalidArgumentException('Папка «' . $newSlug . '» уже занята.');
        }

        $manifestPath = $this->manifestPath($existing['slug']);
        $parts = $this->manifestReader->parseFile($manifestPath);
        if ($parts === null) {
            throw new \RuntimeException('manifest.md не найден или повреждён.');
        }

        $properties = $parts['properties'];
        $properties['awn-name'] = $name;
        $properties['cms-location'] = $location;
        $properties['awn-sort'] = $sortOrder;
        $properties['awn-update'] = $this->nowIso();
        if (!isset($properties['awn-type']) || !is_string($properties['awn-type'])) {
            $properties['awn-type'] = 'nodes/root/ws';
        }

        if ($avatarFile !== null) {
            $previousAvatar = $existing['avatar'];
            $newAvatar = $this->storeUploadedAvatar($newSlug, $avatarFile);
            $properties['cms-avatar'] = $newAvatar;
            if ($previousAvatar !== '' && $previousAvatar !== $newAvatar) {
                $this->removeAvatarFile($previousAvatar);
            }
        }

        $this->writeFileAtomic($manifestPath, $this->manifestReader->buildFileContent($properties, $parts['body']));

        if ($newSlug !== $existing['slug']) {
            $oldPath = $this->workspacePath($existing['slug']);
            $newPath = $this->workspacePath($newSlug);
            if (!rename($oldPath, $newPath)) {
                throw new \RuntimeException('Не удалось переименовать папку workspace.');
            }
        }

        return $this->readWorkspaceDir($newSlug);
    }

    public function delete(string $id): bool
    {
        if (!$this->isValidSlug($id)) {
            return false;
        }

        $dir = $this->workspacePath($id);
        if (!is_dir($dir)) {
            return false;
        }

        $workspace = $this->readWorkspaceDir($id);
        if ($workspace !== null && $workspace['avatar'] !== '') {
            $this->removeAvatarFile($workspace['avatar']);
        }

        return $this->removeDirectory($dir);
    }

    public function resolveAvatarPath(string $filename): ?string
    {
        if (!$this->isValidAvatarFilename($filename)) {
            return null;
        }

        $path = $this->avatarsDir() . '/' . $filename;
        if (!is_file($path)) {
            return null;
        }

        return $path;
    }

    public function isValidAvatarFilename(string $filename): bool
    {
        if ($filename === '') {
            return false;
        }

        if (str_contains($filename, '/') || str_contains($filename, '\\')) {
            return false;
        }

        return (bool) preg_match('/^[a-zA-Z0-9._-]+$/', $filename);
    }

    public function initialsFromName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/u', $name);
        if ($parts === false || count($parts) === 0) {
            return mb_strtoupper(mb_substr($name, 0, 1));
        }

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        $first = mb_substr($parts[0], 0, 1);
        $second = mb_substr($parts[1], 0, 1);

        return mb_strtoupper($first . $second);
    }

    /**
     * @return array{id: string, slug: string, name: string, folder: string, location: string, description: string, avatar: string, sort_order: int, created_at: string, updated_at: string}|null
     */
    private function readWorkspaceDir(string $slug): ?array
    {
        if (!$this->isValidSlug($slug)) {
            return null;
        }

        $manifestPath = $this->manifestPath($slug);
        if (!is_file($manifestPath)) {
            return null;
        }

        $meta = $this->manifestReader->readWorkspaceMeta($manifestPath);
        if ($meta === null) {
            return null;
        }

        $name = $meta['name'];
        if ($name === '') {
            $name = $slug;
        }

        $createdAt = $meta['created_at'];
        if ($createdAt === '') {
            $createdAt = $this->nowIso();
        }

        $updatedAt = $meta['updated_at'];
        if ($updatedAt === '') {
            $updatedAt = $createdAt;
        }

        $typeId = 'nodes/root/ws';
        if (isset($meta['type_id']) && is_string($meta['type_id']) && $meta['type_id'] !== '') {
            $typeId = $meta['type_id'];
        }

        return [
            'id' => $slug,
            'slug' => $slug,
            'name' => $name,
            'folder' => 'workspaces/' . $slug,
            'location' => $meta['location'],
            'description' => $meta['description'],
            'avatar' => $meta['avatar'],
            'type_id' => $typeId,
            'sort_order' => $meta['sort_order'],
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    private function isWorkspaceDirName(string $name): bool
    {
        if ($name === '.' || $name === '..') {
            return false;
        }

        if ($name === 'avatars') {
            return false;
        }

        if (str_starts_with($name, '.')) {
            return false;
        }

        return $this->isValidSlug($name);
    }

    private function workspacePath(string $slug): string
    {
        return $this->workspacesDir . '/' . $slug;
    }

    public function getWorkspaceDirectory(string $slug): ?string
    {
        $path = $this->workspacePath($slug);
        if (!is_dir($path)) {
            return null;
        }

        return $path;
    }

    private function manifestPath(string $slug): string
    {
        return $this->workspacePath($slug) . '/manifest.md';
    }

    /**
     * @return array{properties: array<string, mixed>, body: string, manifest_file: string}|null
     */
    public function readRootNodeManifest(string $slug): ?array
    {
        $base = $this->workspacePath($slug);
        $candidates = ['manifest.node.md', 'manifest.md'];
        foreach ($candidates as $file) {
            $path = $base . '/' . $file;
            $parsed = $this->manifestReader->parseFile($path);
            if ($parsed !== null) {
                return [
                    'properties' => $parsed['properties'],
                    'body' => $parsed['body'],
                    'manifest_file' => $file,
                ];
            }
        }

        return null;
    }

    private function storeUploadedAvatar(string $slug, UploadedFile $file): string
    {
        $this->ensureAvatarsDirectory();

        $extension = $file->guessExtension();
        if ($extension === null || $extension === '') {
            $extension = $file->getClientOriginalExtension();
        }
        if ($extension === null || $extension === '') {
            $extension = 'jpg';
        }

        $extension = strtolower($extension);
        $extension = preg_replace('/[^a-z0-9]/', '', $extension);
        if ($extension === null || $extension === '') {
            $extension = 'jpg';
        }

        $filename = $slug . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $targetPath = $this->avatarsDir() . '/' . $filename;
        if (is_file($targetPath)) {
            throw new \RuntimeException('Конфликт имени аватара.');
        }

        $file->move($this->avatarsDir(), $filename);

        return $filename;
    }

    private function removeAvatarFile(string $filename): void
    {
        if (!$this->isValidAvatarFilename($filename)) {
            return;
        }

        $path = $this->avatarsDir() . '/' . $filename;
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function avatarsDir(): string
    {
        return $this->workspacesDir . '/avatars';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->workspacesDir)) {
            mkdir($this->workspacesDir, 0775, true);
        }
    }

    private function ensureAvatarsDirectory(): void
    {
        $this->ensureDirectory();
        if (!is_dir($this->avatarsDir())) {
            mkdir($this->avatarsDir(), 0775, true);
        }
    }

    private function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }

    private function normalizeSortOrder(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    private function nowIso(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }

    public function writeFileAtomicPublic(string $path, string $content): void
    {
        $this->writeFileAtomic($path, $content);
    }

    private function writeFileAtomic(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $tmpPath = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        $handle = fopen($tmpPath, 'cb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open temp manifest file.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock manifest file.');
            }

            ftruncate($handle, 0);
            $written = fwrite($handle, $content);
            if ($written === false) {
                throw new \RuntimeException('Cannot write manifest file.');
            }

            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException('Cannot save manifest file.');
        }
    }

    private function removeDirectory(string $dir): bool
    {
        $realDir = realpath($dir);
        $realRoot = realpath($this->workspacesDir);
        if ($realDir === false || $realRoot === false) {
            return false;
        }

        if (!str_starts_with($realDir, $realRoot . DIRECTORY_SEPARATOR)) {
            return false;
        }

        $entries = scandir($realDir);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $realDir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                if (!$this->removeDirectory($path)) {
                    return false;
                }
                continue;
            }

            if (!unlink($path)) {
                return false;
            }
        }

        return rmdir($realDir);
    }

    public function resolveRevealAbsolutePath(string $slug, string $relativePath): ?string
    {
        $base = $this->getWorkspaceDirectory($slug);
        if ($base === null) {
            return null;
        }

        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if (str_contains($relativePath, '..')) {
            return null;
        }

        if ($relativePath === '') {
            $realBase = realpath($base);
            if ($realBase === false) {
                return null;
            }

            return $realBase;
        }

        $target = $base . '/' . $relativePath;
        $realBase = realpath($base);
        if ($realBase === false) {
            return null;
        }

        $realTarget = realpath($target);
        if ($realTarget === false) {
            $parent = dirname($target);
            $realTarget = realpath($parent);
            if ($realTarget === false) {
                return null;
            }
        }

        if (!str_starts_with($realTarget, $realBase)) {
            return null;
        }

        return $realTarget;
    }

    public function revealInFileManager(string $slug, string $relativePath): bool
    {
        $path = $this->resolveRevealAbsolutePath($slug, $relativePath);
        if ($path === null) {
            return false;
        }

        if (PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }

        if (is_file($path)) {
            exec('open -R ' . escapeshellarg($path));
        } else {
            exec('open ' . escapeshellarg($path));
        }

        return true;
    }
}
