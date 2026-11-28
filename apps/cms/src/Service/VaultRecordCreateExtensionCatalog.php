<?php

namespace App\Service;

class VaultRecordCreateExtensionCatalog
{
    /** @var list<string>|null */
    private ?array $allExtensionsCache = null;

    /** @var list<string>|null */
    private ?array $binaryExtensionsCache = null;

    /**
     * @return list<array{label: string, items: list<array{ext: string, title: string, hint: string}>}>
     */
    public function groups(): array
    {
        return [
            [
                'label' => 'Запись',
                'items' => [
                    ['ext' => 'md', 'title' => 'Markdown', 'hint' => 'запись'],
                ],
            ],
            [
                'label' => 'Текст и данные',
                'items' => [
                    ['ext' => 'txt', 'title' => 'Plain text', 'hint' => 'текст'],
                    ['ext' => 'json', 'title' => 'JSON', 'hint' => 'данные'],
                    ['ext' => 'yaml', 'title' => 'YAML', 'hint' => 'конфиг'],
                    ['ext' => 'yml', 'title' => 'YML', 'hint' => 'конфиг'],
                    ['ext' => 'toml', 'title' => 'TOML', 'hint' => 'конфиг'],
                    ['ext' => 'csv', 'title' => 'CSV', 'hint' => 'таблица'],
                    ['ext' => 'xml', 'title' => 'XML', 'hint' => 'разметка'],
                    ['ext' => 'ini', 'title' => 'INI', 'hint' => 'конфиг'],
                    ['ext' => 'env', 'title' => 'ENV', 'hint' => 'переменные'],
                    ['ext' => 'log', 'title' => 'Log', 'hint' => 'журнал'],
                ],
            ],
            [
                'label' => 'Веб',
                'items' => [
                    ['ext' => 'html', 'title' => 'HTML', 'hint' => 'веб'],
                    ['ext' => 'css', 'title' => 'CSS', 'hint' => 'стили'],
                    ['ext' => 'js', 'title' => 'JavaScript', 'hint' => 'скрипт'],
                    ['ext' => 'jsx', 'title' => 'JSX', 'hint' => 'react'],
                    ['ext' => 'ts', 'title' => 'TypeScript', 'hint' => 'скрипт'],
                    ['ext' => 'tsx', 'title' => 'TSX', 'hint' => 'react'],
                ],
            ],
            [
                'label' => 'Скрипты и агенты',
                'items' => [
                    ['ext' => 'py', 'title' => 'Python', 'hint' => 'агент'],
                    ['ext' => 'sh', 'title' => 'Shell', 'hint' => 'bash'],
                    ['ext' => 'bash', 'title' => 'Bash', 'hint' => 'shell'],
                    ['ext' => 'zsh', 'title' => 'Zsh', 'hint' => 'shell'],
                    ['ext' => 'ps1', 'title' => 'PowerShell', 'hint' => 'windows'],
                    ['ext' => 'bat', 'title' => 'Batch', 'hint' => 'cmd'],
                    ['ext' => 'cmd', 'title' => 'CMD', 'hint' => 'windows'],
                    ['ext' => 'rb', 'title' => 'Ruby', 'hint' => 'скрипт'],
                    ['ext' => 'go', 'title' => 'Go', 'hint' => 'скрипт'],
                    ['ext' => 'rs', 'title' => 'Rust', 'hint' => 'скрипт'],
                    ['ext' => 'php', 'title' => 'PHP', 'hint' => 'скрипт'],
                    ['ext' => 'lua', 'title' => 'Lua', 'hint' => 'скрипт'],
                    ['ext' => 'pl', 'title' => 'Perl', 'hint' => 'скрипт'],
                    ['ext' => 'sql', 'title' => 'SQL', 'hint' => 'запросы'],
                ],
            ],
            [
                'label' => 'Изображения и медиа',
                'items' => [
                    ['ext' => 'png', 'title' => 'PNG', 'hint' => 'изображение'],
                    ['ext' => 'jpg', 'title' => 'JPEG', 'hint' => 'изображение'],
                    ['ext' => 'jpeg', 'title' => 'JPEG', 'hint' => 'изображение'],
                    ['ext' => 'gif', 'title' => 'GIF', 'hint' => 'анимация'],
                    ['ext' => 'webp', 'title' => 'WebP', 'hint' => 'изображение'],
                    ['ext' => 'svg', 'title' => 'SVG', 'hint' => 'вектор'],
                    ['ext' => 'pdf', 'title' => 'PDF', 'hint' => 'документ'],
                ],
            ],
            [
                'label' => 'Документы Office',
                'items' => [
                    ['ext' => 'docx', 'title' => 'Word', 'hint' => 'office'],
                    ['ext' => 'doc', 'title' => 'Word (legacy)', 'hint' => 'office'],
                    ['ext' => 'xlsx', 'title' => 'Excel', 'hint' => 'таблица'],
                    ['ext' => 'xls', 'title' => 'Excel (legacy)', 'hint' => 'таблица'],
                    ['ext' => 'pptx', 'title' => 'PowerPoint', 'hint' => 'презентация'],
                    ['ext' => 'ppt', 'title' => 'PowerPoint (legacy)', 'hint' => 'презентация'],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function allExtensions(): array
    {
        if ($this->allExtensionsCache !== null) {
            return $this->allExtensionsCache;
        }

        $out = [];
        foreach ($this->groups() as $group) {
            foreach ($group['items'] as $item) {
                $ext = $item['ext'];
                if (!in_array($ext, $out, true)) {
                    $out[] = $ext;
                }
            }
        }

        $this->allExtensionsCache = $out;

        return $out;
    }

    /**
     * @return list<string>
     */
    public function binaryExtensions(): array
    {
        if ($this->binaryExtensionsCache !== null) {
            return $this->binaryExtensionsCache;
        }

        $this->binaryExtensionsCache = [
            'png',
            'jpg',
            'jpeg',
            'gif',
            'webp',
            'pdf',
            'docx',
            'doc',
            'xlsx',
            'xls',
            'pptx',
            'ppt',
        ];

        return $this->binaryExtensionsCache;
    }

    public function isBinaryExtension(string $extension): bool
    {
        $extension = strtolower(trim($extension));

        return in_array($extension, $this->binaryExtensions(), true);
    }

    public function isKnownExtension(string $extension): bool
    {
        $extension = strtolower(trim($extension));

        return in_array($extension, $this->allExtensions(), true);
    }

    /**
     * @param list<string> $acceptFiles
     *
     * @return list<string>
     */
    public function extensionsForAcceptFiles(array $acceptFiles): array
    {
        if ($this->acceptFilesWildcard($acceptFiles)) {
            return $this->allExtensions();
        }

        $allowedTokens = $this->normalizeAcceptFileTokens($acceptFiles);
        if (count($allowedTokens) === 0) {
            return ['md'];
        }

        $out = [];
        foreach ($this->allExtensions() as $ext) {
            if (in_array($ext, $allowedTokens, true) && !in_array($ext, $out, true)) {
                $out[] = $ext;
            }
        }

        foreach ($allowedTokens as $token) {
            if ($this->isKnownExtension($token) && !in_array($token, $out, true)) {
                $out[] = $token;
            }
        }

        if (count($out) === 0) {
            return ['md'];
        }

        return $out;
    }

    /**
     * @param list<string> $acceptFiles
     *
     * @return list<array{label: string, items: list<array{ext: string, title: string, hint: string, option_label: string}>}>
     */
    public function groupsForAcceptFiles(array $acceptFiles): array
    {
        $allowed = $this->extensionsForAcceptFiles($acceptFiles);
        $groups = [];

        foreach ($this->groups() as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (!in_array($item['ext'], $allowed, true)) {
                    continue;
                }
                $optionLabel = $item['title'] . ' .' . $item['ext'];
                if ($item['hint'] !== '') {
                    $optionLabel = $optionLabel . ' — ' . $item['hint'];
                }
                $items[] = [
                    'ext' => $item['ext'],
                    'title' => $item['title'],
                    'hint' => $item['hint'],
                    'option_label' => $optionLabel,
                ];
            }
            if (count($items) > 0) {
                $groups[] = [
                    'label' => $group['label'],
                    'items' => $items,
                ];
            }
        }

        return $groups;
    }

    /**
     * @param list<string> $acceptFiles
     */
    private function acceptFilesWildcard(array $acceptFiles): bool
    {
        if (count($acceptFiles) === 0) {
            return true;
        }

        foreach ($acceptFiles as $item) {
            if ($item === '*') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $acceptFiles
     *
     * @return list<string>
     */
    private function normalizeAcceptFileTokens(array $acceptFiles): array
    {
        $out = [];
        foreach ($acceptFiles as $item) {
            if (!is_string($item)) {
                continue;
            }
            $norm = $this->normalizeAcceptFileExtensionToken($item);
            if ($norm === '') {
                continue;
            }
            if (!in_array($norm, $out, true)) {
                $out[] = $norm;
            }
        }

        return $out;
    }

    private function normalizeAcceptFileExtensionToken(string $item): string
    {
        $item = strtolower(trim($item));
        if ($item === '' || $item === '*') {
            return '';
        }

        if (str_starts_with($item, '.')) {
            $item = substr($item, 1);
        }

        if ($item === '' || !preg_match('/^[a-z0-9]+$/', $item)) {
            return '';
        }

        return $item;
    }
}