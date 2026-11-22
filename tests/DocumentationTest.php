<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Keeps the README and docs/ honest:
 * - every PHP example parses, and every SDK or package class it imports exists;
 * - every page has front matter for labs.dev1.mx;
 * - every relative link and anchor resolves;
 * - the configuration page lists exactly the variables config/pey.php reads.
 */
#[CoversNothing]
final class DocumentationTest extends PHPUnitTestCase
{
    private const ROOT = __DIR__ . '/..';

    public function testThereAreExamplesToCheck(): void
    {
        self::assertGreaterThanOrEqual(10, iterator_count(self::provideExamples()));
    }

    #[DataProvider('provideExamples')]
    public function testExamplesParse(string $file, string $code): void
    {
        try {
            $tokens = token_get_all('<?php ' . $code, \TOKEN_PARSE);
        } catch (\ParseError $e) {
            self::fail(\sprintf('A PHP example in %s does not parse: %s', $file, $e->getMessage()));
        }

        self::assertNotSame([], $tokens);
    }

    #[DataProvider('provideExamples')]
    public function testImportedClassesExist(string $file, string $code): void
    {
        preg_match_all('/^use (Dev1\\\\Pey\\\\[\w\\\\]+);/m', $code, $imports);

        foreach ($imports[1] as $class) {
            self::assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class),
                \sprintf('%s imports %s, which does not exist.', $file, $class),
            );
        }

        $this->addToAssertionCount(1);
    }

    #[DataProvider('providePages')]
    public function testEveryPageHasFrontMatter(string $file): void
    {
        $contents = self::read($file);

        self::assertMatchesRegularExpression('/\A---\ntitle: (.+)\ndescription: (.+)\n---\n\n# \1\n/', $contents, $file . ' needs a title and a description, and its heading must repeat the title.');
    }

    #[DataProvider('provideDocuments')]
    public function testRelativeLinksAndAnchorsResolve(string $file): void
    {
        $contents = self::read($file);
        preg_match_all('/\]\((?!https?:|mailto:)([^)#\s]*)(?:#([^)\s]+))?\)/', $contents, $links, \PREG_SET_ORDER);

        foreach ($links as $link) {
            $target = $link[1] === '' ? $file : \dirname($file) . '/' . $link[1];
            self::assertFileExists(self::ROOT . '/' . $target, \sprintf('%s links to %s, which does not exist.', $file, $link[1]));

            if (isset($link[2])) {
                self::assertContains($link[2], self::anchors(self::read($target)), \sprintf('%s links to #%s in %s, which has no such heading.', $file, $link[2], $target));
            }
        }

        $this->addToAssertionCount(1);
    }

    public function testTheConfigurationPageListsEveryEnvironmentVariable(): void
    {
        preg_match_all("/env\\('(PEY_[A-Z_]+)'/", self::read('config/pey.php'), $read);
        preg_match_all('/^\| `(PEY_[A-Z_]+)` \|/m', self::read('docs/configuration.md'), $documented);

        $read = array_unique($read[1]);
        sort($read);
        $documented = $documented[1];
        sort($documented);

        self::assertNotSame([], $read);
        self::assertSame($read, $documented);
    }

    public function testTheReadmeLinksEveryPage(): void
    {
        $readme = self::read('README.md');

        foreach (self::pages() as $page) {
            self::assertStringContainsString('](' . $page . ')', $readme, 'The README must link ' . $page . '.');
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideExamples(): iterable
    {
        foreach (self::documents() as $file) {
            preg_match_all('/```php\n(.*?)```/s', self::read($file), $blocks);
            foreach ($blocks[1] as $index => $code) {
                yield \sprintf('%s #%d', $file, $index + 1) => [$file, $code];
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePages(): iterable
    {
        foreach (self::pages() as $page) {
            yield $page => [$page];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDocuments(): iterable
    {
        foreach (self::documents() as $file) {
            yield $file => [$file];
        }
    }

    /**
     * @return list<string> paths relative to the repository root
     */
    private static function pages(): array
    {
        $pages = glob(self::ROOT . '/docs/*.md');
        self::assertIsArray($pages);
        self::assertNotSame([], $pages);

        return array_map(static fn(string $page): string => 'docs/' . basename($page), $pages);
    }

    /**
     * @return list<string>
     */
    private static function documents(): array
    {
        return ['README.md', ...self::pages()];
    }

    private static function read(string $file): string
    {
        $contents = file_get_contents(self::ROOT . '/' . $file);
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * The anchors GitHub generates for a page's headings.
     *
     * @return list<string>
     */
    private static function anchors(string $markdown): array
    {
        preg_match_all('/^#{1,6} (.+)$/m', $markdown, $headings);

        return array_map(
            static fn(string $heading): string => str_replace(' ', '-', (string) preg_replace('/[^a-z0-9 _-]/', '', strtolower($heading))),
            $headings[1],
        );
    }
}
