<?php

namespace App\Controller;

use App\Service\ProjectReadmeService;
use App\Service\ConnectorService;
use App\Service\StarterEnvService;
use App\Service\StarterMountService;
use App\Service\StarterEnvironmentService;
use App\Service\StarterLogJournalService;
use App\Service\StarterToggleService;
use App\Service\UiMenuService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AppController extends AbstractController
{
    private UiMenuService $uiMenuService;

    private StarterEnvironmentService $starterEnvironmentService;

    private StarterToggleService $starterToggleService;

    private StarterLogJournalService $starterLogJournalService;

    private ProjectReadmeService $projectReadmeService;

    private StarterEnvService $starterEnvService;

    private StarterMountService $starterMountService;

    private ConnectorService $connectorService;

    public function __construct(
        UiMenuService $uiMenuService,
        StarterEnvironmentService $starterEnvironmentService,
        StarterToggleService $starterToggleService,
        StarterLogJournalService $starterLogJournalService,
        ProjectReadmeService $projectReadmeService,
        StarterEnvService $starterEnvService,
        StarterMountService $starterMountService,
        ConnectorService $connectorService
    ) {
        $this->uiMenuService = $uiMenuService;
        $this->starterEnvironmentService = $starterEnvironmentService;
        $this->starterToggleService = $starterToggleService;
        $this->starterLogJournalService = $starterLogJournalService;
        $this->projectReadmeService = $projectReadmeService;
        $this->starterEnvService = $starterEnvService;
        $this->starterMountService = $starterMountService;
        $this->connectorService = $connectorService;
    }

    #[Route('/app/{name}/{section}', name: 'app_section', requirements: ['name' => '[a-z0-9_-]+', 'section' => '[a-z0-9_-]+'], methods: ['GET', 'POST'])]
    public function section(string $name, string $section, Request $request): Response
    {
        if ($name === 'documentation' && $section === 'typography') {
            return $this->forward('App\Controller\TypographyController::renderPage', [
                'request' => $request,
            ]);
        }

        if ($name === 'connector') {
            return $this->redirectToRoute('app_show', ['name' => 'connector'], Response::HTTP_MOVED_PERMANENTLY);
        }

        $app = $this->uiMenuService->findAppByName($name);
        if ($app === null) {
            throw $this->createNotFoundException('Приложение не найдено.');
        }

        $sectionMeta = $this->uiMenuService->findAppSection($name, $section);
        if ($sectionMeta === null) {
            throw $this->createNotFoundException('Раздел не найден.');
        }

        $template = 'app/documentation/sections/' . $section . '.html.twig';

        if (!$this->container->get('twig')->getLoader()->exists($template)) {
            throw $this->createNotFoundException('Раздел не найден.');
        }

        $view = [
            'app' => $app,
            'doc_app' => $app,
            'section' => $sectionMeta,
            'doc_sections' => $this->uiMenuService->getAppSections($name),
        ];

        if ($name === 'documentation' && $section === 'readme') {
            $view['readme_body'] = $this->projectReadmeService->getContent();
            $view['readme_empty'] = $this->projectReadmeService->isEmpty();
            $view['readme_path_label'] = $this->projectReadmeService->getDisplayPath();
        }

        return $this->render($template, $view);
    }

    #[Route('/app/{name}', name: 'app_show', requirements: ['name' => '[a-z0-9_-]+'], methods: ['GET', 'POST'])]
    public function show(string $name, Request $request): Response
    {
        $app = $this->uiMenuService->findAppByName($name);
        if ($app === null) {
            throw $this->createNotFoundException('Приложение не найдено.');
        }

        $defaultSection = $this->uiMenuService->getDefaultAppSectionSlug($name);
        if ($defaultSection !== null) {
            return $this->redirectToRoute('app_section', [
                'name' => $name,
                'section' => $defaultSection,
            ]);
        }

        if ($name === 'typography') {
            $params = ['name' => 'documentation', 'section' => 'typography'];
            if ($request->query->getBoolean('compact')) {
                $params['compact'] = 1;
            }

            return $this->redirectToRoute('app_section', $params, Response::HTTP_MOVED_PERMANENTLY);
        }

        if ($name === 'layouts') {
            return $this->redirectToRoute('app_section', [
                'name' => 'documentation',
                'section' => 'layouts',
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        if ($name === 'symfony_ai') {
            return $this->render('app/symfony_ai/index.html.twig', [
                'app' => $app,
            ]);
        }

        if ($name === 'connector') {
            $flashBag = $request->getSession()->getFlashBag();

            return $this->render('app/connector/index.html.twig', [
                'app' => $app,
                'connector_connection' => $this->connectorService->buildCurrentConnection(),
                'connector_tokens' => $this->connectorService->listTokens(),
                'connector_tokens_path' => $this->connectorService->getTokensFileDisplayPath(),
                'connector_flashes' => [
                    'success' => $flashBag->get('success'),
                    'error' => $flashBag->get('error'),
                ],
            ]);
        }

        if ($name === 'starter') {
            $flashBag = $request->getSession()->getFlashBag();

            return $this->render('app/starter/index.html.twig', [
                'app' => $app,
                'environment' => $this->starterEnvironmentService->buildReport(),
                'starter_env_groups' => $this->starterEnvService->listGroups(),
                'starter_env_values_path' => $this->starterEnvService->getValuesFileDisplayPath(),
                'starter_mounts' => $this->starterMountService->listMounts(),
                'starter_mount_ids' => $this->buildStarterMountIds(),
                'starter_mount_presets' => $this->starterMountService->listPresetsForPlatform(
                    $this->starterMountService->resolveHostPlatform()
                ),
                'starter_mount_platform' => $this->starterMountService->resolveHostPlatform(),
                'starter_mounts_config_path' => $this->starterMountService->getMountsFileDisplayPath(),
                'starter_mounts_override_path' => $this->starterMountService->getOverrideSnippetPath(),
                'starter_mounts_override_yaml' => $this->starterMountService->buildOverrideYaml(),
                'starter_packages' => $this->starterToggleService->listPackages(),
                'starter_services' => $this->starterToggleService->listServices(),
                'starter_log_journal' => $this->starterLogJournalService->buildJournal(),
                'starter_flashes' => [
                    'success' => $flashBag->get('success'),
                    'error' => $flashBag->get('error'),
                ],
            ]);
        }

        return $this->render('app/show.html.twig', [
            'app' => $app,
        ]);
    }

    /**
     * @return list<string>
     */
    private function buildStarterMountIds(): array
    {
        $ids = [];
        foreach ($this->starterMountService->listMounts() as $mount) {
            $ids[] = $mount['id'];
        }

        return $ids;
    }
}
