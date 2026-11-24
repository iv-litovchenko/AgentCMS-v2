<?php

namespace App\Controller;

use AgentCms\Pages\DemoBlock\DemoBlockService;
use App\Service\StarterToggleService;
use App\Service\VaultService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    private DemoBlockService $demoBlockService;

    private VaultService $vaultService;

    private StarterToggleService $starterToggleService;

    public function __construct(
        DemoBlockService $demoBlockService,
        VaultService $vaultService,
        StarterToggleService $starterToggleService
    ) {
        $this->demoBlockService = $demoBlockService;
        $this->vaultService = $vaultService;
        $this->starterToggleService = $starterToggleService;
    }

    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return $this->renderHome(false);
    }

    #[Route('/compact', name: 'home_compact')]
    public function compact(): Response
    {
        return $this->renderHome(true);
    }

    private function renderHome(bool $compact): Response
    {
        $demoBlock = null;
        if ($this->starterToggleService->isPackageEnabled('demo_block')) {
            $demoBlock = $this->demoBlockService->getBlockData();
        }

        return $this->render('home/index.html.twig', [
            'compact' => $compact,
            'demo_block' => $demoBlock,
            'storages' => $this->vaultService->listForDisplay(),
        ]);
    }
}
