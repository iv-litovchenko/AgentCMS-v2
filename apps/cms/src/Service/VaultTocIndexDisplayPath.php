<?php

namespace App\Service;

class VaultTocIndexDisplayPath
{
    public static function withVaultPrefix(string $vaultSlug, string $relativePath): string
    {
        $vaultSlug = trim(str_replace('\\', '/', $vaultSlug), '/');
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($vaultSlug === '') {
            return $relativePath;
        }
        if ($relativePath === '') {
            return $vaultSlug;
        }

        return $vaultSlug . '/' . $relativePath;
    }
}