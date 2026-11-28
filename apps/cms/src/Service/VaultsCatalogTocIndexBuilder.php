<?php

namespace App\Service;

class VaultsCatalogTocIndexBuilder
{
    public const INDEX_RELATIVE_PATH = 'INDEX.md';

    public const CSRF_TOKEN_ID = 'vaults_toc_index_build';

    private string $vaultsDir;

    private VaultService $vaultService;

    public function __construct(
        string $vaultsDir,
        VaultService $vaultService
    ) {
        $this->vaultsDir = rtrim($vaultsDir, '/');
        $this->vaultService = $vaultService;
    }

    public function indexExists(): bool
    {
        $absolute = $this->resolveIndexAbsolutePath();
        if ($absolute === null) {
            return false;
        }

        return is_file($absolute);
    }

    public function readIndexBody(): string
    {
        $absolute = $this->resolveIndexAbsolutePath();
        if ($absolute === null || !is_file($absolute)) {
            return '';
        }

        $content = file_get_contents($absolute);
        if (!is_string($content)) {
            return '';
        }

        return $content;
    }

    /**
     * @return string путь INDEX.md относительно каталога vaults/
     */
    public function writeIndex(): string
    {
        $absolute = $this->resolveIndexAbsolutePath();
        if ($absolute === null) {
            throw new \RuntimeException('Каталог vaults недоступен.');
        }

        $markdown = $this->buildIndexMarkdown();
        $this->vaultService->writeFileAtomicPublic($absolute, $markdown);

        return self::INDEX_RELATIVE_PATH;
    }

    public function buildIndexMarkdown(): string
    {
        $lines = [];
        $lines[] = '# Индекс каталога хранилищ';
        $lines[] = '';
        $lines[] = '| Slug | Название | Описание | Расположение |';
        $lines[] = '| --- | --- | --- | --- |';

        $vaults = $this->vaultService->list();
        if (count($vaults) === 0) {
            $lines[] = '| — | — | — | Нет хранилищ |';
        } else {
            $i = 0;
            for ($i = 0; $i < count($vaults); $i = $i + 1) {
                $vault = $vaults[$i];
                $location = 'локально';
                if (isset($vault['location']) && $vault['location'] === 'server') {
                    $location = 'сервер';
                }

                $slug = '';
                if (isset($vault['slug']) && is_string($vault['slug'])) {
                    $slug = $vault['slug'];
                }

                $name = '';
                if (isset($vault['name']) && is_string($vault['name'])) {
                    $name = $vault['name'];
                }

                $description = '';
                if (isset($vault['description']) && is_string($vault['description'])) {
                    $description = $vault['description'];
                }

                $lines[] = '| '
                    . $this->escapeTableCell($slug) . ' | '
                    . $this->escapeTableCell($name) . ' | '
                    . $this->escapeTableCell($description) . ' | '
                    . $this->escapeTableCell($location) . ' |';
            }
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    public function resolveIndexAbsolutePath(): ?string
    {
        if ($this->vaultsDir === '') {
            return null;
        }

        return $this->vaultsDir . '/' . self::INDEX_RELATIVE_PATH;
    }

    private function escapeTableCell(string $value): string
    {
        $value = str_replace("\r", '', $value);
        $value = str_replace('|', '\\|', $value);
        $value = str_replace("\n", ' ', $value);

        return trim($value);
    }
}