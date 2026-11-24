<?php

namespace App\Service;

class WorkspaceViewAssembler
{
    private WorkspaceService $workspaceService;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private WorkspaceModuleRegistry $workspaceModuleRegistry;

    private WorkspaceNodeScanner $workspaceNodeScanner;

    public function __construct(
        WorkspaceService $workspaceService,
        TypeSchemaRegistry $typeSchemaRegistry,
        WorkspaceModuleRegistry $workspaceModuleRegistry,
        WorkspaceNodeScanner $workspaceNodeScanner
    ) {
        $this->workspaceService = $workspaceService;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->workspaceModuleRegistry = $workspaceModuleRegistry;
        $this->workspaceNodeScanner = $workspaceNodeScanner;
    }

    /**
     * @param array<string, mixed> $workspace
     *
     * @return array<string, mixed>
     */
    public function buildViewContext(array $workspace): array
    {
        $typeSchema = $this->typeSchemaRegistry->resolveType($workspace['type_id'], $workspace['slug']);
        $typeLabel = $workspace['type_id'];
        $typeKind = '';
        if (is_array($typeSchema)) {
            if (isset($typeSchema['name']) && is_string($typeSchema['name'])) {
                $typeLabel = $typeSchema['name'];
            }
            if (isset($typeSchema['kind']) && is_string($typeSchema['kind'])) {
                $typeKind = $typeSchema['kind'];
            }
        }

        $slug = $workspace['slug'];

        return [
            'workspace' => $workspace,
            'workspaces' => $this->workspaceService->listForDisplay(),
            'workspace_modules' => $this->workspaceModuleRegistry->listSidebarModules($slug),
            'hub_tree' => $this->buildHubTreeForSidebar($slug),
            'type_label' => $typeLabel,
            'type_kind' => $typeKind,
            'active_hub_path' => '',
            'active_hub_family' => '',
            'active_hub_family_page' => false,
            'active_disk_node_path' => '',
            'sidebar_root_note_body' => $this->workspaceService->readRootMarkdownBody($slug, 'NOTE.md'),
            'sidebar_root_todo_body' => $this->workspaceService->readRootMarkdownBody($slug, 'TODO.md'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildHubTreeForSidebar(string $workspaceSlug): array
    {
        $groups = $this->workspaceNodeScanner->attachNestedDiskNodesToHubTree(
            $this->typeSchemaRegistry->listHubTreeGroups($workspaceSlug),
            $workspaceSlug
        );

        foreach ($groups as $index => $group) {
            $family = '';
            if (isset($group['family']) && is_string($group['family'])) {
                $family = trim($group['family']);
            }
            $folderOnly = false;
            if ($family !== '') {
                if (!$this->typeSchemaRegistry->hubAllowsSectionChildren($family, $workspaceSlug)) {
                    $folderOnly = true;
                }
            }
            $groups[$index]['zone_folder_only'] = $folderOnly;
            $groups[$index]['tree_section_children'] = !$folderOnly;
        }

        return $groups;
    }

    public function resolveHubFamilyForDiskPath(string $diskPath, string $workspaceSlug): string
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

        foreach ($this->typeSchemaRegistry->listHubTreeGroups($workspaceSlug) as $group) {
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
