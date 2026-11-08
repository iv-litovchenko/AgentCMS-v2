<?php

namespace App\Service;

class WorkspaceDiskNodeEditService
{
    public const MODE_NODE_MANIFEST = 'node_manifest';

    public const MODE_MARKDOWN = 'markdown';

    public const MODE_TEXT = 'text';

    private WorkspaceNodeScanner $workspaceNodeScanner;

    private WorkspaceManifestReader $manifestReader;

    private WorkspaceService $workspaceService;

    private AcmsManifestPropertyBridge $acmsManifestPropertyBridge;

    public function __construct(
        WorkspaceNodeScanner $workspaceNodeScanner,
        WorkspaceManifestReader $manifestReader,
        WorkspaceService $workspaceService,
        AcmsManifestPropertyBridge $acmsManifestPropertyBridge
    ) {
        $this->workspaceNodeScanner = $workspaceNodeScanner;
        $this->manifestReader = $manifestReader;
        $this->workspaceService = $workspaceService;
        $this->acmsManifestPropertyBridge = $acmsManifestPropertyBridge;
    }

    /**
     * @param array<string, mixed> $diskNode
     */
    public function resolveEditMode(array $diskNode): string
    {
        $file = '';
        if (isset($diskNode['manifest_file']) && is_string($diskNode['manifest_file'])) {
            $file = $diskNode['manifest_file'];
        }
        if ($file === 'manifest.node.md' || str_ends_with($file, '.node.md')) {
            return self::MODE_NODE_MANIFEST;
        }
        if (str_ends_with($file, '.md')) {
            return self::MODE_MARKDOWN;
        }

        return self::MODE_TEXT;
    }

    public function resolveCsrfTokenId(string $diskPath): string
    {
        $diskPath = trim(str_replace('\\', '/', $diskPath), '/');

        return 'workspace_disk_node_edit_' . md5($diskPath);
    }

    /**
     * @param array<string, mixed> $diskNode
     */
    public function save(
        string $workspaceSlug,
        string $diskPath,
        array $diskNode,
        string $mode,
        string $name,
        string $description,
        string $body,
        string $rawText
    ): void {
        $absolutePath = $this->workspaceNodeScanner->resolveDiskNodeAbsolutePath($workspaceSlug, $diskPath);
        if ($absolutePath === null) {
            throw new \InvalidArgumentException('Файл узла не найден.');
        }

        $content = '';
        if ($mode === self::MODE_NODE_MANIFEST) {
            $content = $this->buildNodeManifestContent($diskNode, $name, $description, $body);
        } elseif ($mode === self::MODE_MARKDOWN) {
            $content = $this->buildMarkdownContent($diskNode, $name, $description, $body);
        } else {
            $content = $rawText;
        }

        $this->workspaceService->writeFileAtomicPublic($absolutePath, $content);
    }

    /**
     * @param array<string, mixed> $diskNode
     */
    private function buildNodeManifestContent(array $diskNode, string $name, string $description, string $body): string
    {
        $properties = [];
        if (isset($diskNode['properties']) && is_array($diskNode['properties'])) {
            $properties = $diskNode['properties'];
        }

        $properties['acms-name'] = $name;
        $properties['acms-description'] = $description;
        $properties['acms-update'] = (new \DateTimeImmutable('now'))->format('c');

        return $this->manifestReader->buildFileContent($properties, $body);
    }

    /**
     * @param array<string, mixed> $diskNode
     */
    private function buildMarkdownContent(array $diskNode, string $name, string $description, string $body): string
    {
        $properties = [];
        if (isset($diskNode['properties']) && is_array($diskNode['properties'])) {
            $properties = $diskNode['properties'];
        }

        if (count($properties) === 0) {
            return $body;
        }

        if ($this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-name') !== ''
            || isset($properties['acms-name'])
            || isset($properties['awn-name'])) {
            $properties['acms-name'] = $name;
            unset($properties['awn-name']);
        } elseif (isset($properties['name'])) {
            $properties['name'] = $name;
        } else {
            $properties['acms-name'] = $name;
        }

        if ($this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-description') !== ''
            || isset($properties['acms-description'])
            || isset($properties['awn-description'])) {
            $properties['acms-description'] = $description;
            unset($properties['awn-description']);
        } elseif (isset($properties['description'])) {
            $properties['description'] = $description;
        } else {
            $properties['acms-description'] = $description;
        }

        if (isset($properties['acms-update']) || isset($properties['awn-update'])) {
            $properties['acms-update'] = (new \DateTimeImmutable('now'))->format('c');
            unset($properties['awn-update']);
        }

        return $this->manifestReader->buildFileContent($properties, $body);
    }
}
