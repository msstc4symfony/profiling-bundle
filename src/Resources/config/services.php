<?php

declare(strict_types=1);

use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\Worker;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $exclude = ['../../ProfilingBundle.php', '../../Resources/'];
    if (!class_exists(Worker::class)) {
        $exclude[] = '../../EventListener/MessageEventListener.php';
    }

    $services->load('Msstc4Symfony\\ProfilingBundle\\', '../../')->exclude($exclude);

    $services->alias(ProfilingFactoryInterface::class, ProfilingFactory::class);
};
