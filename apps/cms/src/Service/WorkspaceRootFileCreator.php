<?php

namespace App\Service;

class WorkspaceRootFileCreator
{
    private WorkspaceService $workspaceService;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private NodeDiskManifestRegistry $nodeDiskManifestRegistry;

    public function __construct(
        WorkspaceService $workspaceService,
        TypeSchemaRegistry $typeSchemaRegistry,
        NodeDiskManifestRegistry $nodeDiskManifestRegistry
    ) {
        $this->workspaceService = $workspaceService;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->nodeDiskManifestRegistry = $nodeDiskManifestRegistry;
    }

    /**
     * @return array{file: string, disk_path: string}
     */
    public function createRootFile(string $workspaceSlug, string $filename): array
    {
        $filename = trim($filename);
        if ($filename === '' || str_contains($filename, '/') || str_contains($filename, '..')) {
            throw new \InvalidArgumentException('Недопустимое имя файла.');
        }

        $allowed = false;
        $presetId = '';
        $typeId = '';
        $typeName = '';
        foreach ($this->nodeDiskManifestRegistry->listRootCreatableFiles($workspaceSlug) as $entry) {
            if ($entry['file'] === $filename) {
                $allowed = true;
                $presetId = $entry['preset'];
                if (isset($entry['type_id']) && is_string($entry['type_id'])) {
                    $typeId = $entry['type_id'];
                }
                if (isset($entry['label']) && is_string($entry['label'])) {
                    $typeName = $entry['label'];
                }
                break;
            }
        }
        if (!$allowed) {
            throw new \InvalidArgumentException('Этот тип файла нельзя создать в корне.');
        }

        $base = $this->workspaceService->getWorkspaceDirectory($workspaceSlug);
        if ($base === null) {
            throw new \RuntimeException('Workspace не найден на диске.');
        }

        $path = $base . '/' . $filename;
        if (is_file($path)) {
            throw new \InvalidArgumentException('Файл уже существует.');
        }

        $body = $this->resolvePresetBody($presetId, $filename);
        if ($typeId !== '') {
            $body = $this->wrapWithFrontmatter($body, $typeId, $typeName);
        }

        $this->workspaceService->writeFileAtomicPublic($path, $body);

        return [
            'file' => $filename,
            'disk_path' => $filename,
        ];
    }

    private function resolvePresetBody(string $presetId, string $filename): string
    {
        if ($presetId !== '') {
            $preset = $this->typeSchemaRegistry->resolveType($presetId, '');
            if (is_array($preset) && isset($preset['body']) && is_string($preset['body']) && $preset['body'] !== '') {
                return $preset['body'];
            }
        }

        $title = preg_replace('/\.md$/', '', $filename);
        if (!is_string($title)) {
            $title = $filename;
        }

        return "# " . $title . "\n\n";
    }

    private function wrapWithFrontmatter(string $body, string $typeId, string $typeName): string
    {
        $lines = [
            '---',
            'acms-type: ' . $this->typeSchemaRegistry->publicTypeId($typeId),
        ];
        if ($typeName !== '') {
            $lines[] = 'acms-name: ' . $typeName;
        }
        $lines[] = '---';
        $lines[] = '';

        return implode("\n", $lines) . ltrim($body, "\n");
    }
}
