<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class NodeDiskManifestRegistry
{
    private string $configPath;

    private TypeSchemaRegistry $typeSchemaRegistry;

    /** @var list<string>|null */
    private ?array $fixedCache = null;

    /** @var list<string>|null */
    private ?array $suffixesCache = null;

    public function __construct(string $configPath, TypeSchemaRegistry $typeSchemaRegistry)
    {
        $this->configPath = $configPath;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
    }

    public function isNodeManifestFilename(string $filename): bool
    {
        $filename = trim($filename);
        if ($filename === '') {
            return false;
        }

        foreach ($this->suffixes() as $suffix) {
            if ($suffix !== '' && str_ends_with($filename, $suffix)) {
                return true;
            }
        }

        return in_array($filename, $this->fixed(), true);
    }

    public function diskPathForRelativeFile(string $relativeToVault, string $filename): string
    {
        if ($filename === 'manifest.section.md') {
            $dir = dirname($relativeToVault);
            if ($dir === '.' || $dir === '') {
                return '';
            }

            return $dir;
        }

        if (in_array($filename, $this->fixed(), true)) {
            return $relativeToVault;
        }

        $stripped = preg_replace('/\.section\.md$/', '', $relativeToVault);
        if (is_string($stripped)) {
            return $stripped;
        }

        return $relativeToVault;
    }

    /**
     * @return list<array{file: string, preset: string, label: string, type_id: string}>
     */
    public function listRootCreatableFiles(string $vaultSlug = ''): array
    {
        $fromTypes = $this->typeSchemaRegistry->listRootDiskManifestTypes($vaultSlug);
        $result = [];
        foreach ($fromTypes as $row) {
            $result[] = [
                'file' => $row['file'],
                'preset' => $row['preset'],
                'label' => $row['label'],
                'type_id' => $row['type_id'],
            ];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function fixed(): array
    {
        if ($this->fixedCache !== null) {
            return $this->fixedCache;
        }

        $result = [];
        foreach ($this->listRootCreatableFiles() as $entry) {
            $result[] = $entry['file'];
        }

        $this->fixedCache = $result;

        return $this->fixedCache;
    }

    /**
     * @return list<string>
     */
    private function suffixes(): array
    {
        if ($this->suffixesCache !== null) {
            return $this->suffixesCache;
        }

        $config = $this->loadConfig();
        $suffixes = $config['suffixes'] ?? ['.section.md'];
        if (!is_array($suffixes)) {
            $this->suffixesCache = ['.section.md'];

            return $this->suffixesCache;
        }

        $result = [];
        foreach ($suffixes as $item) {
            if (is_string($item) && $item !== '') {
                $result[] = $item;
            }
        }
        if (count($result) === 0) {
            $result = ['.section.md'];
        }

        $this->suffixesCache = $result;

        return $this->suffixesCache;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        if (!is_file($this->configPath)) {
            return [];
        }

        $parsed = Yaml::parseFile($this->configPath);
        if (!is_array($parsed)) {
            return [];
        }

        return $parsed;
    }
}
