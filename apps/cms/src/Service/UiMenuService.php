<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Yaml\Yaml;

class UiMenuService
{
    private string $configPath;

    private UrlGeneratorInterface $urlGenerator;

    private RequestStack $requestStack;

    private string $publicBaseDomain;

    private string $publicHttpPort;

    private string $publicHttpsPort;

    private string $publicPreferredScheme;

    private StarterToggleService $starterToggleService;

    /** @var array<string, mixed>|null */
    private ?array $configCache = null;

    public function __construct(
        string $uiMenuConfigPath,
        UrlGeneratorInterface $urlGenerator,
        RequestStack $requestStack,
        StarterToggleService $starterToggleService,
        string $publicBaseDomain,
        string $publicHttpPort,
        string $publicHttpsPort,
        string $publicPreferredScheme
    ) {
        $this->configPath = $uiMenuConfigPath;
        $this->urlGenerator = $urlGenerator;
        $this->requestStack = $requestStack;
        $this->starterToggleService = $starterToggleService;
        $this->publicBaseDomain = $publicBaseDomain;
        $this->publicHttpPort = $publicHttpPort;
        $this->publicHttpsPort = $publicHttpsPort;
        $this->publicPreferredScheme = $publicPreferredScheme;
    }

    /**
     * @return array{display_name: string, email: string, initials: string}
     */
    public function getProfile(): array
    {
        $config = $this->loadConfig();
        $profile = $config['profile'] ?? [];
        if (!is_array($profile)) {
            $profile = [];
        }

        $displayName = $profile['display_name'] ?? '';
        if (!is_string($displayName)) {
            $displayName = '';
        }

        $email = $profile['email'] ?? '';
        if (!is_string($email)) {
            $email = '';
        }

        $initials = $profile['initials'] ?? '';
        if (!is_string($initials)) {
            $initials = '';
        }

        return [
            'display_name' => $displayName,
            'email' => $email,
            'initials' => $initials,
        ];
    }

    /**
     * @return list<array{type: string, label: string, url: string, active: bool, disabled: bool}>
     */
    public function getZoneItems(string $zone): array
    {
        $config = $this->loadConfig();
        $zones = $config['zones'] ?? [];
        if (!is_array($zones)) {
            return [];
        }

        $zoneConfig = $zones[$zone] ?? null;
        if (!is_array($zoneConfig)) {
            return [];
        }

        $rawItems = $zoneConfig['items'] ?? [];
        if (!is_array($rawItems)) {
            return [];
        }

        $currentRoute = $this->getCurrentRoute();
        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $normalized = $this->normalizeItem($rawItem, $currentRoute);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        return $items;
    }

    /**
     * @return list<array{label: string, name: string, url: string, active: bool}>
     */
    public function getAppItems(): array
    {
        $config = $this->loadConfig();
        $apps = $config['apps'] ?? [];
        if (!is_array($apps)) {
            return [];
        }

        $rawItems = $apps['items'] ?? [];
        if (!is_array($rawItems)) {
            return [];
        }

        $currentRoute = $this->getCurrentRoute();
        $currentAppName = $this->getCurrentAppName();
        $currentSection = $this->getCurrentAppSection();
        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $label = $rawItem['label'] ?? '';
            if (!is_string($label)) {
                $label = '';
            }

            $name = $rawItem['name'] ?? '';
            if (!is_string($name)) {
                $name = '';
            }

            $customRoute = $rawItem['route'] ?? '';
            if (!is_string($customRoute)) {
                $customRoute = '';
            }

            if ($customRoute !== '') {
                $routeParams = $rawItem['route_params'] ?? [];
                if (!is_array($routeParams)) {
                    $routeParams = [];
                }
                $url = $this->urlGenerator->generate($customRoute, $routeParams);
                $active = $this->isAppMenuItemActive($rawItem, $currentRoute, $currentAppName, $currentSection);
                if ($name === '') {
                    $name = 'menu-' . (string) count($items);
                }
                $items[] = [
                    'label' => $label,
                    'name' => $name,
                    'url' => $url,
                    'active' => $active,
                ];
                continue;
            }

            if (!$this->isValidAppName($name)) {
                continue;
            }

            $url = $this->urlGenerator->generate('app_show', ['name' => $name]);
            $defaultSection = $this->getDefaultAppSectionSlugFromRaw($rawItem);
            if ($defaultSection !== null) {
                $url = $this->urlGenerator->generate('app_section', [
                    'name' => $name,
                    'section' => $defaultSection,
                ]);
            }

            $active = false;
            if ($currentAppName === $name) {
                if ($currentRoute === 'app_show' || $currentRoute === 'app_section') {
                    $active = true;
                }
            }
            if ($name === 'documentation' && $currentAppName === 'documentation' && $currentSection !== '') {
                $active = true;
            }

            $items[] = [
                'label' => $label,
                'name' => $name,
                'url' => $url,
                'active' => $active,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $rawItem
     */
    private function isAppMenuItemActive(array $rawItem, string $currentRoute, string $currentAppName, string $currentSection): bool
    {
        $activeOn = $rawItem['active_on'] ?? null;
        if (!is_array($activeOn)) {
            return false;
        }

        $expectedRoute = $activeOn['route'] ?? '';
        if (!is_string($expectedRoute) || $expectedRoute === '') {
            return false;
        }

        if ($currentRoute !== $expectedRoute) {
            return false;
        }

        $expectedName = $activeOn['name'] ?? '';
        if (is_string($expectedName) && $expectedName !== '') {
            if ($currentAppName !== $expectedName) {
                return false;
            }
        }

        $expectedSection = $activeOn['section'] ?? '';
        if (is_string($expectedSection) && $expectedSection !== '') {
            if ($currentSection !== $expectedSection) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{label: string, slug: string, url: string, active: bool}>
     */
    public function getAppSections(string $appName, ?string $activeSectionOverride = null): array
    {
        if (!$this->isValidAppName($appName)) {
            return [];
        }

        $rawApp = $this->findRawAppByName($appName);
        if ($rawApp === null) {
            return [];
        }

        $rawSections = $rawApp['sections'] ?? [];
        if (!is_array($rawSections)) {
            return [];
        }

        $currentRoute = $this->getCurrentRoute();
        $currentAppName = $this->getCurrentAppName();
        $currentSection = $this->getCurrentAppSection();

        $sections = [];
        foreach ($rawSections as $rawSection) {
            if (!is_array($rawSection)) {
                continue;
            }

            $label = $rawSection['label'] ?? '';
            if (!is_string($label)) {
                $label = '';
            }

            $slug = $rawSection['slug'] ?? '';
            if (!is_string($slug) || !$this->isValidSectionSlug($slug)) {
                continue;
            }

            $url = $this->urlGenerator->generate('app_section', [
                'name' => $appName,
                'section' => $slug,
            ]);

            $active = false;
            if ($activeSectionOverride !== null) {
                if ($slug === $activeSectionOverride) {
                    $active = true;
                }
            } elseif ($currentAppName === $appName && $currentSection === $slug) {
                $active = true;
            }

            $sections[] = [
                'label' => $label,
                'slug' => $slug,
                'url' => $url,
                'active' => $active,
            ];
        }

        return $sections;
    }

    public function getDefaultAppSectionSlug(string $appName): ?string
    {
        $rawApp = $this->findRawAppByName($appName);
        if ($rawApp === null) {
            return null;
        }

        return $this->getDefaultAppSectionSlugFromRaw($rawApp);
    }

    /**
     * @return array{label: string, slug: string}|null
     */
    public function findAppSection(string $appName, string $sectionSlug): ?array
    {
        foreach ($this->getAppSections($appName) as $section) {
            if ($section['slug'] === $sectionSlug) {
                return [
                    'label' => $section['label'],
                    'slug' => $section['slug'],
                ];
            }
        }

        return null;
    }

    /**
     * @return array{label: string, name: string, hide_apps_nav: bool}|null
     */
    public function findAppByName(string $name): ?array
    {
        if (!$this->isValidAppName($name)) {
            return null;
        }

        $rawApp = $this->findRawAppByName($name);
        if ($rawApp === null) {
            return null;
        }

        $label = $rawApp['label'] ?? '';
        if (!is_string($label)) {
            $label = '';
        }

        $hideAppsNav = $rawApp['hide_apps_nav'] ?? false;
        if (!is_bool($hideAppsNav)) {
            $hideAppsNav = false;
        }

        return [
            'label' => $label,
            'name' => $name,
            'hide_apps_nav' => $hideAppsNav,
        ];
    }

    public function hasActiveApp(): bool
    {
        foreach ($this->getAppItems() as $item) {
            if ($item['active']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{label: string, subdomain: string, url: string, host: string, description: string, active: bool}>
     */
    public function getServiceItems(): array
    {
        $config = $this->loadConfig();
        $services = $config['services'] ?? [];
        if (!is_array($services)) {
            return [];
        }

        $rawItems = $services['items'] ?? [];
        if (!is_array($rawItems)) {
            return [];
        }

        $requestHost = $this->getCurrentRequestHost();
        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $label = $rawItem['label'] ?? '';
            if (!is_string($label)) {
                $label = '';
            }

            $subdomain = $rawItem['subdomain'] ?? '';
            if (!is_string($subdomain) || !$this->isValidSubdomain($subdomain)) {
                continue;
            }

            $serviceId = $rawItem['id'] ?? $subdomain;
            if (!is_string($serviceId) || $serviceId === '') {
                $serviceId = $subdomain;
            }

            if (!$this->starterToggleService->isServiceEnabled($serviceId)) {
                continue;
            }

            $description = $rawItem['description'] ?? '';
            if (!is_string($description)) {
                $description = '';
            }

            $host = $subdomain . '.' . $this->publicBaseDomain;
            $url = $this->buildServicePublicUrl($subdomain);

            $active = false;
            if ($requestHost === $host) {
                $active = true;
            }

            $items[] = [
                'label' => $label,
                'subdomain' => $subdomain,
                'url' => $url,
                'host' => $host,
                'description' => $description,
                'active' => $active,
            ];
        }

        return $items;
    }

    public function hasActiveService(): bool
    {
        foreach ($this->getServiceItems() as $item) {
            if ($item['active']) {
                return true;
            }
        }

        return false;
    }

    private function buildServicePublicUrl(string $subdomain): string
    {
        $host = $subdomain . '.' . $this->publicBaseDomain;
        $scheme = $this->publicPreferredScheme;
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }

        $portSuffix = '';
        if ($scheme === 'http') {
            if ($this->publicHttpPort !== '' && $this->publicHttpPort !== '80') {
                $portSuffix = ':' . $this->publicHttpPort;
            }
        } else {
            if ($this->publicHttpsPort !== '' && $this->publicHttpsPort !== '443') {
                $portSuffix = ':' . $this->publicHttpsPort;
            }
        }

        return $scheme . '://' . $host . $portSuffix . '/';
    }

    private function getCurrentRequestHost(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return '';
        }

        $host = $request->getHost();
        if (!is_string($host)) {
            return '';
        }

        return strtolower($host);
    }

    private function isValidSubdomain(string $subdomain): bool
    {
        return (bool) preg_match('/^[a-z0-9-]+$/', $subdomain);
    }

    /**
     * @param array<string, mixed> $rawItem
     *
     * @return array{type: string, label: string, url: string, active: bool, disabled: bool}|null
     */
    private function normalizeItem(array $rawItem, string $currentRoute): ?array
    {
        if (!empty($rawItem['divider'])) {
            return [
                'type' => 'divider',
                'label' => '',
                'url' => '',
                'active' => false,
                'disabled' => false,
            ];
        }

        $label = $rawItem['label'] ?? '';
        if (!is_string($label)) {
            $label = '';
        }

        $enabled = $rawItem['enabled'] ?? true;
        if (!is_bool($enabled)) {
            $enabled = true;
        }

        $route = $rawItem['route'] ?? '';
        if (!is_string($route)) {
            $route = '';
        }

        $routeParams = $rawItem['route_params'] ?? [];
        if (!is_array($routeParams)) {
            $routeParams = [];
        }

        $url = '';
        if ($enabled && $route !== '') {
            $url = $this->urlGenerator->generate($route, $routeParams);
        }

        $activeRoutes = $rawItem['active_routes'] ?? [];
        if (!is_array($activeRoutes)) {
            $activeRoutes = [];
        }

        $active = false;
        if ($currentRoute !== '' && in_array($currentRoute, $activeRoutes, true)) {
            $active = true;
        }

        $disabled = false;
        if (!$enabled) {
            $disabled = true;
        }

        return [
            'type' => 'link',
            'label' => $label,
            'url' => $url,
            'active' => $active,
            'disabled' => $disabled,
        ];
    }

    private function getCurrentRoute(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return '';
        }

        $route = $request->attributes->get('_route');
        if (!is_string($route)) {
            return '';
        }

        return $route;
    }

    private function getCurrentAppName(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return '';
        }

        $name = $request->attributes->get('name');
        if (!is_string($name)) {
            return '';
        }

        return $name;
    }

    private function getCurrentAppSection(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return '';
        }

        $section = $request->attributes->get('section');
        if (!is_string($section)) {
            return '';
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $rawApp
     */
    private function getDefaultAppSectionSlugFromRaw(array $rawApp): ?string
    {
        $rawSections = $rawApp['sections'] ?? [];
        if (!is_array($rawSections) || count($rawSections) === 0) {
            return null;
        }

        $first = $rawSections[0];
        if (!is_array($first)) {
            return null;
        }

        $slug = $first['slug'] ?? '';
        if (!is_string($slug) || !$this->isValidSectionSlug($slug)) {
            return null;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRawAppByName(string $name): ?array
    {
        $config = $this->loadConfig();
        $apps = $config['apps'] ?? [];
        if (!is_array($apps)) {
            return null;
        }

        $rawItems = $apps['items'] ?? [];
        if (!is_array($rawItems)) {
            return null;
        }

        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $itemName = $rawItem['name'] ?? '';
            if (!is_string($itemName)) {
                continue;
            }

            if ($itemName === $name) {
                return $rawItem;
            }
        }

        return null;
    }

    private function isValidAppName(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9_-]+$/', $name);
    }

    private function isValidSectionSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9_-]+$/', $slug);
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

        $content = file_get_contents($this->configPath);
        if ($content === false) {
            $this->configCache = [];

            return $this->configCache;
        }

        try {
            $parsed = Yaml::parse($content);
        } catch (\Exception $exception) {
            $this->configCache = [];

            return $this->configCache;
        }

        if (!is_array($parsed)) {
            $this->configCache = [];

            return $this->configCache;
        }

        $this->configCache = $parsed;

        return $this->configCache;
    }
}
