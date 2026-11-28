<?php

namespace App\Controller;

use App\Service\AcmsManifestPropertyBridge;
use App\Service\TypeSchemaRegistry;
use App\Service\TypeViewResolver;
use App\Service\VaultBreadcrumbBuilder;
use App\Service\VaultDiskNodeEditService;
use App\Service\VaultNodeCreator;
use App\Service\VaultNodeScanner;
use App\Service\VaultNodeStorage;
use App\Service\VaultService;
use App\Service\VaultSlotFolderWriter;
use App\Service\VaultSlotTocIndexBuilder;
use App\Service\VaultViewAssembler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VaultDiskNodeController extends AbstractController
{
    private VaultService $vaultService;

    private VaultNodeScanner $vaultNodeScanner;

    private TypeViewResolver $typeViewResolver;

    private VaultViewAssembler $vaultViewAssembler;

    private VaultBreadcrumbBuilder $vaultBreadcrumbBuilder;

    private TypeSchemaRegistry $typeSchemaRegistry;

    private AcmsManifestPropertyBridge $acmsManifestPropertyBridge;

    private VaultDiskNodeEditService $vaultDiskNodeEditService;

    private VaultNodeCreator $vaultNodeCreator;

    private VaultSlotFolderWriter $vaultSlotFolderWriter;

    private VaultSlotTocIndexBuilder $vaultSlotTocIndexBuilder;

    public function __construct(
        VaultService $vaultService,
        VaultNodeScanner $vaultNodeScanner,
        TypeViewResolver $typeViewResolver,
        VaultViewAssembler $vaultViewAssembler,
        VaultBreadcrumbBuilder $vaultBreadcrumbBuilder,
        TypeSchemaRegistry $typeSchemaRegistry,
        AcmsManifestPropertyBridge $acmsManifestPropertyBridge,
        VaultDiskNodeEditService $vaultDiskNodeEditService,
        VaultNodeCreator $vaultNodeCreator,
        VaultSlotFolderWriter $vaultSlotFolderWriter,
        VaultSlotTocIndexBuilder $vaultSlotTocIndexBuilder
    ) {
        $this->vaultService = $vaultService;
        $this->vaultNodeScanner = $vaultNodeScanner;
        $this->typeViewResolver = $typeViewResolver;
        $this->vaultViewAssembler = $vaultViewAssembler;
        $this->vaultBreadcrumbBuilder = $vaultBreadcrumbBuilder;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->acmsManifestPropertyBridge = $acmsManifestPropertyBridge;
        $this->vaultDiskNodeEditService = $vaultDiskNodeEditService;
        $this->vaultNodeCreator = $vaultNodeCreator;
        $this->vaultSlotFolderWriter = $vaultSlotFolderWriter;
        $this->vaultSlotTocIndexBuilder = $vaultSlotTocIndexBuilder;
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

    #[Route('/{slug}/disk-node/{nodePath}', name: 'vault_disk_node_legacy', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.*'], methods: ['GET'], priority: 12)]
    public function legacyRedirect(string $slug, string $nodePath): Response
    {
        return $this->redirectToRoute('vault_disk_node_show', [
            'slug' => $slug,
            'nodePath' => $nodePath,
        ], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/{slug}/{nodePath}', name: 'vault_disk_file_view', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.+~view'], methods: ['GET'], priority: 4)]
    public function viewFile(string $slug, string $nodePath): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $fileDiskPath = $this->stripViewSuffix($nodePath);
        $this->assertDiskPathAllowed($fileDiskPath);

        $absolutePath = $this->vaultNodeScanner->resolveDiskNodeAbsolutePath($vault['slug'], $fileDiskPath);
        if ($absolutePath === null || !is_file($absolutePath)) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        $diskNode = $this->vaultNodeScanner->readDiskNode($vault['slug'], $fileDiskPath);
        if ($diskNode === null) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        $vault['initials'] = $this->vaultService->initialsFromName($vault['name']);

        $editMode = $this->vaultDiskNodeEditService->resolveEditMode($diskNode);
        $rawText = '';
        if ($editMode === VaultDiskNodeEditService::MODE_TEXT) {
            $content = file_get_contents($absolutePath);
            if (is_string($content)) {
                $rawText = $content;
            }
        }

        $split = $this->splitNodeAndRelativeFilePath($fileDiskPath);
        $parentDiskPath = $split['node_disk_path'];
        $fileLabel = basename($fileDiskPath);
        if ($fileLabel === '') {
            $fileLabel = $fileDiskPath;
        }

        $hubFamily = $this->vaultViewAssembler->resolveHubFamilyForDiskPath($parentDiskPath, $vault['slug']);
        $parentTypeId = '';
        $parentLabel = $parentDiskPath;
        if ($parentDiskPath === '') {
            $parentLabel = 'Корень';
        }
        $parentNode = $this->vaultNodeScanner->readDiskNode($vault['slug'], $parentDiskPath);
        if ($parentNode !== null) {
            $parentTypeId = $this->acmsManifestPropertyBridge->stringProperty($parentNode['properties'], 'acms-type');
            $parentLabel = $this->resolveNodeLabel($parentDiskPath, $parentNode['properties']);
        }

        $crumbs = $this->vaultBreadcrumbBuilder->buildForDiskNode(
            $vault,
            $parentDiskPath,
            $parentLabel,
            $hubFamily,
            $parentTypeId
        );
        $crumbs[] = [
            'label' => $fileLabel,
            'route' => '',
            'route_params' => [],
            'current' => true,
        ];

        $body = $diskNode['body'];
        if (!is_string($body)) {
            $body = '';
        }

        return $this->render('vault/disk_file_view.html.twig', array_merge(
            $this->vaultViewAssembler->buildViewContext($vault),
            [
                'disk_file' => $diskNode,
                'disk_file_path' => $fileDiskPath,
                'disk_file_label' => $fileLabel,
                'disk_file_body' => $body,
                'disk_file_raw' => $rawText,
                'disk_file_edit_mode' => $editMode,
                'disk_node_parent_path' => $parentDiskPath,
                'disk_node_parent_label' => $parentLabel,
                'active_disk_node_path' => $parentDiskPath,
                'active_hub_path' => '',
                'active_hub_family' => $hubFamily,
                'active_hub_family_page' => false,
                'active_module_slug' => '',
                'vault_breadcrumbs' => $crumbs,
                'vault_toolbar' => $this->vaultBreadcrumbBuilder->buildToolbarForDiskNode($vault, $parentDiskPath),
            ]
        ));
    }

    #[Route('/{slug}/{nodePath}', name: 'vault_disk_node_edit', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.+~edit'], methods: ['GET', 'POST'], priority: 4)]
    public function edit(string $slug, string $nodePath, Request $request): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $diskPath = $this->stripEditSuffix($nodePath);
        $this->assertDiskPathAllowed($diskPath);

        $diskNode = $this->vaultNodeScanner->readDiskNode($vault['slug'], $diskPath);
        if ($diskNode === null) {
            throw $this->createNotFoundException('Узел не найден.');
        }

        $vault['initials'] = $this->vaultService->initialsFromName($vault['name']);

        $editMode = $this->vaultDiskNodeEditService->resolveEditMode($diskNode);
        $csrfId = $this->vaultDiskNodeEditService->resolveCsrfTokenId($diskPath);

        $properties = $diskNode['properties'];
        $typeId = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-type');
        if ($typeId === '' && isset($diskNode['manifest_file']) && is_string($diskNode['manifest_file'])) {
            $typeId = $this->vaultNodeScanner->resolveRootCreatableFileTypeId(
                $diskNode['manifest_file'],
                $vault['slug']
            );
        }
        $hubFamily = $this->vaultViewAssembler->resolveHubFamilyForDiskPath($diskPath, $vault['slug']);
        $nodeLabel = $this->resolveNodeLabel($diskPath, $properties);
        if ($nodeLabel === $diskPath && isset($diskNode['manifest_file']) && is_string($diskNode['manifest_file'])) {
            $nodeLabel = $diskNode['manifest_file'];
        }

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
                $this->vaultDiskNodeEditService->save(
                    $vault['slug'],
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

            return $this->redirectToRoute('vault_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $diskPath !== '' ? $diskPath : '_',
            ]);
        }

        $crumbs = $this->vaultBreadcrumbBuilder->buildForDiskNode(
            $vault,
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

        $manifestBodyLabel = $this->typeSchemaRegistry->resolveManifestBodyLabel($typeId, $vault['slug']);

        return $this->render('vault/disk_node_edit.html.twig', array_merge(
            $this->vaultViewAssembler->buildViewContext($vault),
            [
                'disk_node' => $diskNode,
                'disk_node_path' => $diskPath,
                'disk_node_label' => $nodeLabel,
                'disk_node_type_id' => $this->typeSchemaRegistry->publicTypeId($typeId),
                'disk_node_manifest_body_label' => $manifestBodyLabel,
                'disk_node_edit_mode' => $editMode,
                'disk_node_edit_csrf_id' => $csrfId,
                'disk_node_form_name' => $formName,
                'disk_node_form_description' => $formDescription,
                'disk_node_form_body' => $formBody,
                'disk_node_form_raw' => $this->resolveRawFileContents($vault['slug'], $diskPath, $diskNode, $editMode),
                'active_disk_node_path' => $diskPath,
                'active_hub_path' => '',
                'active_hub_family' => $hubFamily,
                'active_hub_family_page' => false,
                'active_module_slug' => '',
                'vault_breadcrumbs' => $crumbs,
                'vault_toolbar' => $this->vaultBreadcrumbBuilder->buildToolbarForDiskNode($vault, $diskPath),
            ]
        ));
    }

    #[Route('/{slug}/{nodePath}', name: 'vault_disk_single_file_create', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.+~create-single-file'], methods: ['POST'], priority: 4)]
    public function createSingleFileSlot(string $slug, string $nodePath, Request $request): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
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

        $ownerNode = $this->vaultNodeScanner->readDiskNode($vault['slug'], $nodeDiskPath);
        if ($ownerNode === null) {
            throw $this->createNotFoundException('Узел не найден.');
        }

        if ($this->vaultNodeScanner->readFileRelativeToDiskNode($vault['slug'], $nodeDiskPath, $relativePath) !== null) {
            throw $this->createNotFoundException('Файл уже существует.');
        }

        $typeId = $this->acmsManifestPropertyBridge->stringProperty($ownerNode['properties'], 'acms-type');

        try {
            $this->vaultNodeCreator->bootstrapSingleFileSlot(
                $vault['slug'],
                $nodeDiskPath,
                $relativePath,
                $typeId
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('vault_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $nodeDiskPath !== '' ? $nodeDiskPath : '_',
            ]);
        } catch (\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('vault_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $nodeDiskPath !== '' ? $nodeDiskPath : '_',
            ]);
        }

        $this->addFlash('success', 'Файл слота создан.');

        return $this->redirectToRoute('vault_disk_node_show', [
            'slug' => $slug,
            'nodePath' => $nodeDiskPath !== '' ? $nodeDiskPath : '_',
        ]);
    }

    #[Route('/{slug}/{nodePath}', name: 'vault_disk_build_slot_index', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.+~build-slot-index'], methods: ['POST'], priority: 4)]
    public function buildSlotIndex(string $slug, string $nodePath, Request $request): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $nodeDiskPath = $this->stripBuildSlotIndexSuffix($nodePath);
        $this->assertDiskPathAllowed($nodeDiskPath);

        $csrfId = 'disk_slot_index_build_' . md5($nodeDiskPath);
        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid($csrfId, $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $ownerNode = $this->vaultNodeScanner->readDiskNode($vault['slug'], $nodeDiskPath);
        if ($ownerNode === null) {
            throw $this->createNotFoundException('Узел не найден.');
        }

        $typeId = $this->acmsManifestPropertyBridge->stringProperty($ownerNode['properties'], 'acms-type');

        try {
            $indexDiskPath = $this->vaultSlotTocIndexBuilder->writeIndex($vault['slug'], $nodeDiskPath, $typeId);
        } catch (\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('vault_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $nodeDiskPath !== '' ? $nodeDiskPath : '_',
            ]);
        }

        $this->addFlash('success', 'Индекс оглавлений обновлён.');

        return $this->redirectToRoute('vault_disk_file_view', [
            'slug' => $slug,
            'nodePath' => $indexDiskPath . '~view',
        ]);
    }

    #[Route('/{slug}/{nodePath}', name: 'vault_disk_slot_create', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.+~create-slot'], methods: ['POST'], priority: 4)]
    public function createStorageSlot(string $slug, string $nodePath, Request $request): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $slotDiskPath = $this->stripCreateSlotSuffix($nodePath);
        $this->assertDiskPathAllowed($slotDiskPath);

        $csrfId = 'disk_slot_create_' . md5($slotDiskPath);
        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid($csrfId, $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $missingSlot = $this->vaultNodeScanner->resolveMissingStorageSlot($vault['slug'], $slotDiskPath);
        if ($missingSlot === null) {
            throw $this->createNotFoundException('Слот не найден или уже создан.');
        }

        $nodeDiskPath = $missingSlot['node_disk_path'];
        $slotFolder = $missingSlot['slot_folder'];
        $ownerNode = $this->vaultNodeScanner->readDiskNode($vault['slug'], $nodeDiskPath);
        if ($ownerNode === null) {
            throw $this->createNotFoundException('Узел не найден.');
        }

        $typeId = $this->acmsManifestPropertyBridge->stringProperty($ownerNode['properties'], 'acms-type');

        try {
            $this->vaultNodeCreator->bootstrapStorageSlotDirectory(
                $vault['slug'],
                $nodeDiskPath,
                $slotFolder,
                $typeId
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('vault_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $slotDiskPath,
            ]);
        } catch (\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('vault_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $slotDiskPath,
            ]);
        }

        $this->addFlash('success', 'Папка слота создана.');

        return $this->redirectToRoute('vault_disk_node_show', [
            'slug' => $slug,
            'nodePath' => $slotDiskPath,
        ]);
    }

    #[Route('/{slug}/{nodePath}', name: 'vault_disk_slot_create_category', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.+~slot-create-category'], methods: ['POST'], priority: 4)]
    public function createSlotCategory(string $slug, string $nodePath, Request $request): Response
    {
        return $this->handleSlotFolderMutation($slug, $nodePath, $request, '~slot-create-category', function (
            string $vaultSlug,
            string $folderDiskPath,
            Request $request
        ): string {
            $sectionSlug = $request->request->getString('section_slug');
            $sectionName = $request->request->getString('section_name');
            $sectionDescription = $request->request->getString('section_description');
            $created = $this->vaultSlotFolderWriter->createCategory(
                $vaultSlug,
                $folderDiskPath,
                $sectionSlug,
                $sectionName,
                $sectionDescription
            );

            return $created['disk_path'];
        }, 'Раздел создан.');
    }

    #[Route('/{slug}/{nodePath}', name: 'vault_disk_slot_create_record', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.+~slot-create-record'], methods: ['POST'], priority: 4)]
    public function createSlotRecord(string $slug, string $nodePath, Request $request): Response
    {
        return $this->handleSlotFolderMutation($slug, $nodePath, $request, '~slot-create-record', function (
            string $vaultSlug,
            string $folderDiskPath,
            Request $request
        ): string {
            $title = $request->request->getString('record_title');
            $recordSlug = $request->request->getString('record_slug');
            $recordDescription = $request->request->getString('record_description');
            $recordExtension = $request->request->getString('record_extension');
            $created = $this->vaultSlotFolderWriter->createRecord(
                $vaultSlug,
                $folderDiskPath,
                $title,
                $recordSlug,
                $recordDescription,
                $recordExtension
            );

            return $created['disk_path'];
        }, 'Запись создана.');
    }

    #[Route('/{slug}/{nodePath}', name: 'vault_disk_slot_upload', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.+~slot-upload'], methods: ['POST'], priority: 4)]
    public function uploadSlotFiles(string $slug, string $nodePath, Request $request): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $folderDiskPath = $this->stripActionSuffix($nodePath, '~slot-upload');
        $this->assertDiskPathAllowed($folderDiskPath);

        $csrfId = $this->slotFolderCsrfId($folderDiskPath);
        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid($csrfId, $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $uploaded = [];
        $bag = $request->files->all();
        if (isset($bag['upload_files']) && is_array($bag['upload_files'])) {
            foreach ($bag['upload_files'] as $item) {
                if ($item instanceof UploadedFile) {
                    $uploaded[] = $item;
                }
            }
        }

        try {
            $result = $this->vaultSlotFolderWriter->uploadFiles($vault['slug'], $folderDiskPath, $uploaded);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('vault_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $folderDiskPath,
            ]);
        } catch (\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('vault_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $folderDiskPath,
            ]);
        }

        $this->addFlash('success', 'Загружено файлов: ' . (string) $result['count'] . '.');

        return $this->redirectToRoute('vault_disk_node_show', [
            'slug' => $slug,
            'nodePath' => $folderDiskPath,
        ]);
    }

    #[Route('/{slug}/{nodePath}', name: 'vault_disk_node_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'nodePath' => '.*'], methods: ['GET'], priority: 3)]
    public function show(string $slug, string $nodePath): Response
    {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        if ($nodePath === '_') {
            $nodePath = '';
        }

        $nodePath = trim(str_replace('\\', '/', $nodePath), '/');
        if (str_ends_with($nodePath, '~edit')) {
            throw $this->createNotFoundException('Узел не найден.');
        }
        if (str_ends_with($nodePath, '~view')) {
            throw $this->createNotFoundException('Узел не найден.');
        }
        if (str_ends_with($nodePath, '~build-slot-index')) {
            throw $this->createNotFoundException('Узел не найден.');
        }
        $this->assertDiskPathAllowed($nodePath);
        $diskNode = $this->vaultNodeScanner->readDiskNode($vault['slug'], $nodePath);
        if ($diskNode === null) {
            $folderListing = $this->vaultNodeScanner->readDiskDirectoryListing($vault['slug'], $nodePath);
            if ($folderListing === null) {
                $missingSlot = $this->vaultNodeScanner->resolveMissingStorageSlot($vault['slug'], $nodePath);
                if ($missingSlot === null) {
                    throw $this->createNotFoundException('Узел не найден.');
                }

                return $this->renderDiskSlotMissing($vault, $missingSlot);
            }

            return $this->renderDiskFolder($vault, $nodePath, $folderListing);
        }

        $vault['initials'] = $this->vaultService->initialsFromName($vault['name']);

        $typeId = '';
        $properties = $diskNode['properties'];
        $typeId = $this->acmsManifestPropertyBridge->stringProperty($properties, 'acms-type');
        if ($typeId === '' && isset($diskNode['manifest_file']) && is_string($diskNode['manifest_file'])) {
            $typeId = $this->vaultNodeScanner->resolveRootCreatableFileTypeId(
                $diskNode['manifest_file'],
                $vault['slug']
            );
        }

        $hubFamily = $this->vaultViewAssembler->resolveHubFamilyForDiskPath($nodePath, $vault['slug']);
        $nodeLabel = $this->resolveNodeLabel($nodePath, $properties);
        if ($nodeLabel === $nodePath && isset($diskNode['manifest_file']) && is_string($diskNode['manifest_file'])) {
            $nodeLabel = $diskNode['manifest_file'];
        }
        $view = $this->typeViewResolver->resolveDiskNodeView($typeId, $vault['slug']);
        $slotPartition = $this->partitionDiskNodeStorageSlots($vault['slug'], $nodePath, $typeId);
        $manifestBodyLabel = $this->typeSchemaRegistry->resolveManifestBodyLabel($typeId, $vault['slug']);
        $slotIndexContext = $this->buildSlotIndexViewContext($vault['slug'], $nodePath, $typeId, $slotPartition);

        $context = array_merge(
            $this->vaultViewAssembler->buildViewContext($vault),
            $slotPartition,
            $slotIndexContext,
            [
                'disk_node' => $diskNode,
                'disk_node_path' => $nodePath,
                'disk_node_label' => $nodeLabel,
                'disk_node_type_id' => $this->typeSchemaRegistry->publicTypeId($typeId),
                'disk_node_manifest_body_label' => $manifestBodyLabel,
                'disk_node_view_template' => $view['template'],
                'active_disk_node_path' => $nodePath,
                'active_hub_path' => '',
                'active_hub_family' => $hubFamily,
                'active_hub_family_page' => false,
                'active_module_slug' => '',
                'vault_breadcrumbs' => $this->vaultBreadcrumbBuilder->buildForDiskNode(
                    $vault,
                    $nodePath,
                    $nodeLabel,
                    $hubFamily,
                    $typeId
                ),
                'vault_toolbar' => $this->vaultBreadcrumbBuilder->buildToolbarForDiskNode($vault, $nodePath),
            ]
        );

        $action = $view['action'];
        if ($action !== '' && method_exists($this, $action)) {
            return $this->$action($context);
        }

        return $this->render('vault/disk_node.html.twig', $context);
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
        $template = 'vault/disk_nodes/_default.html.twig';
        if (isset($context['disk_node_view_template']) && is_string($context['disk_node_view_template'])) {
            $template = $context['disk_node_view_template'];
        }

        return $this->render('vault/disk_node.html.twig', array_merge($context, [
            'disk_node_view_template' => $template,
        ]));
    }

    /**
     * @param array<string, mixed> $vault
     * @param array{node_disk_path: string, slot_disk_path: string, slot_folder: string, slot: array<string, mixed>} $missingSlot
     */
    private function renderDiskSlotMissing(array $vault, array $missingSlot): Response
    {
        $vault['initials'] = $this->vaultService->initialsFromName($vault['name']);

        $nodeDiskPath = $missingSlot['node_disk_path'];
        $slotDiskPath = $missingSlot['slot_disk_path'];
        $slot = $missingSlot['slot'];

        $hubFamily = $this->vaultViewAssembler->resolveHubFamilyForDiskPath($nodeDiskPath, $vault['slug']);
        $nodeLabel = $nodeDiskPath;
        $ownerNode = $this->vaultNodeScanner->readDiskNode($vault['slug'], $nodeDiskPath);
        if ($ownerNode !== null) {
            $nodeLabel = $this->resolveNodeLabel($nodeDiskPath, $ownerNode['properties']);
        }

        $slotName = $slotDiskPath;
        if (isset($slot['name']) && is_string($slot['name'])) {
            $slotName = $slot['name'];
        }

        $csrfId = 'disk_slot_create_' . md5($slotDiskPath);

        return $this->render('vault/disk_slot_missing.html.twig', array_merge(
            $this->vaultViewAssembler->buildViewContext($vault),
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
                'vault_breadcrumbs' => $this->vaultBreadcrumbBuilder->buildForDiskFolder(
                    $vault,
                    $slotDiskPath,
                    $nodeDiskPath,
                    $nodeLabel,
                    $hubFamily
                ),
                'vault_toolbar' => $this->vaultBreadcrumbBuilder->buildToolbarForDiskNode($vault, $slotDiskPath),
            ]
        ));
    }

    /**
     * @param array<string, mixed> $vault
     * @param array<string, mixed> $folderListing
     */
    private function renderDiskFolder(array $vault, string $folderDiskPath, array $folderListing): Response
    {
        $vault['initials'] = $this->vaultService->initialsFromName($vault['name']);

        $split = VaultNodeStorage::splitFolderDiskPath($folderDiskPath);
        $nodeDiskPath = $split['node_disk_path'];

        $nodeLabel = $folderDiskPath;
        $hubFamily = $this->vaultViewAssembler->resolveHubFamilyForDiskPath($nodeDiskPath, $vault['slug']);

        if ($nodeDiskPath !== '') {
            $ownerNode = $this->vaultNodeScanner->readDiskNode($vault['slug'], $nodeDiskPath);
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

        $slotActionsEnabled = $this->vaultSlotFolderWriter->isStorageFolderDiskPath($folderDiskPath);
        $slotFolderCsrfId = '';
        $recordExtensions = ['md'];
        $recordExtensionGroups = [];
        if ($slotActionsEnabled) {
            $slotFolderCsrfId = $this->slotFolderCsrfId($folderDiskPath);
            $recordExtensions = $this->vaultSlotFolderWriter->listRecordCreateExtensions($vault['slug'], $folderDiskPath);
            $recordExtensionGroups = $this->vaultSlotFolderWriter->listRecordCreateExtensionGroups($vault['slug'], $folderDiskPath);
        }

        return $this->render('vault/disk_folder.html.twig', array_merge(
            $this->vaultViewAssembler->buildViewContext($vault),
            [
                'disk_folder_path' => $folderDiskPath,
                'disk_folder_label' => $folderLabel,
                'disk_folder_parent_path' => $parentDiskPath,
                'disk_folder_entries' => $entries,
                'disk_folder_slot_actions' => $slotActionsEnabled,
                'disk_folder_slot_csrf_id' => $slotFolderCsrfId,
                'disk_folder_record_extensions' => $recordExtensions,
                'disk_folder_record_extension_groups' => $recordExtensionGroups,
                'disk_node_path' => $nodeDiskPath,
                'active_disk_node_path' => $folderDiskPath,
                'active_hub_path' => '',
                'active_hub_family' => $hubFamily,
                'active_hub_family_page' => false,
                'active_module_slug' => '',
                'vault_breadcrumbs' => $this->vaultBreadcrumbBuilder->buildForDiskFolder(
                    $vault,
                    $folderDiskPath,
                    $nodeDiskPath,
                    $nodeLabel,
                    $hubFamily
                ),
                'vault_toolbar' => $this->vaultBreadcrumbBuilder->buildToolbarForDiskNode($vault, $folderDiskPath),
            ]
        ));
    }

    /**
     * @return array{disk_node_storage_slots: list<array<string, mixed>>, disk_node_single_file_slots: list<array<string, mixed>>}
     */
    private function partitionDiskNodeStorageSlots(string $vaultSlug, string $diskPath, string $typeId): array
    {
        $all = $this->typeSchemaRegistry->listStorageSlotsForNodeType($typeId, $vaultSlug);
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
                $content = $this->vaultNodeScanner->readFileRelativeToDiskNode($vaultSlug, $diskPath, $relativePath);
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
                $slot['exists_on_disk'] = $this->vaultNodeScanner->resolveAbsoluteDiskPath($vaultSlug, $slotDiskPath) !== null;
                $slot['slot_disk_path'] = $slotDiskPath;
                $slot['create_csrf_id'] = 'disk_slot_create_' . md5($slotDiskPath);
                $slot['entry_count'] = $this->vaultNodeScanner->countFilesInDiskNodeSubtree(
                    $vaultSlug,
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

    private function stripViewSuffix(string $nodePath): string
    {
        $suffix = '~view';
        if (!str_ends_with($nodePath, $suffix)) {
            throw $this->createNotFoundException('Файл не найден.');
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

    private function stripBuildSlotIndexSuffix(string $nodePath): string
    {
        $suffix = '~build-slot-index';
        if (!str_ends_with($nodePath, $suffix)) {
            throw $this->createNotFoundException('Узел не найден.');
        }

        $diskPath = substr($nodePath, 0, -strlen($suffix));

        return trim(str_replace('\\', '/', $diskPath), '/');
    }

    /**
     * @param array{disk_node_storage_slots: list<array<string, mixed>>, disk_node_single_file_slots: list<array<string, mixed>>} $slotPartition
     *
     * @return array{disk_node_slot_index_enabled: bool, disk_node_slot_index_exists: bool, disk_node_slot_index_path: string, disk_node_slot_index_view_path: string, disk_node_slot_index_build_csrf_id: string}
     */
    private function buildSlotIndexViewContext(
        string $vaultSlug,
        string $nodeDiskPath,
        string $typeId,
        array $slotPartition
    ): array {
        $folderCount = count($slotPartition['disk_node_storage_slots']);
        $singleCount = count($slotPartition['disk_node_single_file_slots']);
        $enabled = false;
        if ($folderCount > 0 || $singleCount > 0) {
            $enabled = true;
        }

        $indexExists = false;
        if ($enabled) {
            $indexExists = $this->vaultSlotTocIndexBuilder->indexExists($vaultSlug, $nodeDiskPath);
        }

        $indexPath = VaultSlotTocIndexBuilder::INDEX_RELATIVE_PATH;
        $indexViewPath = $indexPath;
        if ($nodeDiskPath !== '') {
            $indexViewPath = $nodeDiskPath . '/' . $indexPath;
        }

        $csrfId = 'disk_slot_index_build_' . md5($nodeDiskPath);

        return [
            'disk_node_slot_index_enabled' => $enabled,
            'disk_node_slot_index_exists' => $indexExists,
            'disk_node_slot_index_path' => $indexPath,
            'disk_node_slot_index_view_path' => $indexViewPath,
            'disk_node_slot_index_build_csrf_id' => $csrfId,
        ];
    }

    private function stripActionSuffix(string $nodePath, string $suffix): string
    {
        if (!str_ends_with($nodePath, $suffix)) {
            throw $this->createNotFoundException('Папка не найдена.');
        }

        $diskPath = substr($nodePath, 0, -strlen($suffix));

        return trim(str_replace('\\', '/', $diskPath), '/');
    }

    private function slotFolderCsrfId(string $folderDiskPath): string
    {
        return 'disk_folder_slot_' . md5($folderDiskPath);
    }

    /**
     * @param callable(string, string, Request): string $mutate
     */
    private function handleSlotFolderMutation(
        string $slug,
        string $nodePath,
        Request $request,
        string $suffix,
        callable $mutate,
        string $successMessage
    ): Response {
        $vault = $this->vaultService->findBySlug($slug);
        if ($vault === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $folderDiskPath = $this->stripActionSuffix($nodePath, $suffix);
        $this->assertDiskPathAllowed($folderDiskPath);

        $csrfId = $this->slotFolderCsrfId($folderDiskPath);
        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid($csrfId, $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        try {
            $redirectPath = $mutate($vault['slug'], $folderDiskPath, $request);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('vault_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $folderDiskPath,
            ]);
        } catch (\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('vault_disk_node_show', [
                'slug' => $slug,
                'nodePath' => $folderDiskPath,
            ]);
        }

        $this->addFlash('success', $successMessage);

        return $this->redirectToRoute('vault_disk_node_show', [
            'slug' => $slug,
            'nodePath' => $redirectPath,
        ]);
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
    private function resolveRawFileContents(string $vaultSlug, string $diskPath, array $diskNode, string $editMode): string
    {
        if ($editMode !== VaultDiskNodeEditService::MODE_TEXT) {
            return '';
        }

        $path = $this->vaultNodeScanner->resolveDiskNodeAbsolutePath($vaultSlug, $diskPath);
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
