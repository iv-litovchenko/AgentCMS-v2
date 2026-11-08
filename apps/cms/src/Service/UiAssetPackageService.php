<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class UiAssetPackageService
{
    private string $registryPath;

    private string $togglesFilePath;

    /** @var array<string, mixed>|null */
    private ?array $registryCache = null;

    /** @var array<string, bool>|null */
    private ?array $togglesCache = null;

    public function __construct(string $registryPath, string $togglesFilePath)
    {
        $this->registryPath = $registryPath;
        $this->togglesFilePath = $togglesFilePath;
    }

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     core: bool,
     *     enabled: bool,
     *     description: string,
     *     stylesheets: list<array{href: string}>,
     *     scripts: list<array{src: string}>,
     *     form_theme: string|null
     * }>
     */
    public function listPackages(): array
    {
        $registry = $this->loadRegistry();
        $rawItems = $registry['items'] ?? [];
        if (!is_array($rawItems)) {
            return [];
        }

        $toggles = $this->loadToggles();
        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $id = isset($rawItem['id']) ? (string) $rawItem['id'] : '';
            if ($id === '' || !$this->isValidId($id)) {
                continue;
            }

            $defaultEnabled = true;
            if (array_key_exists('default_enabled', $rawItem)) {
                $defaultEnabled = (bool) $rawItem['default_enabled'];
            }

            $enabled = $defaultEnabled;
            if (array_key_exists($id, $toggles)) {
                $enabled = (bool) $toggles[$id];
            }

            $core = false;
            if (array_key_exists('core', $rawItem)) {
                $core = (bool) $rawItem['core'];
            }

            $stylesheets = [];
            if (isset($rawItem['stylesheets']) && is_array($rawItem['stylesheets'])) {
                foreach ($rawItem['stylesheets'] as $sheet) {
                    if (!is_array($sheet)) {
                        continue;
                    }
                    $href = isset($sheet['href']) ? (string) $sheet['href'] : '';
                    if ($href !== '') {
                        $stylesheets[] = ['href' => $href];
                    }
                }
            }

            $scripts = [];
            if (isset($rawItem['scripts']) && is_array($rawItem['scripts'])) {
                foreach ($rawItem['scripts'] as $script) {
                    if (!is_array($script)) {
                        continue;
                    }
                    $src = isset($script['src']) ? (string) $script['src'] : '';
                    if ($src !== '') {
                        $scripts[] = ['src' => $src];
                    }
                }
            }

            $formTheme = null;
            if (isset($rawItem['form_theme']) && is_string($rawItem['form_theme']) && $rawItem['form_theme'] !== '') {
                $formTheme = $rawItem['form_theme'];
            }

            $description = '';
            if (isset($rawItem['description']) && is_string($rawItem['description'])) {
                $description = $rawItem['description'];
            }

            $label = $id;
            if (isset($rawItem['label']) && is_string($rawItem['label'])) {
                $label = $rawItem['label'];
            }

            $items[] = [
                'id' => $id,
                'label' => $label,
                'core' => $core,
                'enabled' => $enabled,
                'description' => $description,
                'stylesheets' => $stylesheets,
                'scripts' => $scripts,
                'form_theme' => $formTheme,
            ];
        }

        return $items;
    }

    /**
     * @param list<string> $extraIds
     *
     * @return list<array{
     *     id: string,
     *     stylesheets: list<array{href: string}>,
     *     scripts: list<array{src: string}>
     * }>
     */
    public function resolveActivePackages(array $extraIds): array
    {
        $extraMap = [];
        foreach ($extraIds as $extraId) {
            if (!is_string($extraId)) {
                continue;
            }
            if ($this->isValidId($extraId)) {
                $extraMap[$extraId] = true;
            }
        }

        $active = [];
        foreach ($this->listPackages() as $package) {
            if ($package['core']) {
                continue;
            }
            $use = $package['enabled'];
            if (isset($extraMap[$package['id']])) {
                $use = true;
            }
            if (!$use) {
                continue;
            }
            if ($package['stylesheets'] === [] && $package['scripts'] === []) {
                continue;
            }
            $active[] = [
                'id' => $package['id'],
                'stylesheets' => $package['stylesheets'],
                'scripts' => $package['scripts'],
            ];
        }

        return $active;
    }

    public function findFormThemeForPackage(string $id): ?string
    {
        foreach ($this->listPackages() as $package) {
            if ($package['id'] === $id) {
                return $package['form_theme'];
            }
        }

        return null;
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        if (!$this->isValidId($id)) {
            throw new \InvalidArgumentException('Invalid UI package id.');
        }

        $found = false;
        foreach ($this->listPackages() as $package) {
            if ($package['id'] === $id) {
                if ($package['core']) {
                    throw new \InvalidArgumentException('Core UI package cannot be toggled.');
                }
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new \InvalidArgumentException('Unknown UI package.');
        }

        $toggles = $this->loadToggles();
        $toggles[$id] = $enabled;
        $this->saveToggles($toggles);
        $this->togglesCache = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadRegistry(): array
    {
        if ($this->registryCache !== null) {
            return $this->registryCache;
        }

        if (!is_file($this->registryPath)) {
            $this->registryCache = [];

            return $this->registryCache;
        }

        $parsed = Yaml::parseFile($this->registryPath);
        if (!is_array($parsed)) {
            $this->registryCache = [];

            return $this->registryCache;
        }

        $this->registryCache = $parsed;

        return $this->registryCache;
    }

    /**
     * @return array<string, bool>
     */
    private function loadToggles(): array
    {
        if ($this->togglesCache !== null) {
            return $this->togglesCache;
        }

        if (!is_file($this->togglesFilePath)) {
            $this->togglesCache = [];

            return $this->togglesCache;
        }

        $parsed = Yaml::parseFile($this->togglesFilePath);
        if (!is_array($parsed)) {
            $this->togglesCache = [];

            return $this->togglesCache;
        }

        $toggles = [];
        foreach ($parsed as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $toggles[$key] = (bool) $value;
        }

        $this->togglesCache = $toggles;

        return $this->togglesCache;
    }

    /**
     * @param array<string, bool> $toggles
     */
    private function saveToggles(array $toggles): void
    {
        $dir = dirname($this->togglesFilePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create UI toggles directory.');
            }
        }

        $tmpPath = $this->togglesFilePath . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        $yaml = Yaml::dump($toggles, 3, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        $handle = fopen($tmpPath, 'cb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open temp UI toggles file.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock UI toggles file.');
            }

            ftruncate($handle, 0);
            $written = fwrite($handle, $yaml);
            if ($written === false) {
                throw new \RuntimeException('Cannot write UI toggles file.');
            }

            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (!rename($tmpPath, $this->togglesFilePath)) {
            @unlink($tmpPath);
            throw new \RuntimeException('Cannot save UI toggles file.');
        }
    }

    private function isValidId(string $id): bool
    {
        if ($id === '') {
            return false;
        }

        return preg_match('/^[a-z0-9_-]+$/', $id) === 1;
    }
}
