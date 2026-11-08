<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class HubViewRegistry
{
    private string $configPath;

    /** @var array<string, mixed>|null */
    private ?array $configCache = null;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function resolveViewTemplate(string $hubPath): string
    {
        $hubPath = trim(str_replace('\\', '/', $hubPath), '/');
        $config = $this->loadConfig();
        $default = 'workspace/hubs/_default.html.twig';
        if (isset($config['default']) && is_string($config['default']) && $config['default'] !== '') {
            $default = $config['default'];
        }

        $views = $config['views'] ?? [];
        if (!is_array($views)) {
            return $default;
        }

        if ($hubPath !== '' && isset($views[$hubPath]) && is_string($views[$hubPath])) {
            return $views[$hubPath];
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        if ($this->configCache !== null) {
            return $this->configCache;
        }

        if (!is_file($this->configPath)) {
            $this->configCache = [];

            return $this->configCache;
        }

        $parsed = Yaml::parseFile($this->configPath);
        if (!is_array($parsed)) {
            $this->configCache = [];

            return $this->configCache;
        }

        $this->configCache = $parsed;

        return $this->configCache;
    }
}
