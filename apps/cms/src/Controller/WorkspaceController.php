<?php

namespace App\Controller;

use App\Form\WorkspaceData;
use App\Form\WorkspaceFormType;
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

    public function __construct(WorkspaceService $workspaceService)
    {
        $this->workspaceService = $workspaceService;
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

    #[Route('/{slug}', name: 'workspace_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'], priority: -10)]
    public function show(string $slug): Response
    {
        $workspace = $this->workspaceService->findBySlug($slug);
        if ($workspace === null) {
            throw $this->createNotFoundException('Хранилище не найдено.');
        }

        $workspace['initials'] = $this->workspaceService->initialsFromName($workspace['name']);

        return $this->render('workspace/show.html.twig', [
            'workspace' => $workspace,
        ]);
    }
}
