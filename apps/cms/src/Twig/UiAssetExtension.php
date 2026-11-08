<?php

namespace App\Twig;

use App\Service\UiAssetPackageService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class UiAssetExtension extends AbstractExtension
{
    private UiAssetPackageService $uiAssetPackageService;

    public function __construct(UiAssetPackageService $uiAssetPackageService)
    {
        $this->uiAssetPackageService = $uiAssetPackageService;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ui_asset_active_packages', [$this, 'resolveActivePackages']),
        ];
    }

    /**
     * @param list<string>|mixed $extraIds
     *
     * @return list<array{
     *     id: string,
     *     stylesheets: list<array{href: string}>,
     *     scripts: list<array{src: string}>
     * }>
     */
    public function resolveActivePackages($extraIds = []): array
    {
        $ids = [];
        if (is_array($extraIds)) {
            foreach ($extraIds as $extraId) {
                if (is_string($extraId)) {
                    $ids[] = $extraId;
                }
            }
        }

        return $this->uiAssetPackageService->resolveActivePackages($ids);
    }
}
