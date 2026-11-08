<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class WorkspaceModuleRegistry
{
    private string $platformConfigPath;

    private string $projectRoot;

    private string $workspacesDir;

    /** @var array<string, list<array<string, mixed>>> */
    private array $workspaceItemsCache = [];

    public function __construct(string $platformConfigPath, string $projectRoot, string $workspacesDir)
    {
        $this->platformConfigPath = $platformConfigPath;
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->workspacesDir = rtrim($workspacesDir, '/');
    }

    /**
     * @return list<array{slug: string, label: string, module_id: string, implementation: string, active: bool}>
     */
    public function listSidebarModules(string $workspaceSlug): array
    {
        $items = $this->loadMergedItems($workspaceSlug);
        $sidebar = [];
        foreach ($items as $item) {
            if (!$this->isSidebarItem($item)) {
                continue;
            }
            $normalized = $this->normalizeItem($item);
            if ($normalized === null) {
                continue;
            }
            $sidebar[] = $normalized;
        }

        usort($sidebar, function (array $a, array $b): int {
            $sortA = $a['sort'];
            $sortB = $b['sort'];
            if ($sortA === $sortB) {
                return strcmp($a['slug'], $b['slug']);
            }
            if ($sortA < $sortB) {
                return -1;
            }

            return 1;
        });

        $result = [];
        foreach ($sidebar as $row) {
            unset($row['sort']);
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @return array{slug: string, label: string, module_id: string, implementation: string, active: bool}|null
     */
    public function findModule(string $workspaceSlug, string $moduleSlug): ?array
    {
        $moduleSlug = strtolower(trim($moduleSlug));
        if ($moduleSlug === '') {
            return null;
        }

        $items = $this->loadMergedItems($workspaceSlug);
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $slug = $this->itemSlug($item);
            if ($slug !== $moduleSlug) {
                continue;
            }
            if ($this->isDisabled($item)) {
                return null;
            }

            return $this->normalizeItem($item);
        }

        return null;
    }

    public function isKnownModuleSlug(string $workspaceSlug, string $moduleSlug): bool
    {
        return $this->findModule($workspaceSlug, $moduleSlug) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadMergedItems(string $workspaceSlug): array
    {
        if ($workspaceSlug !== '' && isset($this->workspaceItemsCache[$workspaceSlug])) {
            return $this->workspaceItemsCache[$workspaceSlug];
        }

        $merged = [];
        $layerPaths = $this->layerConfigPaths($workspaceSlug);
        foreach ($layerPaths as $path) {
            $items = $this->readItemsFromFile($path);
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $slug = $this->itemSlug($item);
                if ($slug === '') {
                    continue;
                }
                if (isset($merged[$slug])) {
                    $merged[$slug] = $this->mergeItem($merged[$slug], $item);
                } else {
                    $merged[$slug] = $item;
                }
            }
        }

        $list = array_values($merged);
        if ($workspaceSlug !== '') {
            $this->workspaceItemsCache[$workspaceSlug] = $list;
        }

        return $list;
    }

    /**
     * @return list<string>
     */
    private function layerConfigPaths(string $workspaceSlug): array
    {
        $paths = [];

        $packagePattern = $this->projectRoot . '/packages/*/resources/cms/ui/workspace-modules.yaml';
        $packagePaths = glob($packagePattern);
        if (is_array($packagePaths)) {
            sort($packagePaths);
            foreach ($packagePaths as $packagePath) {
                if (is_string($packagePath)) {
                    $paths[] = $packagePath;
                }
            }
        }

        $paths[] = $this->platformConfigPath;

        if ($workspaceSlug !== '') {
            $paths[] = $this->workspacesDir . '/' . $workspaceSlug . '/.agent-cms/modules.yaml';
        }

        return $paths;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readItemsFromFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $parsed = Yaml::parseFile($path);
        if (!is_array($parsed)) {
            return [];
        }

        $items = $parsed['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isSidebarItem(array $item): bool
    {
        if ($this->isDisabled($item)) {
            return false;
        }
        if (isset($item['sidebar']) && $item['sidebar'] === false) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isDisabled(array $item): bool
    {
        if (isset($item['enabled']) && $item['enabled'] === false) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function itemSlug(array $item): string
    {
        if (!isset($item['slug']) || !is_string($item['slug'])) {
            return '';
        }

        return strtolower(trim($item['slug']));
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{slug: string, label: string, module_id: string, implementation: string, active: bool, sort: int}|null
     */
    private function normalizeItem(array $item): ?array
    {
        $slug = $this->itemSlug($item);
        if ($slug === '') {
            return null;
        }

        $label = $slug;
        if (isset($item['label']) && is_string($item['label']) && $item['label'] !== '') {
            $label = $item['label'];
        }

        $moduleId = 'module-' . $slug;
        if (isset($item['module-id']) && is_string($item['module-id']) && $item['module-id'] !== '') {
            $moduleId = $item['module-id'];
        }

        $implementation = 'stub';
        if (isset($item['implementation']) && is_string($item['implementation'])) {
            $implementation = $item['implementation'];
        }

        $active = false;
        if ($implementation === 'active') {
            $active = true;
        }

        $sort = 100;
        if (isset($item['sort'])) {
            $sort = (int) $item['sort'];
        }

        return [
            'slug' => $slug,
            'label' => $label,
            'module_id' => $moduleId,
            'implementation' => $implementation,
            'active' => $active,
            'sort' => $sort,
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function mergeItem(array $base, array $override): array
    {
        $merged = $base;
        foreach ($override as $key => $value) {
            $merged[$key] = $value;
        }

        return $merged;
    }
}
