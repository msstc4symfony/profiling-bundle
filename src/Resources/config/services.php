<?php

declare(strict_types=1);

use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\Worker;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    // Infrastructure needs optional libraries: symfony/dependency-injection 7.4.0 loads every scanned class and fatals
    // on a missing interface. Its services and the compiler passes are registered by ProfilingBundle.
    $exclude = ['../../ProfilingBundle.php', '../../Resources/', '../../Infrastructure/', '../../DependencyInjection/'];
    // Without Messenger its events never fire; keep the listener out of the container.
    if (!class_exists(Worker::class)) {
        $exclude[] = '../../Presentation/EventListener/MessageEventListener.php';
    }

    $services->load('Msstc4Symfony\\ProfilingBundle\\', '../../')->exclude($exclude);

    $services->alias(ProfilingFactoryInterface::class, ProfilingFactory::class);
    $services->alias(DetachedSpanFactoryInterface::class, ProfilingFactory::class);
};
