<?php

namespace App\Twig;

use App\Service\UiMenuService;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class UiMenuExtension extends AbstractExtension implements GlobalsInterface
{
    private UiMenuService $uiMenuService;

    public function __construct(UiMenuService $uiMenuService)
    {
        $this->uiMenuService = $uiMenuService;
    }

    /**
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        return [
            'ui_menu_profile' => $this->uiMenuService->getProfile(),
            'ui_menu_main' => $this->uiMenuService->getZoneItems('main'),
            'ui_menu_apps' => $this->uiMenuService->getAppItems(),
            'ui_menu_apps_active' => $this->uiMenuService->hasActiveApp(),
            'ui_menu_services' => $this->uiMenuService->getServiceItems(),
            'ui_menu_services_active' => $this->uiMenuService->hasActiveService(),
            'ui_menu_profile_dropdown' => $this->uiMenuService->getZoneItems('profile_dropdown'),
            'ui_menu_search_url' => $this->uiMenuService->getHeaderSearchUrl(),
            'ui_menu_search_available' => $this->uiMenuService->isHeaderSearchAvailable(),
            'ui_menu_search_active' => $this->uiMenuService->isHeaderSearchActive(),
        ];
    }
}
