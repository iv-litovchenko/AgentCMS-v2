<?php

namespace App\Service;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class TypeSchemaRegistry
{
    private string $platformTypesDir;

    private string $workspacesDir;

    private string $projectRoot;

    private string $bootstrapTypesDir;

    private string $aliasesFile;

    /** @var array<string, string> */
    private array $idAliases = [];

    /** @var array<string, list<string>> */
    private array $pathToAliases = [];

    private bool $aliasesBootstrapped = false;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $mergedPlatformCache = null;

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $mergedWorkspaceCache = [];

    public function __construct(
        string $platformTypesDir,
        string $workspacesDir,
        string $projectRoot,
        string $bootstrapTypesDir,
        string $aliasesFile
    ) {
        $this->platformTypesDir = $platformTypesDir;
        $this->workspacesDir = $workspacesDir;
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->bootstrapTypesDir = rtrim($bootstrapTypesDir, '/');
        $this->aliasesFile = $aliasesFile;
    }

    public function normalizeTypeId(string $rawId): string
    {
        $rawId = trim($rawId);
        if ($rawId === '') {
            return '';
        }

        $this->ensureAliasesLoaded();
        if (isset($this->idAliases[$rawId])) {
            return $this->idAliases[$rawId];
        }

        if (str_starts_with($rawId, 'acms.')) {
            $fromAcms = $this->canonicalPathFromAcmsPublicId($rawId);
            if ($fromAcms !== '') {
                return $fromAcms;
            }
        }

        if (str_contains($rawId, '/')) {
            return $rawId;
        }

        return $rawId;
    }

    /**
     * Публичный dot-id (acms.*) для manifest и UI; внутренний путь — slash.
     */
    public function publicTypeId(string $rawId): string
    {
        $rawId = trim($rawId);
        if ($rawId === '') {
            return '';
        }

        if (str_starts_with($rawId, 'acms.')) {
            return $rawId;
        }

        $this->ensureAliasesLoaded();
        $canonical = $this->normalizeTypeId($rawId);
        if ($canonical === '') {
            return $rawId;
        }

        if (isset($this->pathToAliases[$canonical]) && is_array($this->pathToAliases[$canonical])) {
            foreach ($this->pathToAliases[$canonical] as $alias) {
                if (str_starts_with($alias, 'acms.')) {
                    return $alias;
                }
            }
        }

        return $this->deriveAcmsPublicId($canonical);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPlatformType(string $typeId): ?array
    {
        return $this->resolveType($typeId, '');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTypeForWorkspace(string $workspaceSlug, string $typeId): ?array
    {
        return $this->resolveType($typeId, $workspaceSlug);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveType(string $typeId, string $workspaceSlug): ?array
    {
        $canonicalId = $this->normalizeTypeId($typeId);
        if ($canonicalId === '') {
            return null;
        }

        $index = $this->loadMergedIndex($workspaceSlug);
        if (!isset($index[$canonicalId])) {
            return null;
        }

        $resolved = $this->resolveExtendsChain($index, $canonicalId, []);
        if ($resolved === null) {
            return null;
        }

        $resolved['id'] = $canonicalId;
        $resolved = $this->enrichDerivedFromPath($resolved, $canonicalId);

        return $resolved;
    }

    /**
     * @return list<string>
     */
    public function listResolvedTypeIds(string $workspaceSlug = ''): array
    {
        $index = $this->loadMergedIndex($workspaceSlug);
        $ids = array_keys($index);
        sort($ids);

        return $ids;
    }

    /**
     * @return list<array{family: string, label: string, folder: string, tree_sort: int, tree_icon: string, tree_icon_class: string, items: list<array<string, mixed>>, type_items: list<array{id: string, hub_path: string, name: string}>}>
     */
    public function listHubTreeGroups(string $workspaceSlug = ''): array
    {
        $ids = $this->listResolvedTypeIds($workspaceSlug);
        /** @var array<string, array{family: string, label: string, items: list<array{id: string, hub_path: string, name: string}>}> $groups */
        $groups = [];

        foreach ($ids as $id) {
            if (!str_starts_with($id, 'hubs/')) {
                continue;
            }
            if ($id === 'hubs/_base') {
                continue;
            }
            $parts = explode('/', $id);
            if (count($parts) !== 2) {
                continue;
            }

            $family = $parts[1];
            $hubDef = $this->resolveType($id, $workspaceSlug);
            if (!is_array($hubDef)) {
                continue;
            }
            $groups[$family] = $this->buildHubTreeGroupShell($family, $hubDef);
        }

        foreach ($ids as $id) {
            if (!str_starts_with($id, 'nodes/')) {
                continue;
            }
            if ($id === 'nodes/_base' || str_ends_with($id, '/_base')) {
                continue;
            }

            $parts = explode('/', $id);
            if (count($parts) < 3) {
                continue;
            }

            $def = $this->resolveType($id, $workspaceSlug);
            if (is_array($def) && isset($def['status']) && is_string($def['status']) && $def['status'] === 'draft') {
                continue;
            }

            $hubKey = '';
            if (is_array($def) && isset($def['hub']) && is_string($def['hub']) && $def['hub'] !== '') {
                $hubKey = $def['hub'];
            }
            if ($hubKey === '') {
                continue;
            }

            if (!isset($groups[$hubKey])) {
                $hubZoneId = 'hubs/' . $hubKey;
                $hubDef = $this->resolveType($hubZoneId, $workspaceSlug);
                if (!is_array($hubDef)) {
                    continue;
                }
                $groups[$hubKey] = $this->buildHubTreeGroupShell($hubKey, $hubDef);
            }

            $nodePath = implode('/', array_slice($parts, 1));
            $name = $nodePath;
            if (is_array($def)) {
                if (isset($def['name']) && is_string($def['name']) && $def['name'] !== '') {
                    $name = $def['name'];
                } elseif (isset($def['label']) && is_string($def['label']) && $def['label'] !== '') {
                    $name = $def['label'];
                }
            }

            if (!isset($groups[$hubKey]['type_items'])) {
                $groups[$hubKey]['type_items'] = [];
            }

            $sort = 100;
            if (is_array($def) && isset($def['sort'])) {
                $sort = (int) $def['sort'];
            }

            $groups[$hubKey]['type_items'][] = [
                'id' => $id,
                'hub_path' => $nodePath,
                'name' => $name,
                'sort' => $sort,
            ];
        }

        $result = array_values($groups);
        foreach ($result as $index => $group) {
            usort($group['items'], function (array $a, array $b): int {
                return strcmp($a['hub_path'], $b['hub_path']);
            });
            $result[$index]['items'] = $group['items'];
            if (isset($group['type_items']) && is_array($group['type_items'])) {
                $typeItems = $group['type_items'];
                usort($typeItems, function (array $a, array $b): int {
                    $sortA = $a['sort'] ?? 100;
                    $sortB = $b['sort'] ?? 100;
                    if ($sortA === $sortB) {
                        return strcmp($a['hub_path'], $b['hub_path']);
                    }
                    if ($sortA < $sortB) {
                        return -1;
                    }

                    return 1;
                });
                $result[$index]['type_items'] = $typeItems;
            }
        }

        usort($result, function (array $a, array $b): int {
            $orderA = $a['tree_sort'] ?? 100;
            $orderB = $b['tree_sort'] ?? 100;
            if ($orderA === $orderB) {
                return strcmp($a['family'], $b['family']);
            }
            if ($orderA < $orderB) {
                return -1;
            }

            return 1;
        });

        return $result;
    }

    /**
     * Служебные .md в корне workspace (AGENTS.md и т.д.) — типы nodes/root/* с manifest-pattern.
     *
     * @return list<array{file: string, preset: string, label: string, type_id: string, sort: int}>
     */
    public function listRootDiskManifestTypes(string $workspaceSlug = ''): array
    {
        $result = [];
        foreach ($this->listResolvedTypeIds($workspaceSlug) as $id) {
            if (!str_starts_with($id, 'nodes/root/')) {
                continue;
            }
            if ($id === 'nodes/root/_file' || $id === 'nodes/root/ws' || $id === 'nodes/root/root') {
                continue;
            }

            $def = $this->resolveType($id, $workspaceSlug);
            if (!is_array($def)) {
                continue;
            }
            if (isset($def['status']) && is_string($def['status']) && $def['status'] === 'draft') {
                continue;
            }

            $pattern = '';
            if (isset($def['manifest-pattern']) && is_string($def['manifest-pattern'])) {
                $pattern = trim($def['manifest-pattern']);
            }
            if ($pattern === '' || $pattern === 'manifest.node.md' || str_contains($pattern, '/')) {
                continue;
            }

            $preset = '';
            if (isset($def['create-preset']) && is_string($def['create-preset'])) {
                $preset = trim($def['create-preset']);
            }

            $label = $pattern;
            if (isset($def['name']) && is_string($def['name']) && $def['name'] !== '') {
                $label = $def['name'];
            }

            $sort = 100;
            if (isset($def['sort'])) {
                $sort = (int) $def['sort'];
            }

            $result[] = [
                'file' => $pattern,
                'preset' => $preset,
                'label' => $label,
                'type_id' => $id,
                'sort' => $sort,
            ];
        }

        usort($result, function (array $a, array $b): int {
            if ($a['sort'] === $b['sort']) {
                return strcmp($a['file'], $b['file']);
            }
            if ($a['sort'] < $b['sort']) {
                return -1;
            }

            return 1;
        });

        return $result;
    }

    /**
     * @return array{family: string, label: string, folder: string, tree_sort: int, tree_icon: string, tree_icon_class: string, items: list<array{id: string, hub_path: string, name: string}>}|null
     */
    public function resolveDefaultNodeTypeIdForHub(string $hubFamily, string $workspaceSlug = ''): string
    {
        $hubFamily = trim($hubFamily);
        $fallback = '';
        $ids = $this->listResolvedTypeIds($workspaceSlug);
        foreach ($ids as $id) {
            if (!str_starts_with($id, 'nodes/')) {
                continue;
            }
            if ($id === 'nodes/_base' || str_ends_with($id, '/_base')) {
                continue;
            }

            $def = $this->resolveType($id, $workspaceSlug);
            if (!is_array($def)) {
                continue;
            }

            if (isset($def['status']) && is_string($def['status']) && $def['status'] === 'draft') {
                continue;
            }

            $hub = '';
            if (isset($def['hub']) && is_string($def['hub'])) {
                $hub = $def['hub'];
            }
            if ($hub !== $hubFamily) {
                continue;
            }

            if ($fallback === '') {
                $fallback = $id;
            }

            if (isset($def['default']) && $def['default'] === true) {
                return $id;
            }
        }

        return $fallback;
    }

    public function findHubTreeGroupByFamily(string $family, string $workspaceSlug = ''): ?array
    {
        $family = trim($family);
        if ($family === '') {
            return null;
        }

        foreach ($this->listHubTreeGroups($workspaceSlug) as $group) {
            if ($group['family'] === $family) {
                return $group;
            }
        }

        $hubZoneId = 'hubs/' . $family;
        $hubZone = $this->resolveType($hubZoneId, $workspaceSlug);
        if ($hubZone === null) {
            return null;
        }

        return $this->buildHubTreeGroupShell($family, $hubZone);
    }

    /**
     * @param array<string, mixed> $familyBase
     *
     * @return array{family: string, label: string, folder: string, tree_sort: int, tree_icon: string, tree_icon_class: string, items: list<array{id: string, hub_path: string, name: string}>}
     */
    private function buildHubTreeGroupShell(string $family, array $familyBase): array
    {
        $folder = '';
        if (isset($familyBase['folder']) && is_string($familyBase['folder'])) {
            $folder = $familyBase['folder'];
        }

        $treeUi = $this->resolveHubTreeUi($familyBase);

        $groupLabel = $treeUi['label'];
        if ($groupLabel === '') {
            $groupLabel = $family;
        }

        $urlSegment = $family;
        if ($folder !== '' && $folder !== '.') {
            $urlSegment = $folder;
        }

        return [
            'family' => $family,
            'label' => $groupLabel,
            'folder' => $folder,
            'url_segment' => $urlSegment,
            'tree_sort' => $treeUi['sort'],
            'tree_icon' => $treeUi['icon'],
            'tree_icon_class' => $treeUi['icon_class'],
            'items' => [],
            'type_items' => [],
        ];
    }

    public function hubUrlSegmentForFamily(string $family, string $workspaceSlug = ''): string
    {
        $family = trim($family);
        if ($family === '') {
            return '';
        }

        $group = $this->findHubTreeGroupByFamily($family, $workspaceSlug);
        if ($group !== null && isset($group['url_segment']) && is_string($group['url_segment']) && $group['url_segment'] !== '') {
            return $group['url_segment'];
        }

        $hubZone = $this->resolveType('hubs/' . $family, $workspaceSlug);
        if (!is_array($hubZone)) {
            return $family;
        }

        $folder = '';
        if (isset($hubZone['folder']) && is_string($hubZone['folder'])) {
            $folder = trim($hubZone['folder']);
        }
        if ($folder !== '' && $folder !== '.') {
            return $folder;
        }

        return $family;
    }

    public function hubFamilyForUrlSegment(string $segment, string $workspaceSlug = ''): ?string
    {
        $segment = trim($segment);
        if ($segment === '') {
            return null;
        }

        foreach ($this->listHubTreeGroups($workspaceSlug) as $group) {
            if (!isset($group['family']) || !is_string($group['family'])) {
                continue;
            }
            $urlSegment = $group['family'];
            if (isset($group['url_segment']) && is_string($group['url_segment']) && $group['url_segment'] !== '') {
                $urlSegment = $group['url_segment'];
            }
            if ($urlSegment === $segment) {
                return $group['family'];
            }
            if ($group['family'] === $segment) {
                return $group['family'];
            }
        }

        return null;
    }

    /**
     * @return array{icon: string, icon_class: string}
     */
    public function resolveTreeUiForTypeId(string $typeId, string $workspaceSlug = ''): array
    {
        $canonicalId = '';
        if ($typeId !== '') {
            $canonicalId = $this->normalizeTypeId($typeId);
        }

        $def = null;
        if ($canonicalId !== '') {
            $def = $this->resolveType($canonicalId, $workspaceSlug);
        }
        if ($def === null && $typeId !== '' && $typeId !== $canonicalId) {
            $def = $this->resolveType($typeId, $workspaceSlug);
        }
        if ($def === null) {
            return [
                'icon' => 'folder',
                'icon_class' => 'text-zinc-400',
            ];
        }

        $ui = $this->resolveHubTreeUi($def);
        $icon = 'folder';
        $iconClass = 'text-zinc-400';
        if (isset($ui['icon']) && is_string($ui['icon'])) {
            $icon = $ui['icon'];
        }
        if (isset($ui['icon_class']) && is_string($ui['icon_class'])) {
            $iconClass = $ui['icon_class'];
        }

        if ($this->typeKindUiFor($def) === 'group') {
            if ($icon === 'folder' || $icon === '') {
                $icon = 'group';
            }
        }

        return [
            'icon' => $icon,
            'icon_class' => $iconClass,
        ];
    }

    /**
     * Каталоги 0-storage/ по storage-slots в YAML типа (создаются вручную в UI).
     *
     * @return list<array{folder: string, name: string, category: string, icon: string, icon_class: string, single_file: bool, relative_path: string, display_path: string, accept_files: list<string>, accept_files_label: string}>
     */
    public function listStorageSlotsForNodeType(string $typeId, string $workspaceSlug = ''): array
    {
        if (trim($typeId) === '') {
            return [];
        }

        $nodeType = $this->getTypeForWorkspace($workspaceSlug, $typeId);
        if ($nodeType === null) {
            return [];
        }

        $slots = $nodeType['storage-slots'] ?? null;
        if (!is_array($slots) || count($slots) === 0) {
            return [];
        }

        $items = [];
        foreach ($slots as $slot) {
            if (!is_string($slot)) {
                continue;
            }
            $folder = trim(str_replace('\\', '/', $slot), '/');
            if ($folder === '' || str_contains($folder, '..')) {
                continue;
            }

            $slotDef = $this->resolveStorageSlotTypeDefinition($folder, $workspaceSlug);
            $name = $folder;
            $category = '';
            if ($slotDef !== null) {
                if (isset($slotDef['name']) && is_string($slotDef['name']) && $slotDef['name'] !== '') {
                    $name = $slotDef['name'];
                }
                if (isset($slotDef['slot-category']) && is_string($slotDef['slot-category'])) {
                    $category = $slotDef['slot-category'];
                }
            }

            $treeUi = $this->resolveStorageSlotTreeUi($folder, $slotDef);
            $diskPath = $this->resolveStorageSlotDiskPath($folder, $slotDef);
            $acceptFiles = $this->resolveSlotAcceptFiles($slotDef);

            $items[] = [
                'folder' => $folder,
                'name' => $name,
                'category' => $category,
                'icon' => $treeUi['icon'],
                'icon_class' => $treeUi['icon_class'],
                'single_file' => $diskPath['single_file'],
                'relative_path' => $diskPath['relative_path'],
                'display_path' => $diskPath['display_path'],
                'accept_files' => $acceptFiles,
                'accept_files_label' => $this->formatSlotAcceptFilesLabel($acceptFiles),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed>|null $slotDef
     *
     * @return list<string>
     */
    private function resolveSlotAcceptFiles(?array $slotDef): array
    {
        if ($slotDef === null) {
            return [];
        }

        $raw = $slotDef['accept-files'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $acceptFiles = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $acceptFiles[] = trim($item);
            }
        }

        return $acceptFiles;
    }

    /**
     * @param list<string> $acceptFiles
     */
    private function formatSlotAcceptFilesLabel(array $acceptFiles): string
    {
        if (count($acceptFiles) === 0) {
            return '';
        }

        foreach ($acceptFiles as $item) {
            if ($item === '*') {
                return 'Любые расширения';
            }
        }

        return implode(', ', $acceptFiles);
    }

    /**
     * @param array<string, mixed>|null $slotDef
     *
     * @return array{single_file: bool, relative_path: string, display_path: string}
     */
    private function resolveStorageSlotDiskPath(string $folder, ?array $slotDef): array
    {
        $singleFile = false;
        $relativePath = '';
        $displayPath = '';

        if ($slotDef !== null) {
            $category = '';
            if (isset($slotDef['slot-category']) && is_string($slotDef['slot-category'])) {
                $category = $slotDef['slot-category'];
            }
            if ($category === 'single-file') {
                $singleFile = true;
            }

            $driver = '';
            if (isset($slotDef['storage-driver']) && is_string($slotDef['storage-driver'])) {
                $driver = $slotDef['storage-driver'];
            }
            if ($driver === 'internal' || $driver === 'tabular') {
                $singleFile = true;
            }
            if ($driver === 'external') {
                $singleFile = false;
            }

            if (isset($slotDef['path']) && is_string($slotDef['path'])) {
                $path = trim(str_replace('\\', '/', $slotDef['path']));
                if ($singleFile) {
                    $relativePath = ltrim($path, '/');
                    $displayPath = $relativePath;
                } else {
                    $path = trim($path, '/');
                    if ($path !== '') {
                        $displayPath = WorkspaceNodeStorage::canonicalPrefix() . rtrim($path, '/') . '/';
                        $relativePath = $displayPath;
                    }
                }
            }
        }

        if (!$singleFile) {
            if ($displayPath === '') {
                $displayPath = WorkspaceNodeStorage::canonicalPrefix() . $folder . '/';
            }
            if ($relativePath === '') {
                $relativePath = $displayPath;
            }
        } elseif ($relativePath === '') {
            $relativePath = $folder;
            $displayPath = $relativePath;
        }

        return [
            'single_file' => $singleFile,
            'relative_path' => $relativePath,
            'display_path' => $displayPath,
        ];
    }

    /**
     * @param array<string, mixed>|null $slotDef
     *
     * @return array{icon: string, icon_class: string}
     */
    private function resolveStorageSlotTreeUi(string $folder, ?array $slotDef): array
    {
        $icon = 'folder';
        $iconClass = 'text-zinc-500';

        if ($slotDef !== null) {
            $treeUi = $slotDef['tree-ui'] ?? null;
            if (is_array($treeUi)) {
                if (isset($treeUi['icon']) && is_string($treeUi['icon']) && $treeUi['icon'] !== '') {
                    $icon = $treeUi['icon'];
                }
                if (isset($treeUi['icon-class']) && is_string($treeUi['icon-class']) && $treeUi['icon-class'] !== '') {
                    $iconClass = $treeUi['icon-class'];
                }
                if ($icon !== 'folder' || $iconClass !== 'text-zinc-500') {
                    return [
                        'icon' => $icon,
                        'icon_class' => $iconClass,
                    ];
                }
            }
        }

        if ($folder === 'main' || $folder === 'main-md' || $folder === 'main-single') {
            $icon = 'file';
            $iconClass = 'text-emerald-600';
        } elseif ($folder === 'main-csv' || $folder === 'main-single-csv') {
            $icon = 'table';
            $iconClass = 'text-emerald-600';
        } elseif ($folder === 'inbox') {
            $icon = 'inbox';
            $iconClass = 'text-sky-600';
        } elseif ($folder === 'outbox') {
            $icon = 'outbox';
            $iconClass = 'text-indigo-600';
        } elseif ($folder === 'task-queue') {
            $icon = 'queue';
            $iconClass = 'text-violet-600';
        } elseif ($folder === 'notes' || $folder === 'note') {
            $icon = 'file';
            $iconClass = 'text-zinc-500';
        } elseif ($folder === 'references') {
            $icon = 'bookmark';
            $iconClass = 'text-amber-600';
        } elseif ($folder === 'artefacts') {
            $icon = 'archive';
            $iconClass = 'text-orange-600';
        } elseif ($folder === 'assets' || $folder === 'media') {
            $icon = 'image';
            $iconClass = 'text-sky-500';
        } elseif ($folder === 'repository') {
            $icon = 'code';
            $iconClass = 'text-orange-600';
        } elseif ($folder === 'scripts') {
            $icon = 'code';
            $iconClass = 'text-zinc-600';
        } elseif ($folder === 'templates') {
            $icon = 'template';
            $iconClass = 'text-fuchsia-600';
        } elseif ($folder === 'base') {
            $icon = 'database';
            $iconClass = 'text-teal-600';
        } elseif ($folder === 'notebooklm') {
            $icon = 'book';
            $iconClass = 'text-rose-600';
        } elseif ($folder === 'todo' || $folder === 'todo-single') {
            $icon = 'check';
            $iconClass = 'text-lime-600';
        } elseif ($folder === 'atlas') {
            $icon = 'map';
            $iconClass = 'text-cyan-600';
        } elseif ($folder === 'files') {
            $icon = 'folder';
            $iconClass = 'text-amber-500';
        } elseif ($folder === 'flexible') {
            $icon = 'section';
            $iconClass = 'text-zinc-400';
        } elseif ($folder === 'comments') {
            $icon = 'channel';
            $iconClass = 'text-zinc-500';
        } elseif ($folder === 'discussion') {
            $icon = 'channel';
            $iconClass = 'text-zinc-500';
        } elseif ($folder === 'history') {
            $icon = 'queue';
            $iconClass = 'text-zinc-400';
        }

        return [
            'icon' => $icon,
            'icon_class' => $iconClass,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveStorageSlotTypeDefinition(string $folder, string $workspaceSlug): ?array
    {
        $folderKeys = [$folder];
        if ($folder === 'notes') {
            $folderKeys[] = 'note';
        }

        foreach ($folderKeys as $key) {
            $candidates = [
                'awn.slot.' . $key,
                'slots/multi-file/' . $key,
                'slots/single-file/' . $key,
                'slots/multi-file/system/' . $key,
            ];
            foreach ($candidates as $candidate) {
                $def = $this->resolveType($candidate, $workspaceSlug);
                if ($def !== null) {
                    return $def;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $typeDef
     */
    private function typeKindUiFor(array $typeDef): string
    {
        $kindUi = $typeDef['kind-ui'] ?? null;
        if (!is_array($kindUi)) {
            return '';
        }
        if (!isset($kindUi['for']) || !is_string($kindUi['for'])) {
            return '';
        }

        return trim($kindUi['for']);
    }

    /**
     * @param array<string, mixed>|null $familyBase
     *
     * @return array{label: string, sort: int, icon: string, icon_class: string}
     */
    private function resolveHubTreeUi(?array $familyBase): array
    {
        $label = '';
        $sort = 100;
        $icon = 'folder';
        $iconClass = 'text-amber-500';

        if ($familyBase === null) {
            return [
                'label' => $label,
                'sort' => $sort,
                'icon' => $icon,
                'icon_class' => $iconClass,
            ];
        }

        $treeUi = $familyBase['tree-ui'] ?? null;
        if (is_array($treeUi)) {
            if (isset($treeUi['label']) && is_string($treeUi['label']) && $treeUi['label'] !== '') {
                $label = $treeUi['label'];
            }
            if (isset($treeUi['sort']) && is_int($treeUi['sort'])) {
                $sort = $treeUi['sort'];
            } elseif (isset($treeUi['sort']) && is_string($treeUi['sort']) && $treeUi['sort'] !== '' && ctype_digit($treeUi['sort'])) {
                $sort = (int) $treeUi['sort'];
            }
            if (isset($treeUi['icon']) && is_string($treeUi['icon']) && $treeUi['icon'] !== '') {
                $icon = $treeUi['icon'];
            }
            if (isset($treeUi['icon-class']) && is_string($treeUi['icon-class']) && $treeUi['icon-class'] !== '') {
                $iconClass = $treeUi['icon-class'];
            }
        }

        if ($label === '' && isset($familyBase['name']) && is_string($familyBase['name'])) {
            $label = $familyBase['name'];
        }

        return [
            'label' => $label,
            'sort' => $sort,
            'icon' => $icon,
            'icon_class' => $iconClass,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function listPlatformTypeIds(): array
    {
        return $this->loadMergedIndex('');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadMergedIndex(string $workspaceSlug): array
    {
        if ($workspaceSlug !== '') {
            if (isset($this->mergedWorkspaceCache[$workspaceSlug])) {
                return $this->mergedWorkspaceCache[$workspaceSlug];
            }
        } elseif ($this->mergedPlatformCache !== null) {
            return $this->mergedPlatformCache;
        }

        $merged = [];
        $layerDirs = $this->buildLayerDirectories($workspaceSlug);
        foreach ($layerDirs as $dir) {
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }
            $scanned = $this->scanTypesDirectory($dir);
            foreach ($scanned as $typeId => $definition) {
                if (isset($merged[$typeId])) {
                    $merged[$typeId] = $this->mergeTypeDefinitions($merged[$typeId], $definition);
                } else {
                    $merged[$typeId] = $definition;
                }
            }
        }

        if ($workspaceSlug !== '') {
            $this->mergedWorkspaceCache[$workspaceSlug] = $merged;
        } else {
            $this->mergedPlatformCache = $merged;
        }

        return $merged;
    }

    /**
     * @return list<string>
     */
    private function buildLayerDirectories(string $workspaceSlug): array
    {
        $dirs = [];

        if ($this->bootstrapTypesDir !== '' && is_dir($this->bootstrapTypesDir)) {
            $dirs[] = $this->bootstrapTypesDir;
        }

        $packagePattern = $this->projectRoot . '/packages/*/resources/cms/types';
        $packageDirs = glob($packagePattern);
        if (is_array($packageDirs)) {
            sort($packageDirs);
            foreach ($packageDirs as $packageDir) {
                if (is_string($packageDir) && is_dir($packageDir)) {
                    $dirs[] = $packageDir;
                }
            }
        }

        $dirs[] = $this->platformTypesDir;

        if ($workspaceSlug !== '') {
            $workspaceTypes = rtrim($this->workspacesDir, '/') . '/' . $workspaceSlug . '/types';
            $dirs[] = $workspaceTypes;
        }

        return $dirs;
    }

    /**
     * @param array<string, array<string, mixed>> $index
     * @param list<string> $stack
     *
     * @return array<string, mixed>|null
     */
    private function resolveExtendsChain(array $index, string $typeId, array $stack): ?array
    {
        if (in_array($typeId, $stack, true)) {
            return null;
        }

        if (!isset($index[$typeId])) {
            return null;
        }

        $definition = $index[$typeId];
        $extends = null;
        if (isset($definition['extends']) && is_string($definition['extends'])) {
            $extends = $this->normalizeTypeId($definition['extends']);
        }

        if ($extends === null || $extends === '') {
            return $definition;
        }

        $parent = $this->resolveExtendsChain($index, $extends, array_merge($stack, [$typeId]));
        if ($parent === null) {
            return $definition;
        }

        unset($definition['extends']);

        return $this->mergeTypeDefinitions($parent, $definition);
    }

    private function ensureAliasesLoaded(): void
    {
        if ($this->aliasesBootstrapped) {
            return;
        }

        $this->aliasesBootstrapped = true;

        if (!is_file($this->aliasesFile)) {
            return;
        }

        $parsed = Yaml::parseFile($this->aliasesFile);
        if (!is_array($parsed)) {
            return;
        }

        foreach ($parsed as $legacy => $path) {
            if (!is_string($legacy) || !is_string($path)) {
                continue;
            }
            $legacy = trim($legacy);
            $path = trim($path);
            if ($legacy === '' || $path === '') {
                continue;
            }
            $this->idAliases[$legacy] = $path;
            if (!isset($this->pathToAliases[$path])) {
                $this->pathToAliases[$path] = [];
            }
            $this->pathToAliases[$path][] = $legacy;
        }
    }

    private function deriveAcmsPublicId(string $canonicalId): string
    {
        $canonicalId = trim($canonicalId);
        if ($canonicalId === '') {
            return '';
        }

        if (str_starts_with($canonicalId, 'nodes/root/')) {
            $leaf = substr($canonicalId, strlen('nodes/root/'));

            return 'acms.root.' . $leaf;
        }

        if (str_starts_with($canonicalId, 'nodes/')) {
            $rest = substr($canonicalId, strlen('nodes/'));
            $parts = explode('/', $rest);
            if (count($parts) >= 2 && $parts[count($parts) - 1] === $parts[count($parts) - 2]) {
                array_pop($parts);
            }

            return 'acms.node.' . implode('.', $parts);
        }

        if (str_starts_with($canonicalId, 'hubs/')) {
            $rest = substr($canonicalId, strlen('hubs/'));

            return 'acms.hub.' . str_replace('/', '.', $rest);
        }

        return 'acms.' . str_replace('/', '.', $canonicalId);
    }

    private function canonicalPathFromAcmsPublicId(string $publicId): string
    {
        $publicId = trim($publicId);
        if ($publicId === '' || !str_starts_with($publicId, 'acms.')) {
            return '';
        }

        if (str_starts_with($publicId, 'acms.root.')) {
            $leaf = substr($publicId, strlen('acms.root.'));
            if ($leaf === '') {
                return '';
            }

            return 'nodes/root/' . $leaf;
        }

        if (str_starts_with($publicId, 'acms.hub.')) {
            $rest = substr($publicId, strlen('acms.hub.'));
            if ($rest === '') {
                return '';
            }

            return 'hubs/' . str_replace('.', '/', $rest);
        }

        if (str_starts_with($publicId, 'acms.node.')) {
            $rest = substr($publicId, strlen('acms.node.'));
            if ($rest === '') {
                return '';
            }
            $parts = explode('.', $rest);
            if (count($parts) === 1) {
                return 'nodes/' . $parts[0] . '/' . $parts[0];
            }

            return 'nodes/' . implode('/', $parts);
        }

        $stripped = substr($publicId, strlen('acms.'));
        if ($stripped === '') {
            return '';
        }

        return str_replace('.', '/', $stripped);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function scanTypesDirectory(string $rootDir): array
    {
        $types = [];
        if (!is_dir($rootDir)) {
            return $types;
        }

        $finder = new Finder();
        $finder->files()->in($rootDir)->ignoreDotFiles(true)->name('/\.(ya?ml)$/');

        foreach ($finder as $file) {
            $basename = $file->getFilename();
            if ($basename === 'aliases.yaml' || $basename === 'aliases.yml') {
                continue;
            }

            $relativePath = $file->getRelativePathname();
            $typeId = $this->typeIdFromRelativePath($relativePath);
            try {
                $parsed = Yaml::parseFile($file->getRealPath());
            } catch (ParseException $exception) {
                continue;
            }
            if (!is_array($parsed)) {
                continue;
            }

            unset($parsed['id']);

            $types[$typeId] = $parsed;
        }

        ksort($types);

        return $types;
    }

    private function typeIdFromRelativePath(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        if (preg_match('/\.(yaml|yml)$/i', $normalized)) {
            $normalized = preg_replace('/\.(yaml|yml)$/i', '', $normalized);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function mergeTypeDefinitions(array $base, array $override): array
    {
        $merged = $base;
        foreach ($override as $key => $value) {
            if ($key === 'extends') {
                continue;
            }
            if ($key === 'properties' && is_array($value) && isset($merged['properties']) && is_array($merged['properties'])) {
                $merged['properties'] = $this->mergePropertyLists($merged['properties'], $value);
                continue;
            }
            if ($key === 'fields' && is_array($value) && isset($merged['fields']) && is_array($merged['fields'])) {
                $merged['fields'] = array_merge($merged['fields'], $value);
                continue;
            }
            if ($key === 'mixins' && is_array($value) && isset($merged['mixins']) && is_array($merged['mixins'])) {
                $merged['mixins'] = array_values(array_unique(array_merge($merged['mixins'], $value)));
                continue;
            }
            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * @param list<array<string, mixed>> $base
     * @param list<array<string, mixed>> $override
     *
     * @return list<array<string, mixed>>
     */
    private function mergePropertyLists(array $base, array $override): array
    {
        $byKey = [];
        foreach ($base as $item) {
            if (!is_array($item)) {
                continue;
            }
            $propKey = $item['key'] ?? null;
            if (is_string($propKey) && $propKey !== '') {
                $byKey[$propKey] = $item;
            }
        }
        foreach ($override as $item) {
            if (!is_array($item)) {
                continue;
            }
            $propKey = $item['key'] ?? null;
            if (is_string($propKey) && $propKey !== '') {
                if (isset($byKey[$propKey]) && is_array($byKey[$propKey])) {
                    $byKey[$propKey] = array_merge($byKey[$propKey], $item);
                } else {
                    $byKey[$propKey] = $item;
                }
            }
        }

        return array_values($byKey);
    }

    /**
     * @param array<string, mixed> $resolved
     *
     * @return array<string, mixed>
     */
    private function enrichDerivedFromPath(array $resolved, string $typeId): array
    {
        if (!isset($resolved['kind']) || !is_string($resolved['kind']) || $resolved['kind'] === '') {
            $kind = $this->deriveKindFromTypeId($typeId);
            if ($kind !== '') {
                $resolved['kind'] = $kind;
            }
        }

        return $resolved;
    }

    private function deriveKindFromTypeId(string $typeId): string
    {
        if (str_ends_with($typeId, '/_base')) {
            return 'base';
        }

        $parts = explode('/', $typeId);
        if (count($parts) === 0) {
            return '';
        }

        $root = $parts[0];
        if ($root === 'hubs') {
            return 'hub';
        }
        if ($root === 'nodes') {
            return 'node';
        }
        if ($root === 'slots') {
            return 'slot';
        }
        if ($root === 'fields') {
            return 'field';
        }
        if ($root === 'content') {
            return 'record';
        }
        if ($root === 'presets') {
            return 'preset';
        }
        if ($root === 'md-blocks') {
            return 'block';
        }
        if ($root === 'mixins') {
            return 'mixin';
        }
        if ($root === 'settings') {
            return 'settings';
        }
        if ($root === 'modules') {
            return 'module';
        }
        if ($root === 'annotations') {
            return 'annotation';
        }
        if ($root === 'viz') {
            return 'viz';
        }
        if ($root === 'base') {
            return 'base';
        }

        return '';
    }
}
