<?php

namespace App\Controller;

use App\Form\VaultData;
use App\Form\VaultFormType;
use App\Service\TypeSchemaRegistry;
use App\Service\TypeViewResolver;
use App\Service\VaultBreadcrumbBuilder;
use App\Service\VaultViewAssembler;
use App\Service\VaultModuleRegistry;
use App\Service\VaultNodeCreator;
use App\Service\VaultRootFileCreator;
use App\Service\NodeDiskManifestRegistry;
use App\Service\VaultNodeScanner;
use App\Service\VaultService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VaultController extends AbstractController
{
    private VaultService $vaultService;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private VaultModuleRegistry $vaultModuleRegistry;

    private VaultNodeScanner $vaultNodeScanner;

    private VaultNodeCreator $vaultNodeCreator;

    private VaultRootFileCreator $vaultRootFileCreator;

    private NodeDiskManifestRegistry $nodeDiskManifestRegistry;

    private VaultViewAssembler $vaultViewAssembler;

    private TypeViewResolver $typeViewResolver;

    private VaultBreadcrumbBuilder $vaultBreadcrumbBuilder;

    public function __construct(
        VaultService $vaultService,
        TypeSchemaRegistry $typeSchemaRegistry,
        VaultModuleRegistry $vaultModuleRegistry,
        VaultNodeScanner $vaultNodeScanner,
        VaultNodeCreator $vaultNodeCreator,
        VaultRootFileCreator $vaultRootFileCreator,
        NodeDiskManifestRegistry $nodeDiskManifestRegistry,
        VaultViewAssembler $vaultViewAssembler,
        TypeViewResolver $typeViewResolver,
        VaultBreadcrumbBuilder $vaultBreadcrumbBuilder
    ) {
        $this->vaultService = $vaultService;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->vaultModuleRegistry = $vaultModuleRegistry;
        $this->vaultNodeScanner = $vaultNodeScanner;
        $this->vaultNodeCreator = $vaultNodeCreator;
        $this->vaultRootFileCreator = $vaultRootFileCreator;
        $this->nodeDiskManifestRegistry = $nodeDiskManifestRegistry;
        $this->vaultViewAssembler = $vaultViewAssembler;
        $this->typeViewResolver = $typeViewResolver;
        $this->vaultBreadcrumbBuilder = $vaultBreadcrumbBuilder;
    }

    #[Route('/storages', name: 'vault_index', methods: ['GET'], priority: 15)]
    public function index(): Response
    {
        return $this->render('vault/index.html.twig', [
            'storages' => $this->vaultService->listForDisplay(),
        ]);
    }

    #[Route('/avatars/{filename}', name: 'vault_avatar', requirements: ['filename' => '[a-zA-Z0-9._-]+'], methods: ['GET'], priority: 20)]
    public function avatar(string $filename): Response
    {
        $path = $this->vaultService->resolveAvatarPath($filename);
        if ($path === null) {
            throw $this->createNotFoundException('Аватар не найден.');
        }

        $response = new BinaryFileResponse($path);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    #[Route('/storages/new', name: 'vault_new', methods: ['GET', 'POST'], priority: 15)]
    public function create(Request $request): Response
    {
        $data = new VaultData();
        $form = $this->createForm(VaultFormType::class, $data, [
            'submit_label' => 'Создать',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->vaultService->create([
                    'name' => $data->name,
                    'slug' => $data->slug,
                    'location' => $data->location,
                    'sort_order' => $data->sortOrder,
                ], $data->avatarUpload);
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->render('vault/form.html.twig', [
                    'form' => $form->createView(),
                    'page_title' => 'Новое хранилище',
                ]);
            }

            $this->addFlash('success', 'Хранилище создано.');

            return $this->redirectToRoute('vault_index');
        }

        return $this->render('vault/form.html.twig', [
            'form' => $form->createView(),
            'page_title' => 'Новое хранилище',
        ]);
    }

    #[Route('/storages/{id}/edit', name: 'vault_edit', requirements: ['id' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET', 'POST'], priority: 15)]
    public function edit(string $id, Request $request): Response
    {
        $vault = $this->vaultService->findById($id);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $data = new VaultData();
        $data->name = $vault['name'];
        $data->slug = $vault['slug'];
        $data->location = $vault['location'];
        $data->sortOrder = $vault['sort_order'];

        $form = $this->createForm(VaultFormType::class, $data, [
            'submit_label' => 'Сохранить',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $updated = $this->vaultService->update($id, [
                    'name' => $data->name,
                    'slug' => $data->slug,
                    'location' => $data->location,
                    'sort_order' => $data->sortOrder,
                ], $data->avatarUpload);
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->render('vault/form.html.twig', [
                    'form' => $form->createView(),
                    'page_title' => 'Редактирование: ' . $vault['name'],
                    'current_avatar' => $vault['avatar'],
                ]);
            }

            if ($updated === null) {
                throw $this->createNotFoundException('Хранилище не найдено.');
            }

            $this->addFlash('success', 'Хранилище обновлено.');

            return $this->redirectToRoute('vault_show', ['slug' => $updated['slug']]);
        }

        return $this->render('vault/form.html.twig', [
            'form' => $form->createView(),
            'page_title' => 'Редактирование: ' . $vault['name'],
            'current_avatar' => $vault['avatar'],
        ]);
    }

    #[Route('/storages/{id}/delete', name: 'vault_delete', requirements: ['id' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['POST'], priority: 15)]
    #[Route('/vaults/{id}/delete', name: 'vault_delete_legacy', requirements: ['id' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['POST'], priority: 25)]
    public function delete(string $id, Request $request): Response
    {
        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('delete_vault_' . $id, $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $deleted = $this->vaultService->delete($id);
        if (!$deleted) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $this->addFlash('success', 'Хранилище удалено.');

        return $this->redirectToRoute('vault_index');
    }

    #[Route('/{slug}/root/create-root-file', name: 'vault_hub_create_root_file', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['POST'], priority: 9)]
    #[Route('/{slug}/hubs/root/create-root-file', name: 'vault_hub_create_root_file_legacy', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['POST'], priority: 10)]
    public function hubCreateRootFile(string $slug, Request $request): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('hub_create_root_file', $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $filename = trim($request->request->getString('root_file'));

        try {
            $created = $this->vaultRootFileCreator->createRootFile($vault['slug'], $filename);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirect(
                $this->generateUrl('vault_module_show', [
                    'slug' => $slug,
                    'module' => 'create',
                ]) . '?family=root'
            );
        }

        $this->addFlash('success', 'Файл ' . $created['file'] . ' создан.');

        return $this->redirectToRoute('vault_disk_node_show', [
            'slug' => $slug,
            'nodePath' => $created['disk_path'],
        ]);
    }

    #[Route('/{slug}/hubs/{family}/create-node', name: 'vault_hub_create_node_legacy', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'family' => '[a-z][a-z0-9]*'], methods: ['POST'], priority: 10)]
    public function hubCreateNodeLegacy(string $slug, string $family, Request $request): Response
    {
        return $this->hubCreateNode($slug, $request);
    }

    #[Route('/{slug}/{zone}/create-node', name: 'vault_hub_create_node', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'zone' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['POST'], priority: 8)]
    public function hubCreateNode(string $slug, Request $request): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $zone = $this->resolveHubUrlSegmentFromRequest($request);
        if ($zone === '') {
            throw $this->createNotFoundException('Зона hub не указана.');
        }

        $family = $this->typeSchemaRegistry->hubFamilyForUrlSegment($zone, $vault['slug']);
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
            $created = $this->vaultNodeCreator->createInHub(
                $vault['slug'],
                $family,
                $nodeTypeId,
                $nodeSlug,
                $nodeName,
                $nodeDescription
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirect(
                $this->generateUrl('vault_module_show', [
                    'slug' => $slug,
                    'module' => 'create',
                ]) . '?family=' . rawurlencode($family)
            );
        }

        $diskPath = $created['disk_path'];
        $nodePath = $diskPath !== '' ? $diskPath : '_';
        $this->addFlash('success', 'Узел создан.');

        return $this->redirectToRoute('vault_disk_node_show', [
            'slug' => $slug,
            'nodePath' => $nodePath,
        ]);
    }

    #[Route('/{slug}/hubs/{family}', name: 'vault_hub_family_legacy', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'family' => '[a-z][a-z0-9]*'], methods: ['GET'], priority: 12)]
    public function hubFamilyLegacyRedirect(string $slug, string $family): Response
    {
        $family = trim($family);
        $zone = $this->typeSchemaRegistry->hubUrlSegmentForFamily($family, $slug);

        return $this->redirectToRoute('vault_hub_family_show', [
            'slug' => $slug,
            'zone' => $zone,
        ], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/{slug}/{zone}', name: 'vault_hub_family_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'zone' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'], priority: 4)]
    public function hubFamilyShow(string $slug, Request $request): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $zone = $this->resolveHubUrlSegmentFromRequest($request);
        if ($zone === '') {
            throw $this->createNotFoundException('Семья hub не найдена.');
        }

        $zone = trim($zone);
        $family = $this->typeSchemaRegistry->hubFamilyForUrlSegment($zone, $vault['slug']);
        if ($family === null) {
            throw $this->createNotFoundException('Семья hub не найдена.');
        }

        $family = trim($family);
        $hubGroup = $this->typeSchemaRegistry->findHubTreeGroupByFamily($family, $vault['slug']);
        if ($hubGroup === null) {
            throw $this->createNotFoundException('Семья hub не найдена.');
        }

        $canonicalZone = $this->typeSchemaRegistry->hubUrlSegmentForFamily($family, $vault['slug']);
        if ($canonicalZone !== $zone) {
            return $this->redirectToRoute('vault_hub_family_show', [
                'slug' => $slug,
                'zone' => $canonicalZone,
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $hubTree = $this->vaultNodeScanner->attachDiskNodesToHubTree([$hubGroup], $vault['slug']);
        $hubGroup = $hubTree[0];

        $hubFolderForList = '';
        if (isset($hubGroup['folder']) && is_string($hubGroup['folder'])) {
            $hubFolderForList = trim($hubGroup['folder']);
        }
        $familyRootItems = [];
        if (isset($hubGroup['items']) && is_array($hubGroup['items'])) {
            $familyRootItems = $this->vaultNodeScanner->filterItemsToHubZoneRoots($hubGroup['items'], $hubFolderForList);
        }
        $hubGroup['items'] = $familyRootItems;

        $hubFolderEntries = [];
        $hubZoneFolderOnly = !$this->typeSchemaRegistry->hubAllowsSectionChildren($family, $vault['slug']);
        if ($hubZoneFolderOnly) {
            $hubFolder = '';
            if (isset($hubGroup['folder']) && is_string($hubGroup['folder'])) {
                $hubFolder = trim($hubGroup['folder']);
            }
            if ($hubFolder !== '' && $hubFolder !== '.') {
                $listing = $this->vaultNodeScanner->readDiskDirectoryListing($vault['slug'], $hubFolder);
                if ($listing !== null && isset($listing['entries']) && is_array($listing['entries'])) {
                    $hubFolderEntries = $listing['entries'];
                }
            }
        }

        $familyBase = $this->typeSchemaRegistry->resolveType('hubs/' . $family, $vault['slug']);
        $defaultNodeTypeId = $this->typeSchemaRegistry->resolveDefaultNodeTypeIdForHub($family, $vault['slug']);

        $rootCreatableFiles = [];
        $existingRootFiles = [];
        if ($family === 'root') {
            $rootCreatableFiles = $this->nodeDiskManifestRegistry->listRootCreatableFiles($vault['slug']);
            $existingRootFiles = $this->vaultNodeScanner->mapExistingRootCreatableFiles($vault['slug']);
        }

        if (isset($hubGroup['type_items']) && is_array($hubGroup['type_items'])) {
            foreach ($hubGroup['type_items'] as $idx => $typeItem) {
                $typeId = '';
                if (isset($typeItem['id']) && is_string($typeItem['id'])) {
                    $typeId = $typeItem['id'];
                }
                $hubGroup['type_items'][$idx]['family_view_template'] = $this->typeViewResolver->resolveFamilySectionTemplate(
                    $typeId,
                    $vault['slug']
                );
            }
        }

        $vault['initials'] = $this->vaultService->initialsFromName($vault['name']);

        return $this->render('vault/hub_family.html.twig', array_merge(
            $this->vaultViewAssembler->buildViewContext($vault),
            [
                'hub_group' => $hubGroup,
                'hub_folder_entries' => $hubFolderEntries,
                'hub_zone_folder_only' => $hubZoneFolderOnly,
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
                'vault_breadcrumbs' => $this->vaultBreadcrumbBuilder->buildForHubFamily($vault, $hubGroup),
                'vault_toolbar' => $this->vaultBreadcrumbBuilder->buildToolbarForHubFamily($vault, $hubGroup),
            ]
        ));
    }

    #[Route('/{slug}/reveal-path', name: 'vault_reveal_path', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['POST'], priority: 9)]
    public function revealPath(string $slug, Request $request): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('vault_reveal_path', $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        if (!isset($vault['location']) || $vault['location'] !== 'local') {
            $this->addFlash('error', 'Finder доступен только для локального хранилища.');

            return $this->redirectBack($request);
        }

        $relativePath = trim($request->request->getString('relative_path'));
        $ok = $this->vaultService->revealInFileManager($slug, $relativePath);
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

        return $this->redirectToRoute('vault_index');
    }

    #[Route('/{slug}/hub/{hub}', name: 'vault_hub_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'hub' => '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*'], methods: ['GET'], priority: 6)]
    public function hubShow(string $slug, string $hub): Response
    {
        return $this->redirectToRoute('vault_node_show', [
            'slug' => $slug,
            'node' => trim(str_replace('\\', '/', $hub), '/'),
        ], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/{slug}/node/{node}', name: 'vault_node_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'node' => '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*'], methods: ['GET'], priority: 6)]
    public function nodeShow(string $slug, string $node): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $node = trim(str_replace('\\', '/', $node), '/');
        $typeId = 'sections/' . $node;
        $hubType = $this->typeSchemaRegistry->resolveType($typeId, $vault['slug']);
        if ($hubType === null) {
            throw $this->createNotFoundException('Тип node не найден.');
        }

        $vault['initials'] = $this->vaultService->initialsFromName($vault['name']);

        $hubLabel = $typeId;
        if (isset($hubType['name']) && is_string($hubType['name']) && $hubType['name'] !== '') {
            $hubLabel = $hubType['name'];
        }

        $schemaView = $this->typeViewResolver->resolveSchemaNodeView($node, $vault['slug']);
        $viewTemplate = $schemaView['template'];

        $hubRootManifest = null;
        if ($node === 'root/root') {
            $hubRootManifest = $this->vaultService->readRootNodeManifest($vault['slug']);
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

        return $this->render('vault/hub.html.twig', array_merge(
            $this->vaultViewAssembler->buildViewContext($vault),
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

    #[Route('/{slug}/module/{module}', name: 'vault_module_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'module' => '[a-z][a-z0-9-]*'], methods: ['GET'], priority: 5)]
    public function moduleShow(string $slug, string $module, Request $request): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $moduleMeta = $this->vaultModuleRegistry->findModule($vault['slug'], $module);
        if ($moduleMeta === null) {
            throw $this->createNotFoundException('Модуль не найден.');
        }

        $vault['initials'] = $this->vaultService->initialsFromName($vault['name']);

        $searchQuery = trim($request->query->getString('q'));

        $extra = [
            'module' => $moduleMeta,
            'active_module_slug' => $moduleMeta['slug'],
            'active_hub_path' => '',
            'search_query' => $searchQuery,
            'vault_breadcrumbs' => $this->vaultBreadcrumbBuilder->buildForModule(
                $vault,
                $moduleMeta['label'],
                $moduleMeta['slug']
            ),
            'vault_toolbar' => ['reveal_enabled' => false, 'reveal_relative_path' => ''],
        ];

        if ($moduleMeta['slug'] === 'create') {
            $createFamily = trim($request->query->getString('family'));
            if ($createFamily !== '' && !$this->typeSchemaRegistry->hubAllowsSectionChildren($createFamily, $vault['slug'])) {
                $createZone = $this->typeSchemaRegistry->hubUrlSegmentForFamily($createFamily, $vault['slug']);

                return $this->redirectToRoute('vault_hub_family_show', [
                    'slug' => $slug,
                    'zone' => $createZone,
                ]);
            }
            $extra = array_merge($extra, $this->buildCreateWizardContext($vault['slug'], $request));
        }

        return $this->render('vault/module.html.twig', array_merge(
            $this->vaultViewAssembler->buildViewContext($vault),
            $extra
        ));
    }

    #[Route('/{slug}', name: 'vault_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'], priority: -10)]
    public function show(string $slug): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $vault['initials'] = $this->vaultService->initialsFromName($vault['name']);

        return $this->render('vault/show.html.twig', array_merge(
            $this->vaultViewAssembler->buildViewContext($vault),
            [
                'active_module_slug' => '',
                'active_hub_path' => '',
                'vault_readme_body' => $this->vaultService->readRootMarkdownBody($vault['slug'], 'README.md'),
                'vault_breadcrumbs' => $this->vaultBreadcrumbBuilder->buildForVaultOverview($vault),
                'vault_toolbar' => ['reveal_enabled' => false, 'reveal_relative_path' => ''],
            ]
        ));
    }

    #[Route('/vaults', name: 'vault_legacy_index', methods: ['GET'], priority: 25)]
    public function legacyVaultsIndex(): Response
    {
        return $this->redirectToRoute('vault_index', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/vaults/new', name: 'vault_legacy_new', methods: ['GET', 'POST'], priority: 25)]
    public function legacyVaultsNew(): Response
    {
        return $this->redirectToRoute('vault_new', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/vaults/{path}', name: 'vault_legacy_path', requirements: ['path' => '.+'], priority: 25)]
    public function legacyVaultsPath(string $path): Response
    {
        if (preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*/edit$#', $path)) {
            $id = preg_replace('#/edit$#', '', $path);
            return $this->redirectToRoute('vault_edit', ['id' => $id], Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->redirect('/' . $path, Response::HTTP_MOVED_PERMANENTLY);
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
    private function buildCreateWizardContext(string $vaultSlug, Request $request): array
    {
        $selectedFamily = trim($request->query->getString('family'));
        $groups = $this->typeSchemaRegistry->listHubTreeGroups($vaultSlug);
        $rootCreatableFiles = $this->nodeDiskManifestRegistry->listRootCreatableFiles($vaultSlug);

        $zones = [];
        foreach ($groups as $group) {
            $family = '';
            if (isset($group['family']) && is_string($group['family'])) {
                $family = $group['family'];
            }
            if ($family === '') {
                continue;
            }

            if (!$this->typeSchemaRegistry->hubAllowsSectionChildren($family, $vaultSlug)) {
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
                    $hint = 'Служебные файлы в корне хранилища';
                }
            } elseif (count($typeItems) > 0) {
                $canCreate = true;
                $hint = 'Секция с manifest.section.md';
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
            $wizardGroup = $this->typeSchemaRegistry->findHubTreeGroupByFamily($selectedFamily, $vaultSlug);
            if ($wizardGroup === null) {
                $selectedFamily = '';
            } else {
                if ($selectedFamily === 'root') {
                    $hubTree = $this->vaultNodeScanner->attachDiskNodesToHubTree([$wizardGroup], $vaultSlug);
                    $wizardGroup = $hubTree[0];
                    $existingRootFiles = $this->vaultNodeScanner->mapExistingRootCreatableFiles($vaultSlug);
                } else {
                    $defaultNodeTypeId = $this->typeSchemaRegistry->resolveDefaultNodeTypeIdForHub($selectedFamily, $vaultSlug);
                    $requestedType = trim($request->query->getString('type'));
                    if ($requestedType !== '' && $wizardGroup !== null && isset($wizardGroup['type_items']) && is_array($wizardGroup['type_items'])) {
                        foreach ($wizardGroup['type_items'] as $typeItem) {
                            $candidate = '';
                            if (isset($typeItem['id']) && is_string($typeItem['id']) && $typeItem['id'] !== '') {
                                $candidate = $typeItem['id'];
                            } elseif (isset($typeItem['hub_path']) && is_string($typeItem['hub_path']) && $typeItem['hub_path'] !== '') {
                                $candidate = 'sections/' . $typeItem['hub_path'];
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
            $wizardUrlZone = $this->typeSchemaRegistry->hubUrlSegmentForFamily($selectedFamily, $vaultSlug);
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
