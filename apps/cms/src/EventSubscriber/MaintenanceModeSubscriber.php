<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

class MaintenanceModeSubscriber implements EventSubscriberInterface
{
    private string $maintenanceModeFile;

    private Environment $twig;

    public function __construct(string $maintenanceModeFile, Environment $twig)
    {
        $this->maintenanceModeFile = $maintenanceModeFile;
        $this->twig = $twig;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if ($path === '/health') {
            return;
        }

        if (!$this->isMaintenanceOn()) {
            return;
        }

        $html = $this->twig->render('errors/maintenance.html.twig', [
            'message' => 'Платформа временно недоступна. Попробуйте позже.',
        ]);

        $event->setResponse(new Response($html, Response::HTTP_SERVICE_UNAVAILABLE));
    }

    private function isMaintenanceOn(): bool
    {
        if (!is_file($this->maintenanceModeFile)) {
            return false;
        }

        $content = trim((string) file_get_contents($this->maintenanceModeFile));

        return $content === '1';
    }
}
