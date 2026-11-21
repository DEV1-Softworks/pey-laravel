<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests;

use Dev1\Pey\Laravel\PeyServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Guards the package metadata that consumers and Packagist rely on.
 */
#[CoversNothing]
final class PackageMetadataTest extends PHPUnitTestCase
{
    private const LARAVEL_CONSTRAINT = '^12.0 || ^13.0';

    public function testThePackageIsNamedAndDescribedAsUnofficial(): void
    {
        $composer = self::composer();

        self::assertSame('dev1/pey-laravel', $composer['name'] ?? null);
        self::assertSame('MIT', $composer['license'] ?? null);
        self::assertIsString($composer['description'] ?? null);
        self::assertStringStartsWith('Unofficial Laravel integration for the Pey API', $composer['description']);
        self::assertStringContainsString('Not affiliated with Pey or Banco de México', $composer['description']);
    }

    public function testItRequiresTheCoreAndOnlySupportedLaravelVersions(): void
    {
        $require = self::section('require');

        self::assertSame('^8.2', $require['php'] ?? null);
        self::assertSame('^0.1.1', $require['dev1/pey-php-sdk'] ?? null);
        self::assertArrayNotHasKey('laravel/framework', $require, 'Depend on illuminate/* components, not the whole framework.');

        $illuminate = array_filter($require, static fn(string $package): bool => str_starts_with($package, 'illuminate/'), \ARRAY_FILTER_USE_KEY);
        self::assertNotSame([], $illuminate);
        foreach ($illuminate as $package => $constraint) {
            self::assertSame(self::LARAVEL_CONSTRAINT, $constraint, $package . ' must support exactly Laravel 12 and 13.');
        }
    }

    public function testTheTestbenchVersionsMatchTheSupportedLaravelVersions(): void
    {
        self::assertSame('^10.0 || ^11.0', self::section('require-dev')['orchestra/testbench'] ?? null);
    }

    /**
     * CI and local installs of this repository are not real installs of the
     * SDK or of anything else, so they are not reported to Packagist.
     */
    public function testInstallsOfThisRepositoryAreNotReportedToPackagist(): void
    {
        self::assertFalse(self::section('config')['notify-on-install'] ?? null);
    }

    public function testTheNamespaceIsAutoloadedFromSrc(): void
    {
        $autoload = self::section('autoload');

        self::assertSame(['psr-4' => ['Dev1\\Pey\\Laravel\\' => 'src/']], $autoload);
    }

    public function testTheServiceProviderIsAutoDiscovered(): void
    {
        $extra = self::section('extra');

        self::assertSame(['providers' => [PeyServiceProvider::class]], array_intersect_key(
            \is_array($extra['laravel'] ?? null) ? $extra['laravel'] : [],
            ['providers' => true],
        ));
    }

    public function testSecretsAndMachineSettingsAreIgnoredByGit(): void
    {
        $lines = file(\dirname(__DIR__) . '/.gitignore', \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        self::assertContains('.env*', $lines);
        self::assertContains('/CLAUDE.local.md', $lines);
    }

    public function testCiRunsOnDevelopAndMasterAndNeverOnMain(): void
    {
        $workflow = file_get_contents(\dirname(__DIR__) . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);

        self::assertSame(2, substr_count($workflow, 'branches: [develop, master]'));
        self::assertDoesNotMatchRegularExpression('/\bmain\b/', $workflow);
    }

    /**
     * @return array<string, mixed>
     */
    private static function section(string $name): array
    {
        $section = self::composer()[$name] ?? null;
        self::assertIsArray($section, \sprintf('composer.json has no "%s" object.', $name));

        $typed = [];
        foreach ($section as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function composer(): array
    {
        $json = file_get_contents(\dirname(__DIR__) . '/composer.json');
        self::assertIsString($json);

        $composer = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        return $composer;
    }
}
