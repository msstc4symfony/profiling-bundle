<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel\AutoSpans;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\ProfilingBundle;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Kernel;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Enables every auto span whose library is installed; the environment PROFILING_FIRST registers the bundle before
 * DoctrineBundle.
 */
final class AutoSpansKernel extends Kernel
{
    use MicroKernelTrait;

    public const PROFILING_FIRST = 'profiling_first';

    // The application decorates the ProfilingFactory service, or the DetachedSpanFactoryInterface alias only.
    public const DECORATED_FACTORY = 'decorated_factory';

    public const DECORATED_ALIAS = 'decorated_alias';

    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-profiling-bundle-auto-spans-test-' . getmypid();
    }

    // The test connection is sqlite in memory.
    public static function hasDoctrine(): bool
    {
        return class_exists(DoctrineBundle::class) && extension_loaded('pdo_sqlite');
    }

    public static function hasHttpClient(): bool
    {
        return class_exists(HttpClient::class);
    }

    public static function hasGuzzle(): bool
    {
        return interface_exists(ClientInterface::class);
    }

    #[Override]
    public function registerBundles(): iterable
    {
        $doctrine = self::hasDoctrine() ? [new DoctrineBundle()] : [];

        return $this->environment === self::PROFILING_FIRST
            ? [new FrameworkBundle(), new ProfilingBundle(), ...$doctrine]
            : [new FrameworkBundle(), ...$doctrine, new ProfilingBundle()];
    }

    #[Override]
    public function getCacheDir(): string
    {
        return self::cacheRoot() . '/cache/' . $this->environment;
    }

    #[Override]
    public function getLogDir(): string
    {
        return self::cacheRoot() . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = [
            'secret' => 'test',
            'http_method_override' => false,
            'test' => true,
            'php_errors' => ['log' => false],
        ];
        if (self::hasHttpClient()) {
            $framework['http_client'] = [
                'mock_response_factory' => MockResponseFactory::class,
                'scoped_clients' => [
                    'test.scoped_client' => ['base_uri' => 'https://scoped.example.com'],
                    'test.retrying_client' => ['base_uri' => 'https://retry.example.com', 'retry_failed' => ['max_retries' => 1, 'delay' => 0]],
                ],
            ];
        }

        $container->extension('framework', $framework);
        $container->extension('msstc4symfony_profiling', [
            'auto_spans' => ['dbal' => self::hasDoctrine(), 'http_client' => self::hasHttpClient(), 'guzzle' => self::hasGuzzle()],
        ]);

        $services = $container->services();
        $services->set('test.recorder', RecordingEndProcessor::class)->public()->autoconfigure();

        if ($this->environment === self::DECORATED_FACTORY || $this->environment === self::DECORATED_ALIAS) {
            $services->set('test.factory_decorator', RecordingFactoryDecorator::class)
                ->decorate($this->environment === self::DECORATED_FACTORY ? ProfilingFactory::class : DetachedSpanFactoryInterface::class)
                ->args([service('.inner')])
                ->public()
            ;
        }

        if (self::hasDoctrine()) {
            $services->set(SpanOpeningMiddleware::class)->autowire()->tag('doctrine.middleware', ['connection' => 'replica']);
            $container->extension('doctrine', ['dbal' => [
                'default_connection' => 'default',
                'connections' => [
                    'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
                    'replica' => ['driver' => 'pdo_sqlite', 'memory' => true],
                ],
            ]]);
        }

        if (self::hasHttpClient()) {
            $services->set(MockResponseFactory::class);
            $services->alias('test.http_client', 'http_client')->public();
            $services->alias('test.scoped', 'test.scoped_client')->public();
            $services->alias('test.retrying', 'test.retrying_client')->public();
        }

        if (self::hasGuzzle()) {
            // Guzzle 8 rejects a "handler" next to this option: the client must be profiled without one.
            $services->set('test.guzzle_capped', Client::class)->public()->args([['max_host_connections' => 2]]);
            $services->set('test.guzzle', Client::class)
                ->public()
                ->args([[
                    'handler' => inline_service(HandlerStack::class)
                        ->factory(HandlerStack::create(...))
                        ->args([inline_service(MockHandler::class)->args([[inline_service(Response::class)->args([200]), inline_service(Response::class)->args([404])]])]),
                ]])
            ;
        }
    }
}
