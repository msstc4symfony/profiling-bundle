<?php

declare(strict_types=1);

use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // Defaults profile nothing: list routes, commands or span prefixes to enable.
    $container->parameters()
        ->set('msstc4symfony_profiling.routes.whitelist', [])
        ->set('msstc4symfony_profiling.commands.whitelist', [])
        ->set('msstc4symfony_profiling.spans.whitelist', null)
        ->set('msstc4symfony_profiling.spans.blacklist', null)
    ;

    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $services->load('Msstc4Symfony\\ProfilingBundle\\', '../../')
        ->exclude(['../../ProfilingBundle.php', '../../Resources/'])
    ;

    $services->set(ProfilingFactory::class)->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(ProfilingFactoryInterface::class, ProfilingFactory::class)->public();
};
