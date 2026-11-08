<?php

namespace App\Controller;

use App\Service\UiMenuService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TypographyController extends AbstractController
{
    private UiMenuService $uiMenuService;

    public function __construct(UiMenuService $uiMenuService)
    {
        $this->uiMenuService = $uiMenuService;
    }

    #[Route('/typography', name: 'typography', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->redirectToTypography($request);
    }

    #[Route('/demo-form', name: 'demo_form', methods: ['GET'])]
    public function legacyDemoForm(Request $request): Response
    {
        return $this->redirectToTypography($request);
    }

    #[Route('/tailwind-demo', name: 'tailwind_demo', methods: ['GET'])]
    public function legacyTailwindDemo(Request $request): Response
    {
        return $this->redirectToTypography($request);
    }

    #[Route('/flowbite-demo', name: 'flowbite_demo', methods: ['GET'])]
    public function legacyFlowbiteDemo(Request $request): Response
    {
        return $this->redirectToTypography($request);
    }

    public function renderPage(Request $request): Response
    {
        $view = [];
        $view['doc_app'] = $this->uiMenuService->findAppByName('documentation');
        $view['doc_sections'] = $this->uiMenuService->getAppSections('documentation', 'typography');
        $view['section'] = $this->uiMenuService->findAppSection('documentation', 'typography');

        return $this->render('typography/shell_doc.html.twig', $view);
    }

    private function redirectToTypography(Request $request): Response
    {
        $params = ['name' => 'documentation', 'section' => 'typography'];

        return $this->redirectToRoute('app_section', $params, Response::HTTP_MOVED_PERMANENTLY);
    }
}
