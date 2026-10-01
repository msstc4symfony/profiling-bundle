<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel;

use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leaves a root span open, as application code that forgets end() does; only the
 * terminate-time endAll() can close it.
 */
#[AsCommand('test:orphan')]
final class OrphanSpanOpener extends Command
{
    public function __construct(
        private readonly ProfilingFactoryInterface $profilingFactory,
    ) {
        parent::__construct();
    }

    public function controller(): Response
    {
        $this->profilingFactory->createSpan('orphan');

        return new Response('ok');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->profilingFactory->createSpan('orphan');

        return Command::SUCCESS;
    }
}
