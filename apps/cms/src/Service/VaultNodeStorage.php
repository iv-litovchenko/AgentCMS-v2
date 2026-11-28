<?php

namespace App\Service;

class VaultNodeStorage
{
    public const DIR_NAME = '0-storage';

    public const LEGACY_DIR_NAME = 'awn-storage';

    public const SOURCE_DIR_NAME = '0-source';

    public const SESSIONS_DIR_NAME = '0-sessions';

    public static function canonicalPrefix(): string
    {
        return self::DIR_NAME . '/';
    }

    public static function resolveStorageRootDir(string $nodeDir): string
    {
        $canonical = $nodeDir . '/' . self::DIR_NAME;
        if (is_dir($canonical)) {
            return $canonical;
        }

        $legacy = $nodeDir . '/' . self::LEGACY_DIR_NAME;
        if (is_dir($legacy)) {
            return $legacy;
        }

        return $canonical;
    }

    public static function activeStorageDirName(string $nodeDir): string
    {
        if (is_dir($nodeDir . '/' . self::DIR_NAME)) {
            return self::DIR_NAME;
        }
        if (is_dir($nodeDir . '/' . self::LEGACY_DIR_NAME)) {
            return self::LEGACY_DIR_NAME;
        }

        return self::DIR_NAME;
    }

    /**
     * @return list<string>
     */
    public static function nodeMountDirNames(): array
    {
        return [
            self::SOURCE_DIR_NAME,
            self::SESSIONS_DIR_NAME,
        ];
    }

    public static function isNodeMountDirName(string $dirName): bool
    {
        $dirName = trim($dirName);
        if ($dirName === '') {
            return false;
        }

        return in_array($dirName, self::nodeMountDirNames(), true);
    }

    public static function resolveSubtreeAbsolutePath(string $nodeDir, string $displayRelativeDir): ?string
    {
        $displayRelativeDir = trim(str_replace('\\', '/', $displayRelativeDir), '/');
        if ($displayRelativeDir === '' || str_contains($displayRelativeDir, '..')) {
            return null;
        }

        $firstSegment = $displayRelativeDir;
        if (str_contains($displayRelativeDir, '/')) {
            $parts = explode('/', $displayRelativeDir);
            $firstSegment = $parts[0];
        }

        if (self::isNodeMountDirName($firstSegment)) {
            return $nodeDir . '/' . $displayRelativeDir;
        }

        $suffix = $displayRelativeDir;
        if (str_starts_with($suffix, self::DIR_NAME . '/')) {
            $suffix = substr($suffix, strlen(self::DIR_NAME) + 1);
        } elseif (str_starts_with($suffix, self::LEGACY_DIR_NAME . '/')) {
            $suffix = substr($suffix, strlen(self::LEGACY_DIR_NAME) + 1);
        }

        $suffix = trim($suffix, '/');
        if ($suffix === '') {
            return VaultNodeStorage::resolveStorageRootDir($nodeDir);
        }

        return VaultNodeStorage::resolveStorageRootDir($nodeDir) . '/' . $suffix;
    }

    /**
     * @return array{node_disk_path: string, folder_suffix: string}
     */
    public static function splitFolderDiskPath(string $diskPath): array
    {
        $diskPath = trim(str_replace('\\', '/', $diskPath), '/');
        if ($diskPath === '') {
            return [
                'node_disk_path' => '',
                'folder_suffix' => '',
            ];
        }

        $parts = explode('/', $diskPath);
        $nodeParts = [];
        $tailParts = [];
        $inStorage = false;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (!$inStorage && ($part === self::DIR_NAME || $part === self::LEGACY_DIR_NAME || self::isNodeMountDirName($part))) {
                $inStorage = true;
                if ($part === self::LEGACY_DIR_NAME) {
                    $tailParts[] = self::DIR_NAME;
                } else {
                    $tailParts[] = $part;
                }
                continue;
            }
            if ($inStorage) {
                $tailParts[] = $part;
            } else {
                $nodeParts[] = $part;
            }
        }

        return [
            'node_disk_path' => implode('/', $nodeParts),
            'folder_suffix' => implode('/', $tailParts),
        ];
    }
}
