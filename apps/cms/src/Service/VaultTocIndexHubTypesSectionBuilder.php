<?php

namespace App\Service;

class VaultTocIndexHubTypesSectionBuilder
{
    private TypeSchemaRegistry $typeSchemaRegistry;

    public function __construct(TypeSchemaRegistry $typeSchemaRegistry)
    {
        $this->typeSchemaRegistry = $typeSchemaRegistry;
    }

    /**
     * @return list<string>
     */
    public function buildGroupedSectionLines(string $vaultSlug = ''): array
    {
        $lines = [];
        $lines[] = '## Типы хабов и разделов';
        $lines[] = '';

        $groups = $this->typeSchemaRegistry->listHubTreeGroups($vaultSlug);
        if (count($groups) === 0) {
            $lines[] = '_Нет зарегистрированных хабов._';
            $lines[] = '';

            return $lines;
        }

        $g = 0;
        for ($g = 0; $g < count($groups); $g = $g + 1) {
            $group = $groups[$g];
            if (!is_array($group)) {
                continue;
            }

            $family = '';
            if (isset($group['family']) && is_string($group['family'])) {
                $family = trim($group['family']);
            }

            $label = $family;
            if (isset($group['label']) && is_string($group['label']) && trim($group['label']) !== '') {
                $label = trim($group['label']);
            }

            $folder = '';
            if (isset($group['folder']) && is_string($group['folder'])) {
                $folder = trim(str_replace('\\', '/', $group['folder']), '/');
            }

            $hubTypeId = '';
            if ($family !== '') {
                $hubTypeId = 'hubs/' . $family;
            }

            $hubPublicType = '';
            if ($hubTypeId !== '') {
                $hubPublicType = $this->typeSchemaRegistry->publicTypeId($hubTypeId);
            }

            $lines[] = '### ' . $label;
            $lines[] = '';

            $lines[] = '| Параметр | Значение |';
            $lines[] = '| --- | --- |';
            $lines[] = '| Тип хаба | ' . $this->escapeTableCell($hubPublicType !== '' ? $hubPublicType : $hubTypeId) . ' |';
            if ($folder !== '' && $folder !== '.') {
                $lines[] = '| Папка | ' . $this->escapeTableCell($folder) . ' |';
            }
            $lines[] = '';

            $typeItems = [];
            if (isset($group['type_items']) && is_array($group['type_items'])) {
                $typeItems = $group['type_items'];
            }

            foreach ($this->buildSectionTypeItemsTableLines($typeItems) as $tableLine) {
                $lines[] = $tableLine;
            }
        }

        return $lines;
    }

    /**
     * @param list<mixed> $typeItems
     *
     * @return list<string>
     */
    private function buildSectionTypeItemsTableLines(array $typeItems): array
    {
        $lines = [];

        if (count($typeItems) === 0) {
            $lines[] = '_Нет типов разделов для этого хаба._';
            $lines[] = '';

            return $lines;
        }

        $lines[] = '**Типы разделов**';
        $lines[] = '';
        $lines[] = '| Тип | Название |';
        $lines[] = '| --- | --- |';

        $i = 0;
        for ($i = 0; $i < count($typeItems); $i = $i + 1) {
            $item = $typeItems[$i];
            if (!is_array($item)) {
                continue;
            }

            $name = '';
            if (isset($item['name']) && is_string($item['name'])) {
                $name = trim($item['name']);
            }

            $typeId = '';
            if (isset($item['id']) && is_string($item['id'])) {
                $typeId = trim($item['id']);
            }

            $typeLabel = $typeId;
            if ($typeId !== '') {
                $typeLabel = $this->typeSchemaRegistry->publicTypeId($typeId);
            }

            $lines[] = '| '
                . $this->escapeTableCell($typeLabel) . ' | '
                . $this->escapeTableCell($name) . ' |';
        }

        $lines[] = '';

        return $lines;
    }

    private function escapeTableCell(string $value): string
    {
        $value = str_replace("\r", '', $value);
        $value = str_replace('|', '\\|', $value);
        $value = str_replace("\n", ' ', $value);

        return trim($value);
    }
}