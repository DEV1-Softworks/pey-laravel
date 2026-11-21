<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Only the SDK parses webhook bodies, and only after verifying them. The
 * package reads nothing from a request but its raw body, its headers and the
 * attribute the middleware sets.
 */
#[CoversNothing]
final class WebhookEntryPointTest extends PHPUnitTestCase
{
    private const ALLOWED = ['getContent', 'headers->all', 'attributes->get', 'attributes->set'];

    public function testThePackageReadsOnlyTheRawBodyAndTheHeadersOfARequest(): void
    {
        $uses = [];
        foreach (self::sources() as $file => $source) {
            preg_match_all('/\$request->(\w+)(?:->(\w+))?/', $source, $matches, \PREG_SET_ORDER);

            foreach ($matches as $match) {
                $use = isset($match[2]) ? $match[1] . '->' . $match[2] : $match[1];
                self::assertContains($use, self::ALLOWED, \sprintf('%s reads $request->%s.', $file, $use));
                $uses[$use] = true;
            }
        }

        self::assertArrayHasKey('getContent', $uses);
    }

    public function testThePackageNeverDecodesJson(): void
    {
        foreach (self::sources() as $file => $source) {
            self::assertStringNotContainsString('json_decode', $source, $file);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function sources(): array
    {
        $sources = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            if ($file->getExtension() === 'php') {
                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                $sources[$file->getPathname()] = $source;
            }
        }

        self::assertNotSame([], $sources);

        return $sources;
    }
}
