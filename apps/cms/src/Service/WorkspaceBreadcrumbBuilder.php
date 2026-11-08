<?php

namespace App\Service;

class WorkspaceBreadcrumbBuilder
{
    private TypeSchemaRegistry $typeSchemaRegistry;

    public function __construct(TypeSchemaRegistry $typeSchemaRegistry)
    {
        $this->typeSchemaRegistry = $typeSchemaRegistry;
    }

    /**
     * @param array<string, mixed> $workspace
     *
     * @return list<array{label: string, route: string, route_params: array<string, string>, current: bool}>
     */
    public function buildForModule(array $workspace, string $moduleLabel, string $moduleSlug): array
    {
        $crumbs = $this->buildWorkspaceRootCrumb($workspace, false);

        $slug = '';
        if (isset($workspace['slug']) && is_string($workspace['slug'])) {
            $slug = $workspace['slug'];
        }

        $crumbs[] = [
            'label' => $moduleLabel,
            'route' => 'workspace_module_show',
            'route_params' => [
                'slug' => $slug,
                'module' => $moduleSlug,
            ],
            'current' => true,
        ];

        return $crumbs;
    }

    public function buildForWorkspaceOverview(array $workspace): array
    {
        $slug = '';
        if (isset($workspace['slug']) && is_string($workspace['slug'])) {
            $slug = $workspace['slug'];
        }
        $name = $slug;
        if (isset($workspace['name']) && is_string($workspace['name']) && $workspace['name'] !== '') {
            $name = $workspace['name'];
        }

        return [
            [
                'label' => $name,
                'route' => 'workspace_show',
                'route_params' => ['slug' => $slug],
                'current' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $hubGroup
     *
     * @return list<array{label: string, route: string, route_params: array<string, string>, current: bool}>
     */
    public function buildForHubFamily(array $workspace, array $hubGroup): array
    {
        $crumbs = $this->buildWorkspaceRootCrumb($workspace, false);

        $family = '';
        if (isset($hubGroup['family']) && is_string($hubGroup['family'])) {
            $family = $hubGroup['family'];
        }

        $label = $family;
        if (isset($hubGroup['label']) && is_string($hubGroup['label']) && $hubGroup['label'] !== '') {
            $label = $hubGroup['label'];
        }

        $slug = '';
        if (isset($workspace['slug']) && is_string($workspace['slug'])) {
            $slug = $workspace['slug'];
        }

        $hubIcon = 'folder';
        $hubIconClass = 'text-amber-500';
        if (isset($hubGroup['tree_icon']) && is_string($hubGroup['tree_icon']) && $hubGroup['tree_icon'] !== '') {
            $hubIcon = $hubGroup['tree_icon'];
        }
        if (isset($hubGroup['tree_icon_class']) && is_string($hubGroup['tree_icon_class']) && $hubGroup['tree_icon_class'] !== '') {
            $hubIconClass = $hubGroup['tree_icon_class'];
        }

        $urlZone = $family;
        if (isset($hubGroup['url_segment']) && is_string($hubGroup['url_segment']) && $hubGroup['url_segment'] !== '') {
            $urlZone = $hubGroup['url_segment'];
        }

        $crumbs[] = [
            'label' => $label,
            'route' => 'workspace_hub_family_show',
            'route_params' => [
                'slug' => $slug,
                'zone' => $urlZone,
                'family' => $family,
            ],
            'current' => true,
            'tree_icon' => $hubIcon,
            'tree_icon_class' => $hubIconClass,
        ];

        return $crumbs;
    }

    /**
     * @param array<string, mixed> $workspace
     *
     * @return list<array{label: string, route: string, route_params: array<string, string>, current: bool}>
     */
    public function buildForDiskNode(
        array $workspace,
        string $nodePath,
        string $nodeLabel,
        string $hubFamily,
        string $typeId = ''
    ): array {
        $crumbs = $this->buildWorkspaceRootCrumb($workspace, false);

        $slug = '';
        if (isset($workspace['slug']) && is_string($workspace['slug'])) {
            $slug = $workspace['slug'];
        }

        $familyLabel = $hubFamily;
        if ($hubFamily !== '') {
            $group = $this->typeSchemaRegistry->findHubTreeGroupByFamily($hubFamily, $slug);
            if ($group !== null && isset($group['label']) && is_string($group['label']) && $group['label'] !== '') {
                $familyLabel = $group['label'];
            }

            $hubIcon = 'folder';
            $hubIconClass = 'text-amber-500';
            if ($group !== null) {
                if (isset($group['tree_icon']) && is_string($group['tree_icon']) && $group['tree_icon'] !== '') {
                    $hubIcon = $group['tree_icon'];
                }
                if (isset($group['tree_icon_class']) && is_string($group['tree_icon_class']) && $group['tree_icon_class'] !== '') {
                    $hubIconClass = $group['tree_icon_class'];
                }
            }

            $urlZone = $hubFamily;
            if ($group !== null && isset($group['url_segment']) && is_string($group['url_segment']) && $group['url_segment'] !== '') {
                $urlZone = $group['url_segment'];
            }

            $crumbs[] = [
                'label' => $familyLabel,
                'route' => 'workspace_hub_family_show',
                'route_params' => [
                    'slug' => $slug,
                    'zone' => $urlZone,
                    'family' => $hubFamily,
                ],
                'current' => false,
                'tree_icon' => $hubIcon,
                'tree_icon_class' => $hubIconClass,
            ];
        }

        $nodeUi = $this->typeSchemaRegistry->resolveTreeUiForTypeId($typeId, $slug);

        $crumbs[] = [
            'label' => $nodeLabel,
            'route' => 'workspace_disk_node_show',
            'route_params' => [
                'slug' => $slug,
                'nodePath' => $nodePath !== '' ? $nodePath : '_',
            ],
            'current' => true,
            'tree_icon' => $nodeUi['icon'],
            'tree_icon_class' => $nodeUi['icon_class'],
        ];

        return $crumbs;
    }

    /**
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $hubGroup
     *
     * @return array{reveal_enabled: bool, reveal_relative_path: string}
     */
    public function buildToolbarForHubFamily(array $workspace, array $hubGroup): array
    {
        $folder = '';
        if (isset($hubGroup['folder']) && is_string($hubGroup['folder'])) {
            $folder = trim($hubGroup['folder']);
        }
        $relative = '';
        if ($folder !== '' && $folder !== '.') {
            $relative = $folder;
        }

        return $this->buildToolbar($workspace, $relative);
    }

    /**
     * @param array<string, mixed> $workspace
     *
     * @return array{reveal_enabled: bool, reveal_relative_path: string}
     */
    public function buildToolbarForDiskNode(array $workspace, string $nodePath): array
    {
        $nodePath = trim(str_replace('\\', '/', $nodePath), '/');

        return $this->buildToolbar($workspace, $nodePath);
    }

    /**
     * @param array<string, mixed> $workspace
     *
     * @return array{reveal_enabled: bool, reveal_relative_path: string}
     */
    private function buildToolbar(array $workspace, string $relativePath): array
    {
        $local = false;
        if (isset($workspace['location']) && is_string($workspace['location']) && $workspace['location'] === 'local') {
            $local = true;
        }

        return [
            'reveal_enabled' => $local,
            'reveal_relative_path' => $relativePath,
        ];
    }

    /**
     * @param array<string, mixed> $workspace
     *
     * @return list<array{label: string, route: string, route_params: array<string, string>, current: bool}>
     */
    private function buildWorkspaceRootCrumb(array $workspace, bool $current): array
    {
        $slug = '';
        if (isset($workspace['slug']) && is_string($workspace['slug'])) {
            $slug = $workspace['slug'];
        }
        $name = $slug;
        if (isset($workspace['name']) && is_string($workspace['name']) && $workspace['name'] !== '') {
            $name = $workspace['name'];
        }

        return [
            [
                'label' => $name,
                'route' => 'workspace_show',
                'route_params' => ['slug' => $slug],
                'current' => $current,
                'tree_icon' => 'root',
                'tree_icon_class' => 'text-zinc-500',
            ],
        ];
    }
}
