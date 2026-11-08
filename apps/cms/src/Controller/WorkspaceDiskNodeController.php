<?php

namespace App\Controller;

use App\Service\AcmsManifestPropertyBridge;
use App\Service\TypeSchemaRegistry;
use App\Service\TypeViewResolver;
use App\Service\WorkspaceBreadcrumbBuilder;
use App\Service\WorkspaceDiskNodeEditService;
use App\Service\WorkspaceNodeCreator;
use App\Service\WorkspaceNodeScanner;
use App\Service\WorkspaceNodeStorage;
use App\Service\WorkspaceService;
use App\Service\WorkspaceViewAssembler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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

    private WorkspaceDiskNodeEditService $workspaceDiskNodeEditService;

    private WorkspaceNodeCreator $workspaceNodeCreator;

    public function __construct(
        WorkspaceService $workspaceService,
        WorkspaceNodeScanner $workspaceNodeScanner,
        TypeViewResolver $typeViewResolver,
        WorkspaceViewAssembler $workspaceViewAssembler,
        WorkspaceBreadcrumbBuilder $workspaceBreadcrumbBuilder,
        TypeSchemaRegistry $typeSchemaRegistry,
        AcmsManifestPropertyBridge $acmsManifestPropertyBridge,
        WorkspaceDiskNodeEditService $workspaceDiskNodeEditService,
        WorkspaceNodeCreator $workspaceNodeCreator
    ) {
        $this->workspaceService = $workspaceService;
        $this->workspaceNodeScanner = $workspaceNodeScanner;
        $this->typeViewResolver = $typeViewResolver;
        $this->workspaceViewAssembler = $workspaceViewAssembler;
        $this->workspaceBreadcrumbBuilder = $workspaceBreadcrumbBuilder;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->acmsManifestPropertyBridge = $acmsManifestPropertyBridge;
        $this->workspaceDiskNodeEditService = $workspaceDiskNodeEditService;
        $this->workspaceNodeCreator = $workspaceNodeCreator;
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

    #[Route('/{slug}/{nodePath}', name: 'workspace_disk_node_edit', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.+~edit'], methods: ['GET', 'POST'], priority: 4)]
    public function edit(string $slug, string $nodePath, Request $request): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $diskPath = $this->stripEditSuffix($nodePath);
        $this->assertDiskPathAllowed($diskPath);

        $diskNode = $this->workspaceNodeScanner->readDiskNode($workspace['slug'], $diskPath);
        if ($diskNode === null) {
            throw $this->createNotFoundException('Узел не найден.');
        }

        $workspace['initials'] = $this->workspaceService->initialsFromName($workspace['name']);

        $editMode = $this->workspaceDiskNodeEditService->resolveEditMode($diskNode);
        $csrfId = $this->workspaceDiskNodeEditService->resolveCsrfTokenId($diskPath);

        $properties = $diskNode['properties'];
        $typeId = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-type');
        $hubFamily = $this->workspaceViewAssembler->resolveHubFamilyForDiskPath($diskPath, $workspace['slug']);
        $nodeLabel = $this->resolveNodeLabel($diskPath, $properties);

        $formName = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-name');
        if ($formName === '' && isset($properties['name']) && is_string($properties['name'])) {
            $formName = $properties['name'];
        }
        $formDescription = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-description');
        if ($formDescription === '' && isset($properties['description']) && is_string($properties['description'])) {
            $formDescription = $properties['description'];
        }
        $formBody = $diskNode['body'];
        if (!is_string($formBody)) {
            $formBody = '';
        }

        if ($request->isMethod('POST')) {
            $token = $request->request->getString('_token');
            if (!$this->isCsrfTokenValid($csrfId, $token)) {
                throw $this->createAccessDeniedException('Неверный CSRF-токен.');
            }

            $postName = trim($request->request->getString('disk_node_name'));
            $postDescription = trim($request->request->getString('disk_node_description'));
            $postBody = $request->request->getString('disk_node_body');
            $postRaw = $request->request->getString('disk_node_raw');

            try {
                $this->workspaceDiskNodeEditService->save(
                    $workspace['slug'],
                    $diskPath,
                    $diskNode,
                    $editMode,
                    $postName,
                    $postDescription,
                    $postBody,
                    $postRaw
                );
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirect($request->getRequestUri());
            }

            $this->addFlash('success', 'Сохранено.');

            return $this->redirectToRoute('workspace_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $diskPath !== '' ? $diskPath : '_',
            ]);
        }

        $crumbs = $this->workspaceBreadcrumbBuilder->buildForDiskNode(
            $workspace,
            $diskPath,
            $nodeLabel,
            $hubFamily,
            $typeId
        );
        if (count($crumbs) > 0) {
            $lastIndex = count($crumbs) - 1;
            $crumbs[$lastIndex]['current'] = false;
        }
        $crumbs[] = [
            'label' => 'Редактирование',
            'route' => '',
            'route_params' => [],
            'current' => true,
        ];

        return $this->render('workspace/disk_node_edit.html.twig', array_merge(
            $this->workspaceViewAssembler->buildViewContext($workspace),
            [
                'disk_node' => $diskNode,
                'disk_node_path' => $diskPath,
                'disk_node_label' => $nodeLabel,
                'disk_node_type_id' => $this->typeSchemaRegistry->publicTypeId($typeId),
                'disk_node_edit_mode' => $editMode,
                'disk_node_edit_csrf_id' => $csrfId,
                'disk_node_form_name' => $formName,
                'disk_node_form_description' => $formDescription,
                'disk_node_form_body' => $formBody,
                'disk_node_form_raw' => $this->resolveRawFileContents($workspace['slug'], $diskPath, $diskNode, $editMode),
                'active_disk_node_path' => $diskPath,
                'active_hub_path' => '',
                'active_hub_family' => $hubFamily,
                'active_hub_family_page' => false,
                'active_module_slug' => '',
                'workspace_breadcrumbs' => $crumbs,
                'workspace_toolbar' => $this->workspaceBreadcrumbBuilder->buildToolbarForDiskNode($workspace, $diskPath),
            ]
        ));
    }

    #[Route('/{slug}/{nodePath}', name: 'workspace_disk_single_file_create', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.+~create-single-file'], methods: ['POST'], priority: 4)]
    public function createSingleFileSlot(string $slug, string $nodePath, Request $request): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $fileDiskPath = $this->stripCreateSingleFileSuffix($nodePath);
        $this->assertDiskPathAllowed($fileDiskPath);

        $csrfId = 'disk_single_file_create_' . md5($fileDiskPath);
        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid($csrfId, $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $split = $this->splitNodeAndRelativeFilePath($fileDiskPath);
        $nodeDiskPath = $split['node_disk_path'];
        $relativePath = $split['relative_path'];

        $ownerNode = $this->workspaceNodeScanner->readDiskNode($workspace['slug'], $nodeDiskPath);
        if ($ownerNode === null) {
            throw $this->createNotFoundException('Узел не найден.');
        }

        if ($this->workspaceNodeScanner->readFileRelativeToDiskNode($workspace['slug'], $nodeDiskPath, $relativePath) !== null) {
            throw $this->createNotFoundException('Файл уже существует.');
        }

        $typeId = $this->acmsManifestPropertyBridge->stringProperty($ownerNode['properties'], 'acms-type');

        try {
            $this->workspaceNodeCreator->bootstrapSingleFileSlot(
                $workspace['slug'],
                $nodeDiskPath,
                $relativePath,
                $typeId
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('workspace_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $nodeDiskPath !== '' ? $nodeDiskPath : '_',
            ]);
        } catch (\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('workspace_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $nodeDiskPath !== '' ? $nodeDiskPath : '_',
            ]);
        }

        $this->addFlash('success', 'Файл слота создан.');

        return $this->redirectToRoute('workspace_disk_node_show', [
            'slug' => $slug,
            'nodePath' => $nodeDiskPath !== '' ? $nodeDiskPath : '_',
        ]);
    }

    #[Route('/{slug}/{nodePath}', name: 'workspace_disk_slot_create', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.+~create-slot'], methods: ['POST'], priority: 4)]
    public function createStorageSlot(string $slug, string $nodePath, Request $request): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $slotDiskPath = $this->stripCreateSlotSuffix($nodePath);
        $this->assertDiskPathAllowed($slotDiskPath);

        $csrfId = 'disk_slot_create_' . md5($slotDiskPath);
        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid($csrfId, $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $missingSlot = $this->workspaceNodeScanner->resolveMissingStorageSlot($workspace['slug'], $slotDiskPath);
        if ($missingSlot === null) {
            throw $this->createNotFoundException('Слот не найден или уже создан.');
        }

        $nodeDiskPath = $missingSlot['node_disk_path'];
        $slotFolder = $missingSlot['slot_folder'];
        $ownerNode = $this->workspaceNodeScanner->readDiskNode($workspace['slug'], $nodeDiskPath);
        if ($ownerNode === null) {
            throw $this->createNotFoundException('Узел не найден.');
        }

        $typeId = $this->acmsManifestPropertyBridge->stringProperty($ownerNode['properties'], 'acms-type');

        try {
            $this->workspaceNodeCreator->bootstrapStorageSlotDirectory(
                $workspace['slug'],
                $nodeDiskPath,
                $slotFolder,
                $typeId
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('workspace_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $slotDiskPath,
            ]);
        } catch (\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('workspace_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $slotDiskPath,
            ]);
        }

        $this->addFlash('success', 'Папка слота создана.');

        return $this->redirectToRoute('workspace_disk_node_show', [
            'slug' => $slug,
            'nodePath' => $slotDiskPath,
        ]);
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
        if (str_ends_with($nodePath, '~edit')) {
            throw $this->createNotFoundException('Узел не найден.');
        }
        $this->assertDiskPathAllowed($nodePath);
        $diskNode = $this->workspaceNodeScanner->readDiskNode($workspace['slug'], $nodePath);
        if ($diskNode === null) {
            $folderListing = $this->workspaceNodeScanner->readDiskDirectoryListing($workspace['slug'], $nodePath);
            if ($folderListing === null) {
                $missingSlot = $this->workspaceNodeScanner->resolveMissingStorageSlot($workspace['slug'], $nodePath);
                if ($missingSlot === null) {
                    throw $this->createNotFoundException('Узел не найден.');
                }

                return $this->renderDiskSlotMissing($workspace, $missingSlot);
            }

            return $this->renderDiskFolder($workspace, $nodePath, $folderListing);
        }

        $workspace['initials'] = $this->workspaceService->initialsFromName($workspace['name']);

        $typeId = '';
        $properties = $diskNode['properties'];
        $typeId = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-type');

        $hubFamily = $this->workspaceViewAssembler->resolveHubFamilyForDiskPath($nodePath, $workspace['slug']);
        $nodeLabel = $this->resolveNodeLabel($nodePath, $properties);
        $view = $this->typeViewResolver->resolveDiskNodeView($typeId, $workspace['slug']);
        $slotPartition = $this->partitionDiskNodeStorageSlots($workspace['slug'], $nodePath, $typeId);

        $context = array_merge(
            $this->workspaceViewAssembler->buildViewContext($workspace),
            $slotPartition,
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
     * @param array<string, mixed> $workspace
     * @param array{node_disk_path: string, slot_disk_path: string, slot_folder: string, slot: array<string, mixed>} $missingSlot
     */
    private function renderDiskSlotMissing(array $workspace, array $missingSlot): Response
    {
        $workspace['initials'] = $this->workspaceService->initialsFromName($workspace['name']);

        $nodeDiskPath = $missingSlot['node_disk_path'];
        $slotDiskPath = $missingSlot['slot_disk_path'];
        $slot = $missingSlot['slot'];

        $hubFamily = $this->workspaceViewAssembler->resolveHubFamilyForDiskPath($nodeDiskPath, $workspace['slug']);
        $nodeLabel = $nodeDiskPath;
        $ownerNode = $this->workspaceNodeScanner->readDiskNode($workspace['slug'], $nodeDiskPath);
        if ($ownerNode !== null) {
            $nodeLabel = $this->resolveNodeLabel($nodeDiskPath, $ownerNode['properties']);
        }

        $slotName = $slotDiskPath;
        if (isset($slot['name']) && is_string($slot['name'])) {
            $slotName = $slot['name'];
        }

        $csrfId = 'disk_slot_create_' . md5($slotDiskPath);

        return $this->render('workspace/disk_slot_missing.html.twig', array_merge(
            $this->workspaceViewAssembler->buildViewContext($workspace),
            [
                'disk_slot_path' => $slotDiskPath,
                'disk_slot_name' => $slotName,
                'disk_slot_meta' => $slot,
                'disk_slot_csrf_id' => $csrfId,
                'disk_node_path' => $nodeDiskPath,
                'active_disk_node_path' => $slotDiskPath,
                'active_hub_path' => '',
                'active_hub_family' => $hubFamily,
                'active_hub_family_page' => false,
                'active_module_slug' => '',
                'workspace_breadcrumbs' => $this->workspaceBreadcrumbBuilder->buildForDiskFolder(
                    $workspace,
                    $slotDiskPath,
                    $nodeDiskPath,
                    $nodeLabel,
                    $hubFamily
                ),
                'workspace_toolbar' => $this->workspaceBreadcrumbBuilder->buildToolbarForDiskNode($workspace, $slotDiskPath),
            ]
        ));
    }

    /**
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $folderListing
     */
    private function renderDiskFolder(array $workspace, string $folderDiskPath, array $folderListing): Response
    {
        $workspace['initials'] = $this->workspaceService->initialsFromName($workspace['name']);

        $split = WorkspaceNodeStorage::splitFolderDiskPath($folderDiskPath);
        $nodeDiskPath = $split['node_disk_path'];

        $nodeLabel = $folderDiskPath;
        $hubFamily = $this->workspaceViewAssembler->resolveHubFamilyForDiskPath($nodeDiskPath, $workspace['slug']);

        if ($nodeDiskPath !== '') {
            $ownerNode = $this->workspaceNodeScanner->readDiskNode($workspace['slug'], $nodeDiskPath);
            if ($ownerNode !== null) {
                $nodeLabel = $this->resolveNodeLabel($nodeDiskPath, $ownerNode['properties']);
            }
        } elseif ($folderDiskPath === '') {
            $nodeLabel = 'Корень';
        }

        $folderLabel = $folderListing['label'];
        if (!is_string($folderLabel) || $folderLabel === '') {
            $folderLabel = $folderDiskPath;
        }

        $entries = [];
        if (isset($folderListing['entries']) && is_array($folderListing['entries'])) {
            $entries = $folderListing['entries'];
        }

        $parentDiskPath = '';
        if (isset($folderListing['parent_disk_path']) && is_string($folderListing['parent_disk_path'])) {
            $parentDiskPath = $folderListing['parent_disk_path'];
        }

        return $this->render('workspace/disk_folder.html.twig', array_merge(
            $this->workspaceViewAssembler->buildViewContext($workspace),
            [
                'disk_folder_path' => $folderDiskPath,
                'disk_folder_label' => $folderLabel,
                'disk_folder_parent_path' => $parentDiskPath,
                'disk_folder_entries' => $entries,
                'disk_node_path' => $nodeDiskPath,
                'active_disk_node_path' => $folderDiskPath,
                'active_hub_path' => '',
                'active_hub_family' => $hubFamily,
                'active_hub_family_page' => false,
                'active_module_slug' => '',
                'workspace_breadcrumbs' => $this->workspaceBreadcrumbBuilder->buildForDiskFolder(
                    $workspace,
                    $folderDiskPath,
                    $nodeDiskPath,
                    $nodeLabel,
                    $hubFamily
                ),
                'workspace_toolbar' => $this->workspaceBreadcrumbBuilder->buildToolbarForDiskNode($workspace, $folderDiskPath),
            ]
        ));
    }

    /**
     * @return array{disk_node_storage_slots: list<array<string, mixed>>, disk_node_single_file_slots: list<array<string, mixed>>}
     */
    private function partitionDiskNodeStorageSlots(string $workspaceSlug, string $diskPath, string $typeId): array
    {
        $all = $this->typeSchemaRegistry->listStorageSlotsForNodeType($typeId, $workspaceSlug);
        $folderSlots = [];
        $singleFileSlots = [];

        foreach ($all as $slot) {
            $isSingle = false;
            if (isset($slot['single_file']) && $slot['single_file'] === true) {
                $isSingle = true;
            }

            if ($isSingle) {
                $relativePath = '';
                if (isset($slot['relative_path']) && is_string($slot['relative_path'])) {
                    $relativePath = $slot['relative_path'];
                }
                $content = $this->workspaceNodeScanner->readFileRelativeToDiskNode($workspaceSlug, $diskPath, $relativePath);
                $slot['content'] = '';
                $slot['file_exists'] = false;
                if ($content !== null) {
                    $slot['content'] = $content;
                    $slot['file_exists'] = true;
                }
                $fileDiskPath = $diskPath;
                if ($relativePath !== '') {
                    if ($fileDiskPath !== '') {
                        $fileDiskPath = $fileDiskPath . '/' . $relativePath;
                    } else {
                        $fileDiskPath = $relativePath;
                    }
                }
                $slot['file_disk_path'] = $fileDiskPath;
                $slot['create_csrf_id'] = 'disk_single_file_create_' . md5($fileDiskPath);
                $singleFileSlots[] = $slot;
            } else {
                $relativeDir = '';
                if (isset($slot['display_path']) && is_string($slot['display_path'])) {
                    $relativeDir = rtrim($slot['display_path'], '/');
                }
                $slotDiskPath = $diskPath;
                if ($slotDiskPath !== '') {
                    $slotDiskPath = $slotDiskPath . '/' . $relativeDir;
                } else {
                    $slotDiskPath = $relativeDir;
                }
                $slot['exists_on_disk'] = $this->workspaceNodeScanner->resolveAbsoluteDiskPath($workspaceSlug, $slotDiskPath) !== null;
                $slot['slot_disk_path'] = $slotDiskPath;
                $slot['create_csrf_id'] = 'disk_slot_create_' . md5($slotDiskPath);
                $slot['entry_count'] = $this->workspaceNodeScanner->countFilesInDiskNodeSubtree(
                    $workspaceSlug,
                    $diskPath,
                    $relativeDir
                );
                $folderSlots[] = $slot;
            }
        }

        return [
            'disk_node_storage_slots' => $folderSlots,
            'disk_node_single_file_slots' => $singleFileSlots,
        ];
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

    private function stripEditSuffix(string $nodePath): string
    {
        $suffix = '~edit';
        if (!str_ends_with($nodePath, $suffix)) {
            throw $this->createNotFoundException('Узел не найден.');
        }

        $diskPath = substr($nodePath, 0, -strlen($suffix));
        return trim(str_replace('\\', '/', $diskPath), '/');
    }

    private function stripCreateSlotSuffix(string $nodePath): string
    {
        $suffix = '~create-slot';
        if (!str_ends_with($nodePath, $suffix)) {
            throw $this->createNotFoundException('Слот не найден.');
        }

        $diskPath = substr($nodePath, 0, -strlen($suffix));
        return trim(str_replace('\\', '/', $diskPath), '/');
    }

    private function stripCreateSingleFileSuffix(string $nodePath): string
    {
        $suffix = '~create-single-file';
        if (!str_ends_with($nodePath, $suffix)) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        $diskPath = substr($nodePath, 0, -strlen($suffix));
        return trim(str_replace('\\', '/', $diskPath), '/');
    }

    /**
     * @return array{node_disk_path: string, relative_path: string}
     */
    private function splitNodeAndRelativeFilePath(string $fileDiskPath): array
    {
        $fileDiskPath = trim(str_replace('\\', '/', $fileDiskPath), '/');
        $slash = strrpos($fileDiskPath, '/');
        if ($slash === false) {
            return [
                'node_disk_path' => '',
                'relative_path' => $fileDiskPath,
            ];
        }

        return [
            'node_disk_path' => substr($fileDiskPath, 0, $slash),
            'relative_path' => substr($fileDiskPath, $slash + 1),
        ];
    }

    /**
     * @param array<string, mixed> $diskNode
     */
    private function resolveRawFileContents(string $workspaceSlug, string $diskPath, array $diskNode, string $editMode): string
    {
        if ($editMode !== WorkspaceDiskNodeEditService::MODE_TEXT) {
            return '';
        }

        $path = $this->workspaceNodeScanner->resolveDiskNodeAbsolutePath($workspaceSlug, $diskPath);
        if ($path === null) {
            return '';
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return '';
        }

        return $content;
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
