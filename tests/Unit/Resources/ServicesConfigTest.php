<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ServicesConfigTest extends TestCase
{
    private const CONFIG_DIR = __DIR__ . '/../../../src/Resources/config';

    /**
     * A moved class leaves its exclude path pointing nowhere, and the loader then registers it silently.
     */
    public function testEveryExcludedPathExists(): void
    {
        $config = (string) file_get_contents(self::CONFIG_DIR . '/services.php');
        self::assertGreaterThan(0, preg_match_all("#'(\\.\\./\\.\\./[^']+)'#", $config, $matches));

        foreach ($matches[1] as $path) {
            $found = glob(self::CONFIG_DIR . '/' . rtrim($path, '/'));
            self::assertNotEmpty($found === false ? [] : $found, $path);
        }
    }
}
