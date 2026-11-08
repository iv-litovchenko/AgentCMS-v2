<?php

namespace App\Controller;

use App\Form\WorkspaceData;
use App\Form\WorkspaceFormType;
use App\Service\HubViewRegistry;
use App\Service\TypeSchemaRegistry;
use App\Service\WorkspaceModuleRegistry;
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

    private HubViewRegistry $hubViewRegistry;

    public function __construct(
        WorkspaceService $workspaceService,
        TypeSchemaRegistry $typeSchemaRegistry,
        WorkspaceModuleRegistry $workspaceModuleRegistry,
        HubViewRegistry $hubViewRegistry
    ) {
        $this->workspaceService = $workspaceService;
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->workspaceModuleRegistry = $workspaceModuleRegistry;
        $this->hubViewRegistry = $hubViewRegistry;
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

    #[Route('/{slug}/hubs/{family}', name: 'workspace_hub_family_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'family' => '[a-z][a-z0-9]*'], methods: ['GET'], priority: 7)]
    public function hubFamilyShow(string $slug, string $family): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $family = trim($family);
        $hubGroup = $this->typeSchemaRegistry->findHubTreeGroupByFamily($family, $workspace['slug']);
        if ($hubGroup === null) {
            throw $this->createNotFoundException('Семья hub не найдена.');
        }

        $familyBase = $this->typeSchemaRegistry->resolveType('hubs/' . $family . '/_base', $workspace['slug']);

        $workspace['initials'] = $this->workspaceService->initialsFromName($workspace['name']);

        return $this->render('workspace/hub_family.html.twig', array_merge(
            $this->workspaceViewContext($workspace),
            [
                'hub_group' => $hubGroup,
                'hub_family' => $family,
                'hub_family_base' => $familyBase,
                'active_hub_path' => '',
                'active_hub_family' => $family,
                'active_hub_family_page' => true,
                'active_module_slug' => '',
            ]
        ));
    }

    #[Route('/{slug}/hub/{hub}', name: 'workspace_hub_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'hub' => '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*'], methods: ['GET'], priority: 6)]
    public function hubShow(string $slug, string $hub): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $hub = trim(str_replace('\\', '/', $hub), '/');
        $typeId = 'hubs/' . $hub;
        $hubType = $this->typeSchemaRegistry->resolveType($typeId, $workspace['slug']);
        if ($hubType === null) {
            throw $this->createNotFoundException('Тип hub не найден.');
        }

        $workspace['initials'] = $this->workspaceService->initialsFromName($workspace['name']);

        $hubLabel = $typeId;
        if (isset($hubType['name']) && is_string($hubType['name']) && $hubType['name'] !== '') {
            $hubLabel = $hubType['name'];
        }

        $viewTemplate = $this->hubViewRegistry->resolveViewTemplate($hub);

        $hubRootManifest = null;
        if ($hub === 'root/root') {
            $hubRootManifest = $this->workspaceService->readRootNodeManifest($workspace['slug']);
        }

        $hubFamily = $hub;
        $slashPos = strpos($hub, '/');
        if ($slashPos !== false) {
            $hubFamily = substr($hub, 0, $slashPos);
        }

        return $this->render('workspace/hub.html.twig', array_merge(
            $this->workspaceViewContext($workspace),
            [
                'hub_type' => $hubType,
                'hub_id' => $typeId,
                'hub_path' => $hub,
                'hub_label' => $hubLabel,
                'hub_view_template' => $viewTemplate,
                'hub_root_manifest' => $hubRootManifest,
                'active_hub_path' => $hub,
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

        return $this->render('workspace/module.html.twig', array_merge(
            $this->workspaceViewContext($workspace),
            [
                'module' => $moduleMeta,
                'active_module_slug' => $moduleMeta['slug'],
                'active_hub_path' => '',
                'search_query' => $searchQuery,
            ]
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
            $this->workspaceViewContext($workspace),
            [
                'active_module_slug' => '',
                'active_hub_path' => '',
            ]
        ));
    }

    /**
     * @param array<string, mixed> $workspace
     *
     * @return array<string, mixed>
     */
    private function workspaceViewContext(array $workspace): array
    {
        $typeSchema = $this->typeSchemaRegistry->resolveType($workspace['type_id'], $workspace['slug']);
        $typeLabel = $workspace['type_id'];
        $typeKind = '';
        if (is_array($typeSchema)) {
            if (isset($typeSchema['name']) && is_string($typeSchema['name'])) {
                $typeLabel = $typeSchema['name'];
            }
            if (isset($typeSchema['kind']) && is_string($typeSchema['kind'])) {
                $typeKind = $typeSchema['kind'];
            }
        }

        return [
            'workspace' => $workspace,
            'workspaces' => $this->workspaceService->listForDisplay(),
            'workspace_modules' => $this->workspaceModuleRegistry->listSidebarModules($workspace['slug']),
            'hub_tree' => $this->typeSchemaRegistry->listHubTreeGroups($workspace['slug']),
            'type_label' => $typeLabel,
            'type_kind' => $typeKind,
            'active_hub_path' => '',
            'active_hub_family' => '',
            'active_hub_family_page' => false,
        ];
    }
}
