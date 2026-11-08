<?php

namespace App\Service;

class TypeViewResolver
{
    private TypeSchemaRegistry $typeSchemaRegistry;

    private string $templatesDir;

    public function __construct(TypeSchemaRegistry $typeSchemaRegistry, string $projectDir)
    {
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->templatesDir = rtrim($projectDir, '/') . '/templates';
    }

    /**
     * @return array{template: string, action: string}
     */
    public function resolveDiskNodeView(string $typeId, string $workspaceSlug = ''): array
    {
        $typeId = $this->typeSchemaRegistry->normalizeTypeId($typeId);
        $action = '';
        $template = '';

        $def = null;
        if ($typeId !== '') {
            $def = $this->typeSchemaRegistry->resolveType($typeId, $workspaceSlug);
        }
        if (is_array($def)) {
            $view = $def['view'] ?? null;
            if (is_array($view)) {
                $disk = $view['disk'] ?? null;
                if (is_array($disk)) {
                    if (isset($disk['template']) && is_string($disk['template']) && $disk['template'] !== '') {
                        $template = trim($disk['template']);
                    }
                    if (isset($disk['action']) && is_string($disk['action']) && $disk['action'] !== '') {
                        $action = trim($disk['action']);
                    }
                }
            }
        }

        if ($template === '') {
            $conventional = $this->conventionalDiskTemplate($typeId);
            if ($conventional !== '' && $this->twigTemplateExists($conventional)) {
                $template = $conventional;
            }
        }

        if ($template === '' || !$this->twigTemplateExists($template)) {
            $template = 'workspace/disk_nodes/_default.html.twig';
        }

        return [
            'template' => $template,
            'action' => $action,
        ];
    }

  /**
     * @return array{template: string, action: string}
     */
    public function resolveSchemaNodeView(string $hubPath, string $workspaceSlug = ''): array
    {
        $typeId = 'nodes/' . trim(str_replace('\\', '/', $hubPath), '/');
        $action = '';
        $template = '';

        $def = $this->typeSchemaRegistry->resolveType($typeId, $workspaceSlug);
        if (is_array($def)) {
            $view = $def['view'] ?? null;
            if (is_array($view)) {
                $screen = $view['screen'] ?? null;
                if (is_array($screen)) {
                    if (isset($screen['template']) && is_string($screen['template']) && $screen['template'] !== '') {
                        $template = trim($screen['template']);
                    }
                    if (isset($screen['action']) && is_string($screen['action']) && $screen['action'] !== '') {
                        $action = trim($screen['action']);
                    }
                }
            }
        }

        if ($template === '') {
            $conventional = $this->conventionalSchemaTemplate($hubPath);
            if ($conventional !== '' && $this->twigTemplateExists($conventional)) {
                $template = $conventional;
            }
        }

        if ($template === '' || !$this->twigTemplateExists($template)) {
            $template = 'workspace/hubs/_default.html.twig';
        }

        return [
            'template' => $template,
            'action' => $action,
        ];
    }

    public function resolveFamilySectionTemplate(string $typeId, string $workspaceSlug = ''): string
    {
        $typeId = $this->typeSchemaRegistry->normalizeTypeId($typeId);
        $template = '';

        $def = null;
        if ($typeId !== '') {
            $def = $this->typeSchemaRegistry->resolveType($typeId, $workspaceSlug);
        }
        if (is_array($def)) {
            $view = $def['view'] ?? null;
            if (is_array($view)) {
                $family = $view['family'] ?? null;
                if (is_array($family) && isset($family['template']) && is_string($family['template']) && $family['template'] !== '') {
                    $template = trim($family['template']);
                }
            }
        }

        if ($template === '') {
            $conventional = $this->conventionalFamilyTemplate($typeId);
            if ($conventional !== '' && $this->twigTemplateExists($conventional)) {
                $template = $conventional;
            }
        }

        if ($template === '' || !$this->twigTemplateExists($template)) {
            $template = 'workspace/hubs/family/_type_section.html.twig';
        }

        return $template;
    }

    private function conventionalDiskTemplate(string $typeId): string
    {
        if (!str_starts_with($typeId, 'nodes/')) {
            return '';
        }

        $relative = substr($typeId, strlen('nodes/'));
        if ($relative === '' || str_contains($relative, '..')) {
            return '';
        }

        return 'workspace/disk_nodes/' . $relative . '.html.twig';
    }

    private function conventionalSchemaTemplate(string $hubPath): string
    {
        $hubPath = trim(str_replace('\\', '/', $hubPath), '/');
        if ($hubPath === '' || str_contains($hubPath, '..')) {
            return '';
        }

        return 'workspace/schema_nodes/' . $hubPath . '.html.twig';
    }

    private function conventionalFamilyTemplate(string $typeId): string
    {
        if (!str_starts_with($typeId, 'nodes/')) {
            return '';
        }

        $relative = substr($typeId, strlen('nodes/'));
        if ($relative === '' || str_contains($relative, '..')) {
            return '';
        }

        return 'workspace/hubs/family/' . $relative . '.html.twig';
    }

    private function twigTemplateExists(string $logicalPath): bool
    {
        $logicalPath = trim($logicalPath);
        if ($logicalPath === '') {
            return false;
        }

        $fsPath = $this->templatesDir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $logicalPath);

        return is_file($fsPath);
    }
}
