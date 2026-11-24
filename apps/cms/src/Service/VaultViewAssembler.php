<?php

namespace App\Service;

class VaultViewAssembler
{
    private VaultService $vaultService;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private VaultModuleRegistry $vaultModuleRegistry;

    private VaultNodeScanner $vaultNodeScanner;

    public function __construct(
        VaultService $vaultService,
        TypeSchemaRegistry $typeSchemaRegistry,
        VaultModuleRegistry $vaultModuleRegistry,
        VaultNodeScanner $vaultNodeScanner
    ) {
        $this->vaultService = $vaultService;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->vaultModuleRegistry = $vaultModuleRegistry;
        $this->vaultNodeScanner = $vaultNodeScanner;
    }

    /**
     * @param array<string, mixed> $vault
     *
     * @return array<string, mixed>
     */
    public function buildViewContext(array $vault): array
    {
        $typeSchema = $this->typeSchemaRegistry->resolveType($vault['type_id'], $vault['slug']);
        $typeLabel = $vault['type_id'];
        $typeKind = '';
        if (is_array($typeSchema)) {
            if (isset($typeSchema['name']) && is_string($typeSchema['name'])) {
                $typeLabel = $typeSchema['name'];
            }
            if (isset($typeSchema['kind']) && is_string($typeSchema['kind'])) {
                $typeKind = $typeSchema['kind'];
            }
        }

        $slug = $vault['slug'];

        return [
            'vault' => $vault,
            'vaults' => $this->vaultService->listForDisplay(),
            'vault_modules' => $this->vaultModuleRegistry->listSidebarModules($slug),
            'hub_tree' => $this->buildHubTreeForSidebar($slug),
            'type_label' => $typeLabel,
            'type_kind' => $typeKind,
            'active_hub_path' => '',
            'active_hub_family' => '',
            'active_hub_family_page' => false,
            'active_disk_node_path' => '',
            'sidebar_root_note_body' => $this->vaultService->readRootMarkdownBody($slug, 'NOTE.md'),
            'sidebar_root_todo_body' => $this->vaultService->readRootMarkdownBody($slug, 'TODO.md'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildHubTreeForSidebar(string $vaultSlug): array
    {
        $groups = $this->vaultNodeScanner->attachNestedDiskNodesToHubTree(
            $this->typeSchemaRegistry->listHubTreeGroups($vaultSlug),
            $vaultSlug
        );

        foreach ($groups as $index => $group) {
            $family = '';
            if (isset($group['family']) && is_string($group['family'])) {
                $family = trim($group['family']);
            }
            $folderOnly = false;
            if ($family !== '') {
                if (!$this->typeSchemaRegistry->hubAllowsSectionChildren($family, $vaultSlug)) {
                    $folderOnly = true;
                }
            }
            $groups[$index]['zone_folder_only'] = $folderOnly;
            $groups[$index]['tree_section_children'] = !$folderOnly;
        }

        return $groups;
    }

    public function resolveHubFamilyForDiskPath(string $diskPath, string $vaultSlug): string
    {
        $diskPath = trim(str_replace('\\', '/', $diskPath), '/');
        $folderPrefix = $diskPath;
        $slashPos = strpos($diskPath, '/');
        if ($slashPos !== false) {
            $folderPrefix = substr($diskPath, 0, $slashPos);
        }
        if ($diskPath === '') {
            $folderPrefix = '.';
        }

        foreach ($this->typeSchemaRegistry->listHubTreeGroups($vaultSlug) as $group) {
            $folder = '';
            if (isset($group['folder']) && is_string($group['folder'])) {
                $folder = $group['folder'];
            }
            if ($folder === $folderPrefix) {
                return $group['family'];
            }
        }

        return '';
    }
}
