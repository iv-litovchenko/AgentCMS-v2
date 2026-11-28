<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setParameter('app.version', $this->resolveAppVersionFromComposer());
    }

    private function resolveAppVersionFromComposer(): string
    {
        $fallback = '0.0.0';
        $path = $this->getProjectDir() . '/composer.json';
        if (!is_readable($path)) {
            return $fallback;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return $fallback;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $fallback;
        }

        if (!isset($decoded['version']) || !is_string($decoded['version'])) {
            return $fallback;
        }

        $version = trim($decoded['version']);
        if ($version === '') {
            return $fallback;
        }

        return $version;
    }
}
