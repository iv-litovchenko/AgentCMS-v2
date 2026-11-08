<?php

namespace App\Controller;

use App\Form\WorkspaceData;
use App\Form\WorkspaceFormType;
use App\Service\TypeSchemaRegistry;
use App\Service\TypeViewResolver;
use App\Service\WorkspaceBreadcrumbBuilder;
use App\Service\WorkspaceViewAssembler;
use App\Service\WorkspaceModuleRegistry;
use App\Service\WorkspaceNodeCreator;
use App\Service\WorkspaceRootFileCreator;
use App\Service\NodeDiskManifestRegistry;
use App\Service\WorkspaceNodeScanner;
use App\Service\WorkspaceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/workspaces')]
class WorkspaceController extends AbstractController
{
    private WorkspaceService $workspaceService;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private WorkspaceModuleRegistry $workspaceModuleRegistry;

    private WorkspaceNodeScanner $workspaceNodeScanner;

    private WorkspaceNodeCreator $workspaceNodeCreator;

    private WorkspaceRootFileCreator $workspaceRootFileCreator;

    private NodeDiskManifestRegistry $nodeDiskManifestRegistry;

    private WorkspaceViewAssembler $workspaceViewAssembler;

    private TypeViewResolver $typeViewResolver;

    private WorkspaceBreadcrumbBuilder $workspaceBreadcrumbBuilder;

    public function __construct(
        WorkspaceService $workspaceService,
        TypeSchemaRegistry $typeSchemaRegistry,
        WorkspaceModuleRegistry $workspaceModuleRegistry,
        WorkspaceNodeScanner $workspaceNodeScanner,
        WorkspaceNodeCreator $workspaceNodeCreator,
        WorkspaceRootFileCreator $workspaceRootFileCreator,
        NodeDiskManifestRegistry $nodeDiskManifestRegistry,
        WorkspaceViewAssembler $workspaceViewAssembler,
        TypeViewResolver $typeViewResolver,
        WorkspaceBreadcrumbBuilder $workspaceBreadcrumbBuilder
    ) {
        $this->workspaceService = $workspaceService;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->workspaceModuleRegistry = $workspaceModuleRegistry;
        $this->workspaceNodeScanner = $workspaceNodeScanner;
        $this->workspaceNodeCreator = $workspaceNodeCreator;
        $this->workspaceRootFileCreator = $workspaceRootFileCreator;
        $this->nodeDiskManifestRegistry = $nodeDiskManifestRegistry;
        $this->workspaceViewAssembler = $workspaceViewAssembler;
        $this->typeViewResolver = $typeViewResolver;
        $this->workspaceBreadcrumbBuilder = $workspaceBreadcrumbBuilder;
    }

    #[Route('', name: 'workspace_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('workspace/index.html.twig', [
            'storages' => $this->workspaceService->listForDisplay(),
        ]);
    }

    #[Route('/avatars/{filename}', name: 'workspace_avatar', requirements: ['filename' => '[a-zA-Z0-9._-]+'], methods: ['GET'], priority: 10)]
    public function avatar(string $filename): Response
    {
        $path = $this->workspaceService->resolveAvatarPath($filename);
        if ($path === null) {
            throw $this->createNotFoundException('Аватар не найден.');
        }

        $response = new BinaryFileResponse($path);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    #[Route('/new', name: 'workspace_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $data = new WorkspaceData();
        $form = $this->createForm(WorkspaceFormType::class, $data, [
            'submit_label' => 'Создать',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->workspaceService->create([
                    'name' => $data->name,
                    'slug' => $data->slug,
                    'location' => $data->location,
                    'sort_order' => $data->sortOrder,
                ], $data->avatarUpload);
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->render('workspace/form.html.twig', [
                    'form' => $form->createView(),
                    'page_title' => 'Новое хранилище',
                ]);
            }

            $this->addFlash('success', 'Хранилище создано.');

            return $this->redirectToRoute('workspace_index');
        }

        return $this->render('workspace/form.html.twig', [
            'form' => $form->createView(),
            'page_title' => 'Новое хранилище',
        ]);
    }

    #[Route('/{id}/edit', name: 'workspace_edit', requirements: ['id' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $workspace = $this->workspaceService->findById($id);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $data = new WorkspaceData();
        $data->name = $workspace['name'];
        $data->slug = $workspace['slug'];
        $data->location = $workspace['location'];
        $data->sortOrder = $workspace['sort_order'];

        $form = $this->createForm(WorkspaceFormType::class, $data, [
            'submit_label' => 'Сохранить',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $updated = $this->workspaceService->update($id, [
                    'name' => $data->name,
                    'slug' => $data->slug,
                    'location' => $data->location,
                    'sort_order' => $data->sortOrder,
                ], $data->avatarUpload);
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->render('workspace/form.html.twig', [
                    'form' => $form->createView(),
                    'page_title' => 'Редактирование: ' . $workspace['name'],
                    'current_avatar' => $workspace['avatar'],
                ]);
            }

            if ($updated === null) {
                throw $this->createNotFoundException('Хранилище не найдено.');
            }

            $this->addFlash('success', 'Хранилище обновлено.');

            return $this->redirectToRoute('workspace_show', ['slug' => $updated['slug']]);
        }

        return $this->render('workspace/form.html.twig', [
            'form' => $form->createView(),
            'page_title' => 'Редактирование: ' . $workspace['name'],
            'current_avatar' => $workspace['avatar'],
        ]);
    }

    #[Route('/{id}/delete', name: 'workspace_delete', requirements: ['id' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('delete_workspace_' . $id, $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $deleted = $this->workspaceService->delete($id);
        if (!$deleted) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $this->addFlash('success', 'Хранилище удалено.');

        return $this->redirectToRoute('workspace_index');
    }

    #[Route('/{slug}/root/create-root-file', name: 'workspace_hub_create_root_file', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['POST'], priority: 9)]
    #[Route('/{slug}/hubs/root/create-root-file', name: 'workspace_hub_create_root_file_legacy', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['POST'], priority: 10)]
    public function hubCreateRootFile(string $slug, Request $request): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('hub_create_root_file', $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $filename = trim($request->request->getString('root_file'));

        try {
            $created = $this->workspaceRootFileCreator->createRootFile($workspace['slug'], $filename);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirect(
                $this->generateUrl('workspace_module_show', [
                    'slug' => $slug,
                    'module' => 'create',
                ]) . '?family=root'
            );
        }

        $this->addFlash('success', 'Файл ' . $created['file'] . ' создан.');

        return $this->redirectToRoute('workspace_disk_node_show', [
            'slug' => $slug,
            'nodePath' => $created['disk_path'],
        ]);
    }

    #[Route('/{slug}/hubs/{family}/create-node', name: 'workspace_hub_create_node_legacy', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'family' => '[a-z][a-z0-9]*'], methods: ['POST'], priority: 10)]
    public function hubCreateNodeLegacy(string $slug, string $family, Request $request): Response
    {
        return $this->hubCreateNode($slug, $request);
    }

    #[Route('/{slug}/{zone}/create-node', name: 'workspace_hub_create_node', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'zone' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['POST'], priority: 8)]
    public function hubCreateNode(string $slug, Request $request): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $zone = $this->resolveHubUrlSegmentFromRequest($request);
        if ($zone === '') {
            throw $this->createNotFoundException('Зона hub не указана.');
        }

        $family = $this->typeSchemaRegistry->hubFamilyForUrlSegment($zone, $workspace['slug']);
        if ($family === null) {
            $family = trim($zone);
        } else {
            $family = trim($family);
        }
        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('hub_create_node_' . $family, $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $nodeTypeId = trim($request->request->getString('node_type'));
        $nodeSlug = trim($request->request->getString('node_slug'));
        $nodeName = trim($request->request->getString('node_name'));
        $nodeDescription = trim($request->request->getString('node_description'));

        try {
            $created = $this->workspaceNodeCreator->createInHub(
                $workspace['slug'],
                $family,
                $nodeTypeId,
                $nodeSlug,
                $nodeName,
                $nodeDescription
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirect(
                $this->generateUrl('workspace_module_show', [
                    'slug' => $slug,
                    'module' => 'create',
                ]) . '?family=' . rawurlencode($family)
            );
        }

        $diskPath = $created['disk_path'];
        $nodePath = $diskPath !== '' ? $diskPath : '_';
        $this->addFlash('success', 'Узел создан.');

        return $this->redirectToRoute('workspace_disk_node_show', [
            'slug' => $slug,
            'nodePath' => $nodePath,
        ]);
    }

    #[Route('/{slug}/hubs/{family}', name: 'workspace_hub_family_legacy', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'family' => '[a-z][a-z0-9]*'], methods: ['GET'], priority: 12)]
    public function hubFamilyLegacyRedirect(string $slug, string $family): Response
    {
        $family = trim($family);
        $zone = $this->typeSchemaRegistry->hubUrlSegmentForFamily($family, $slug);

        return $this->redirectToRoute('workspace_hub_family_show', [
            'slug' => $slug,
            'zone' => $zone,
        ], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/{slug}/{zone}', name: 'workspace_hub_family_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'zone' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'], priority: 4)]
    public function hubFamilyShow(string $slug, Request $request): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $zone = $this->resolveHubUrlSegmentFromRequest($request);
        if ($zone === '') {
            throw $this->createNotFoundException('Семья hub не найдена.');
        }

        $zone = trim($zone);
        $family = $this->typeSchemaRegistry->hubFamilyForUrlSegment($zone, $workspace['slug']);
        if ($family === null) {
            throw $this->createNotFoundException('Семья hub не найдена.');
        }

        $canonicalZone = $this->typeSchemaRegistry->hubUrlSegmentForFamily($family, $workspace['slug']);
        if ($canonicalZone !== $zone) {
            return $this->redirectToRoute('workspace_hub_family_show', [
                'slug' => $slug,
                'zone' => $canonicalZone,
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $family = trim($family);
        $hubGroup = $this->typeSchemaRegistry->findHubTreeGroupByFamily($family, $workspace['slug']);
        if ($hubGroup === null) {
            throw $this->createNotFoundException('Семья hub не найдена.');
        }

        $hubTree = $this->workspaceNodeScanner->attachDiskNodesToHubTree([$hubGroup], $workspace['slug']);
        $hubGroup = $hubTree[0];

        $familyBase = $this->typeSchemaRegistry->resolveType('hubs/' . $family, $workspace['slug']);
        $defaultNodeTypeId = $this->typeSchemaRegistry->resolveDefaultNodeTypeIdForHub($family, $workspace['slug']);

        $rootCreatableFiles = [];
        $existingRootFiles = [];
        if ($family === 'root') {
            $rootCreatableFiles = $this->nodeDiskManifestRegistry->listRootCreatableFiles($workspace['slug']);
            foreach ($hubGroup['items'] as $item) {
                if (isset($item['manifest_file']) && is_string($item['manifest_file'])) {
                    $existingRootFiles[$item['manifest_file']] = true;
                } elseif (isset($item['disk_path']) && is_string($item['disk_path']) && str_ends_with($item['disk_path'], '.md')) {
                    $existingRootFiles[$item['disk_path']] = true;
                }
            }
        }

        if (isset($hubGroup['type_items']) && is_array($hubGroup['type_items'])) {
            foreach ($hubGroup['type_items'] as $idx => $typeItem) {
                $typeId = '';
                if (isset($typeItem['id']) && is_string($typeItem['id'])) {
                    $typeId = $typeItem['id'];
                }
                $hubGroup['type_items'][$idx]['family_view_template'] = $this->typeViewResolver->resolveFamilySectionTemplate(
                    $typeId,
                    $workspace['slug']
                );
            }
        }

        $workspace['initials'] = $this->workspaceService->initialsFromName($workspace['name']);

        return $this->render('workspace/hub_family.html.twig', array_merge(
            $this->workspaceViewAssembler->buildViewContext($workspace),
            [
                'hub_group' => $hubGroup,
                'hub_family' => $family,
                'hub_url_zone' => $canonicalZone,
                'hub_family_base' => $familyBase,
                'default_node_type_id' => $defaultNodeTypeId,
                'root_creatable_files' => $rootCreatableFiles,
                'existing_root_files' => $existingRootFiles,
                'active_hub_path' => '',
                'active_hub_family' => $family,
                'active_hub_family_page' => true,
                'active_module_slug' => '',
                'workspace_breadcrumbs' => $this->workspaceBreadcrumbBuilder->buildForHubFamily($workspace, $hubGroup),
                'workspace_toolbar' => $this->workspaceBreadcrumbBuilder->buildToolbarForHubFamily($workspace, $hubGroup),
            ]
        ));
    }

    #[Route('/{slug}/reveal-path', name: 'workspace_reveal_path', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['POST'], priority: 9)]
    public function revealPath(string $slug, Request $request): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('workspace_reveal_path', $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        if (!isset($workspace['location']) || $workspace['location'] !== 'local') {
            $this->addFlash('error', 'Finder доступен только для локального хранилища.');

            return $this->redirectBack($request);
        }

        $relativePath = trim($request->request->getString('relative_path'));
        $ok = $this->workspaceService->revealInFileManager($slug, $relativePath);
        if (!$ok) {
            $this->addFlash('error', 'Не удалось открыть папку в Finder.');
        }

        return $this->redirectBack($request);
    }

    private function redirectBack(Request $request): Response
    {
        $target = trim($request->request->getString('_redirect'));
        if ($target !== '' && str_starts_with($target, '/')) {
            return $this->redirect($target);
        }

        $referer = $request->headers->get('referer');
        if (is_string($referer) && $referer !== '') {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('workspace_index');
    }

    #[Route('/{slug}/hub/{hub}', name: 'workspace_hub_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'hub' => '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*'], methods: ['GET'], priority: 6)]
    public function hubShow(string $slug, string $hub): Response
    {
        return $this->redirectToRoute('workspace_node_show', [
            'slug' => $slug,
            'node' => trim(str_replace('\\', '/', $hub), '/'),
        ], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/{slug}/node/{node}', name: 'workspace_node_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'node' => '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*'], methods: ['GET'], priority: 6)]
    public function nodeShow(string $slug, string $node): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $node = trim(str_replace('\\', '/', $node), '/');
        $typeId = 'nodes/' . $node;
        $hubType = $this->typeSchemaRegistry->resolveType($typeId, $workspace['slug']);
        if ($hubType === null) {
            throw $this->createNotFoundException('Тип node не найден.');
        }

        $workspace['initials'] = $this->workspaceService->initialsFromName($workspace['name']);

        $hubLabel = $typeId;
        if (isset($hubType['name']) && is_string($hubType['name']) && $hubType['name'] !== '') {
            $hubLabel = $hubType['name'];
        }

        $schemaView = $this->typeViewResolver->resolveSchemaNodeView($node, $workspace['slug']);
        $viewTemplate = $schemaView['template'];

        $hubRootManifest = null;
        if ($node === 'root/root') {
            $hubRootManifest = $this->workspaceService->readRootNodeManifest($workspace['slug']);
        }

        $hubFamily = '';
        if (isset($hubType['hub']) && is_string($hubType['hub']) && $hubType['hub'] !== '') {
            $hubFamily = $hubType['hub'];
        } else {
            $slashPos = strpos($node, '/');
            if ($slashPos !== false) {
                $hubFamily = substr($node, 0, $slashPos);
            } else {
                $hubFamily = $node;
            }
        }

        return $this->render('workspace/hub.html.twig', array_merge(
            $this->workspaceViewAssembler->buildViewContext($workspace),
            [
                'hub_type' => $hubType,
                'hub_id' => $typeId,
                'hub_path' => $node,
                'hub_label' => $hubLabel,
                'hub_view_template' => $viewTemplate,
                'hub_root_manifest' => $hubRootManifest,
                'active_hub_path' => $node,
                'active_hub_family' => $hubFamily,
                'active_hub_family_page' => false,
                'active_module_slug' => '',
            ]
        ));
    }

    #[Route('/{slug}/module/{module}', name: 'workspace_module_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'module' => '[a-z][a-z0-9-]*'], methods: ['GET'], priority: 5)]
    public function moduleShow(string $slug, string $module, Request $request): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $moduleMeta = $this->workspaceModuleRegistry->findModule($workspace['slug'], $module);
        if ($moduleMeta === null) {
            throw $this->createNotFoundException('Модуль не найден.');
        }

        $workspace['initials'] = $this->workspaceService->initialsFromName($workspace['name']);

        $searchQuery = trim($request->query->getString('q'));

        $extra = [
            'module' => $moduleMeta,
            'active_module_slug' => $moduleMeta['slug'],
            'active_hub_path' => '',
            'search_query' => $searchQuery,
            'workspace_breadcrumbs' => $this->workspaceBreadcrumbBuilder->buildForModule(
                $workspace,
                $moduleMeta['label'],
                $moduleMeta['slug']
            ),
            'workspace_toolbar' => ['reveal_enabled' => false, 'reveal_relative_path' => ''],
        ];

        if ($moduleMeta['slug'] === 'create') {
            $extra = array_merge($extra, $this->buildCreateWizardContext($workspace['slug'], $request));
        }

        return $this->render('workspace/module.html.twig', array_merge(
            $this->workspaceViewAssembler->buildViewContext($workspace),
            $extra
        ));
    }

    #[Route('/{slug}', name: 'workspace_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'], priority: -10)]
    public function show(string $slug): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $workspace['initials'] = $this->workspaceService->initialsFromName($workspace['name']);

        return $this->render('workspace/show.html.twig', array_merge(
            $this->workspaceViewAssembler->buildViewContext($workspace),
            [
                'active_module_slug' => '',
                'active_hub_path' => '',
                'workspace_breadcrumbs' => $this->workspaceBreadcrumbBuilder->buildForWorkspaceOverview($workspace),
                'workspace_toolbar' => ['reveal_enabled' => false, 'reveal_relative_path' => ''],
            ]
        ));
    }

    private function resolveHubUrlSegmentFromRequest(Request $request): string
    {
        $zone = $request->attributes->get('zone');
        if (is_string($zone) && trim($zone) !== '') {
            return trim($zone);
        }

        $family = $request->attributes->get('family');
        if (is_string($family) && trim($family) !== '') {
            return trim($family);
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreateWizardContext(string $workspaceSlug, Request $request): array
    {
        $selectedFamily = trim($request->query->getString('family'));
        $groups = $this->typeSchemaRegistry->listHubTreeGroups($workspaceSlug);
        $rootCreatableFiles = $this->nodeDiskManifestRegistry->listRootCreatableFiles($workspaceSlug);

        $zones = [];
        foreach ($groups as $group) {
            $family = '';
            if (isset($group['family']) && is_string($group['family'])) {
                $family = $group['family'];
            }
            if ($family === '') {
                continue;
            }

            $isRoot = $family === 'root';
            $typeItems = [];
            if (isset($group['type_items']) && is_array($group['type_items'])) {
                $typeItems = $group['type_items'];
            }

            $canCreate = false;
            $hint = '';
            if ($isRoot) {
                if (count($rootCreatableFiles) > 0) {
                    $canCreate = true;
                    $hint = 'Корневые markdown-файлы';
                }
            } elseif (count($typeItems) > 0) {
                $canCreate = true;
                $hint = 'Узел с manifest.node.md';
            }

            if (!$canCreate) {
                continue;
            }

            $label = $family;
            if (isset($group['label']) && is_string($group['label']) && $group['label'] !== '') {
                $label = $group['label'];
            }

            $folder = '';
            if (isset($group['folder']) && is_string($group['folder'])) {
                $folder = $group['folder'];
            }

            $treeIcon = 'folder';
            if (isset($group['tree_icon']) && is_string($group['tree_icon']) && $group['tree_icon'] !== '') {
                $treeIcon = $group['tree_icon'];
            }
            $treeIconClass = 'text-amber-500';
            if (isset($group['tree_icon_class']) && is_string($group['tree_icon_class']) && $group['tree_icon_class'] !== '') {
                $treeIconClass = $group['tree_icon_class'];
            }

            $urlSegment = $family;
            if (isset($group['url_segment']) && is_string($group['url_segment']) && $group['url_segment'] !== '') {
                $urlSegment = $group['url_segment'];
            } elseif ($folder !== '' && $folder !== '.') {
                $urlSegment = $folder;
            }

            $zones[] = [
                'family' => $family,
                'url_segment' => $urlSegment,
                'label' => $label,
                'folder' => $folder,
                'hint' => $hint,
                'tree_icon' => $treeIcon,
                'tree_icon_class' => $treeIconClass,
            ];
        }

        $wizardGroup = null;
        $defaultNodeTypeId = '';
        $existingRootFiles = [];

        if ($selectedFamily !== '') {
            $wizardGroup = $this->typeSchemaRegistry->findHubTreeGroupByFamily($selectedFamily, $workspaceSlug);
            if ($wizardGroup === null) {
                $selectedFamily = '';
            } else {
                if ($selectedFamily === 'root') {
                    $hubTree = $this->workspaceNodeScanner->attachDiskNodesToHubTree([$wizardGroup], $workspaceSlug);
                    $wizardGroup = $hubTree[0];
                    foreach ($wizardGroup['items'] as $item) {
                        if (isset($item['manifest_file']) && is_string($item['manifest_file'])) {
                            $existingRootFiles[$item['manifest_file']] = true;
                        } elseif (isset($item['disk_path']) && is_string($item['disk_path']) && str_ends_with($item['disk_path'], '.md')) {
                            $existingRootFiles[$item['disk_path']] = true;
                        }
                    }
                } else {
                    $defaultNodeTypeId = $this->typeSchemaRegistry->resolveDefaultNodeTypeIdForHub($selectedFamily, $workspaceSlug);
                    $requestedType = trim($request->query->getString('type'));
                    if ($requestedType !== '' && $wizardGroup !== null && isset($wizardGroup['type_items']) && is_array($wizardGroup['type_items'])) {
                        foreach ($wizardGroup['type_items'] as $typeItem) {
                            $candidate = '';
                            if (isset($typeItem['id']) && is_string($typeItem['id']) && $typeItem['id'] !== '') {
                                $candidate = $typeItem['id'];
                            } elseif (isset($typeItem['hub_path']) && is_string($typeItem['hub_path']) && $typeItem['hub_path'] !== '') {
                                $candidate = 'nodes/' . $typeItem['hub_path'];
                            }
                            if ($candidate === $requestedType) {
                                $defaultNodeTypeId = $candidate;
                                break;
                            }
                        }
                    }
                }
            }
        }

        $wizardUrlZone = '';
        if ($selectedFamily !== '') {
            $wizardUrlZone = $this->typeSchemaRegistry->hubUrlSegmentForFamily($selectedFamily, $workspaceSlug);
        }

        return [
            'create_wizard_zones' => $zones,
            'create_wizard_family' => $selectedFamily,
            'create_wizard_url_zone' => $wizardUrlZone,
            'create_wizard_group' => $wizardGroup,
            'default_node_type_id' => $defaultNodeTypeId,
            'root_creatable_files' => $rootCreatableFiles,
            'existing_root_files' => $existingRootFiles,
        ];
    }

}
