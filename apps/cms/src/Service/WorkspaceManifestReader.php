<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class WorkspaceManifestReader
{
    private const FRONTMATTER_DELIM = '---';

    private AcmsManifestPropertyBridge $acmsManifestPropertyBridge;

    public function __construct(AcmsManifestPropertyBridge $acmsManifestPropertyBridge)
    {
        $this->acmsManifestPropertyBridge = $acmsManifestPropertyBridge;
    }

    /**
     * @return array{properties: array, body: string}|null
     */
    public function parseFile(string $manifestPath): ?array
    {
        if (!is_file($manifestPath)) {
            return null;
        }

        $content = file_get_contents($manifestPath);
        if ($content === false) {
            return null;
        }

        return $this->parseMarkdownWithFrontmatter($content);
    }

    /**
     * @return array{name: string, description: string}|null
     */
    public function read(string $manifestPath): ?array
    {
        $meta = $this->readWorkspaceMeta($manifestPath);
        if ($meta === null) {
            return null;
        }

        return [
            'name' => $meta['name'],
            'description' => $meta['description'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readWorkspaceMeta(string $manifestPath): ?array
    {
        $parsed = $this->parseFile($manifestPath);
        if ($parsed === null) {
            return null;
        }

        $properties = $parsed['properties'];
        $body = $parsed['body'];

        $name = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-name');
        if ($name === '') {
            $name = $this->stringProperty($properties, 'name');
        }

        $description = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-description');
        if ($description === '') {
            $description = $this->stringProperty($properties, 'description');
        }
        if ($description === '') {
            $description = $this->firstParagraph($body);
        }

        $location = $this->stringProperty($properties, 'cms-location');
        if ($location !== 'local' && $location !== 'server') {
            $location = 'local';
        }

        $avatar = $this->stringProperty($properties, 'cms-avatar');

        $sortOrder = $this->acmsManifestPropertyBridge->intProperty($properties, 'acms-sort');

        $createdAt = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-create');
        $updatedAt = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-update');
        if ($updatedAt === '') {
            $updatedAt = $createdAt;
        }

        $typeId = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-type');
        if ($typeId === '') {
            $typeId = 'acms.root.ws';
        }

        return [
            'name' => $name,
            'description' => $description,
            'location' => $location,
            'sort_order' => $sortOrder,
            'avatar' => $avatar,
            'type_id' => $typeId,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $properties
     */
    public function buildFileContent(array $properties, string $body = "\n"): string
    {
        $properties = $this->acmsManifestPropertyBridge->stripLegacyAwnDuplicates($properties);
        $yaml = Yaml::dump($properties, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        if ($body === '') {
            $body = "\n";
        }
        if (!str_starts_with($body, "\n")) {
            $body = "\n" . $body;
        }

        $delim = self::FRONTMATTER_DELIM;

        return $delim . "\n" . $yaml . $delim . $body;
    }

    /**
     * @return array{properties: array, body: string}|null
     */
    private function parseMarkdownWithFrontmatter(string $content): ?array
    {
        $delim = self::FRONTMATTER_DELIM;
        $trimmed = ltrim($content);
        if (!str_starts_with($trimmed, $delim)) {
            return [
                'properties' => [],
                'body' => $content,
            ];
        }

        $lines = preg_split("/\r\n|\n|\r/", $trimmed);
        if ($lines === false || count($lines) < 2) {
            return null;
        }

        $yamlLines = [];
        $endIndex = -1;
        $lineCount = count($lines);
        for ($i = 1; $i < $lineCount; $i = $i + 1) {
            $line = $lines[$i];
            if (trim($line) === $delim) {
                $endIndex = $i;
                break;
            }
            array_push($yamlLines, $line);
        }

        if ($endIndex < 0) {
            return null;
        }

        $yamlText = implode("\n", $yamlLines);
        try {
            $properties = Yaml::parse($yamlText);
        } catch (\Exception $exception) {
            return null;
        }

        if (!is_array($properties)) {
            $properties = [];
        }

        $bodyLines = [];
        for ($j = $endIndex + 1; $j < $lineCount; $j = $j + 1) {
            array_push($bodyLines, $lines[$j]);
        }

        return [
            'properties' => $properties,
            'body' => implode("\n", $bodyLines),
        ];
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function stringProperty(array $properties, string $key): string
    {
        if (!isset($properties[$key]) || !is_string($properties[$key])) {
            return '';
        }

        return trim($properties[$key]);
    }

    private function firstParagraph(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $parts = preg_split("/\n\s*\n/", $body);
        if ($parts === false || count($parts) === 0) {
            $line = trim($body);
        } else {
            $line = trim($parts[0]);
        }

        $line = preg_replace('/\s+/u', ' ', $line);
        if ($line === null) {
            $line = '';
        }

        if (mb_strlen($line) > 240) {
            $line = mb_substr($line, 0, 237) . '...';
        }

        return $line;
    }
}
