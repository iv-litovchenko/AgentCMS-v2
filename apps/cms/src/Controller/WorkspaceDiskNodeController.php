<?php

namespace App\Controller;

use App\Service\AcmsManifestPropertyBridge;
use App\Service\TypeSchemaRegistry;
use App\Service\TypeViewResolver;
use App\Service\WorkspaceBreadcrumbBuilder;
use App\Service\WorkspaceNodeScanner;
use App\Service\WorkspaceService;
use App\Service\WorkspaceViewAssembler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/workspaces')]
class WorkspaceDiskNodeController extends AbstractController
{
    private WorkspaceService $workspaceService;

    private WorkspaceNodeScanner $workspaceNodeScanner;

    private TypeViewResolver $typeViewResolver;

    private WorkspaceViewAssembler $workspaceViewAssembler;

    private WorkspaceBreadcrumbBuilder $workspaceBreadcrumbBuilder;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private AcmsManifestPropertyBridge $acmsManifestPropertyBridge;

    public function __construct(
        WorkspaceService $workspaceService,
        WorkspaceNodeScanner $workspaceNodeScanner,
        TypeViewResolver $typeViewResolver,
        WorkspaceViewAssembler $workspaceViewAssembler,
        WorkspaceBreadcrumbBuilder $workspaceBreadcrumbBuilder,
        TypeSchemaRegistry $typeSchemaRegistry,
        AcmsManifestPropertyBridge $acmsManifestPropertyBridge
    ) {
        $this->workspaceService = $workspaceService;
        $this->workspaceNodeScanner = $workspaceNodeScanner;
        $this->typeViewResolver = $typeViewResolver;
        $this->workspaceViewAssembler = $workspaceViewAssembler;
        $this->workspaceBreadcrumbBuilder = $workspaceBreadcrumbBuilder;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->acmsManifestPropertyBridge = $acmsManifestPropertyBridge;
    }

    /** @var list<string> */
    private const RESERVED_DISK_PATH_PREFIXES = [
        'hubs',
        'module',
        'node',
        'hub',
        'reveal-path',
        'disk-node',
    ];

    #[Route('/{slug}/disk-node/{nodePath}', name: 'workspace_disk_node_legacy', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.*'], methods: ['GET'], priority: 12)]
    public function legacyRedirect(string $slug, string $nodePath): Response
    {
        return $this->redirectToRoute('workspace_disk_node_show', [
            'slug' => $slug,
            'nodePath' => $nodePath,
        ], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/{slug}/{nodePath}', name: 'workspace_disk_node_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.*'], methods: ['GET'], priority: 3)]
    public function show(string $slug, string $nodePath): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        if ($nodePath === '_') {
            $nodePath = '';
        }

        $nodePath = trim(str_replace('\\', '/', $nodePath), '/');
        $this->assertDiskPathAllowed($nodePath);
        $diskNode = $this->workspaceNodeScanner->readDiskNode($workspace['slug'], $nodePath);
        if ($diskNode === null) {
            throw $this->createNotFoundException('Узел не найден.');
        }

        $workspace['initials'] = $this->workspaceService->initialsFromName($workspace['name']);

        $typeId = '';
        $properties = $diskNode['properties'];
        $typeId = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-type');

        $hubFamily = $this->workspaceViewAssembler->resolveHubFamilyForDiskPath($nodePath, $workspace['slug']);
        $nodeLabel = $this->resolveNodeLabel($nodePath, $properties);
        $view = $this->typeViewResolver->resolveDiskNodeView($typeId, $workspace['slug']);

        $context = array_merge(
            $this->workspaceViewAssembler->buildViewContext($workspace),
            [
                'disk_node' => $diskNode,
                'disk_node_path' => $nodePath,
                'disk_node_label' => $nodeLabel,
                'disk_node_type_id' => $this->typeSchemaRegistry->publicTypeId($typeId),
                'disk_node_view_template' => $view['template'],
                'active_disk_node_path' => $nodePath,
                'active_hub_path' => '',
                'active_hub_family' => $hubFamily,
                'active_hub_family_page' => false,
                'active_module_slug' => '',
                'workspace_breadcrumbs' => $this->workspaceBreadcrumbBuilder->buildForDiskNode(
                    $workspace,
                    $nodePath,
                    $nodeLabel,
                    $hubFamily,
                    $typeId
                ),
                'workspace_toolbar' => $this->workspaceBreadcrumbBuilder->buildToolbarForDiskNode($workspace, $nodePath),
            ]
        );

        $action = $view['action'];
        if ($action !== '' && method_exists($this, $action)) {
            return $this->$action($context);
        }

        return $this->render('workspace/disk_node.html.twig', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function showDiskNodeTopicArea(array $context): Response
    {
        return $this->renderDiskNodeView($context);
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function renderDiskNodeView(array $context): Response
    {
        $template = 'workspace/disk_nodes/_default.html.twig';
        if (isset($context['disk_node_view_template']) && is_string($context['disk_node_view_template'])) {
            $template = $context['disk_node_view_template'];
        }

        return $this->render('workspace/disk_node.html.twig', array_merge($context, [
            'disk_node_view_template' => $template,
        ]));
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function resolveNodeLabel(string $nodePath, array $properties): string
    {
        $nodeLabel = $nodePath;
        if ($nodePath === '') {
            $nodeLabel = 'Корень';
        }
        $acmsName = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-name');
        if ($acmsName !== '') {
            $nodeLabel = $acmsName;
        } elseif (isset($properties['name']) && is_string($properties['name']) && $properties['name'] !== '') {
            $nodeLabel = $properties['name'];
        }

        return $nodeLabel;
    }

    private function assertDiskPathAllowed(string $nodePath): void
    {
        if ($nodePath === '') {
            return;
        }

        $slash = strpos($nodePath, '/');
        if ($slash === false) {
            $first = $nodePath;
        } else {
            $first = substr($nodePath, 0, $slash);
        }

        if ($first === '') {
            return;
        }

        if (in_array($first, self::RESERVED_DISK_PATH_PREFIXES, true)) {
            throw $this->createNotFoundException('Узел не найден.');
        }
    }
}
