<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Tests;

use EchoFusion\TemplateManager\Exceptions\TemplateException;
use EchoFusion\TemplateManager\TemplateFinder;
use PHPUnit\Framework\TestCase;

final class FinderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures';

    public function testFindsPlainAndNamespacedTemplates(): void
    {
        $finder = new TemplateFinder();
        $finder->addPath(self::FIXTURES . '/tpl/');
        $finder->addPath(self::FIXTURES . '/blog', 'blog');

        $this->assertSame(self::FIXTURES . '/tpl/hello.tpl', $finder->find('hello', 'tpl'));
        $this->assertSame(self::FIXTURES . '/tpl/sub/page.tpl', $finder->find('sub/page', 'tpl'));
        $this->assertSame(self::FIXTURES . '/blog/post.tpl', $finder->find('@blog/post', 'tpl'));
        $this->assertNull($finder->find('hello', 'twig'));
        $this->assertNull($finder->find('missing', 'tpl'));
        $this->assertNull($finder->find('@unknown/post', 'tpl'));
        $this->assertNull($finder->find('@blog', 'tpl'));
        $this->assertSame(['' => [self::FIXTURES . '/tpl'], 'blog' => [self::FIXTURES . '/blog']], $finder->paths());
    }

    public function testNamespaceParsingWorksWithNonAsciiNames(): void
    {
        $dir = sys_get_temp_dir() . '/ef-finder-' . uniqid('', true);
        mkdir($dir . '/نوشته', 0775, true);
        file_put_contents($dir . '/نوشته/صفحه.tpl', 'x');
        $finder = new TemplateFinder();
        $finder->addPath($dir, 'بلاگ');

        $this->assertSame($dir . '/نوشته/صفحه.tpl', $finder->find('@بلاگ/نوشته/صفحه', 'tpl'));

        unlink($dir . '/نوشته/صفحه.tpl');
        rmdir($dir . '/نوشته');
        rmdir($dir);
    }

    public function testFirstDirectoryWins(): void
    {
        $finder = new TemplateFinder();
        $finder->addPath(self::FIXTURES . '/blog');
        $finder->addPath(self::FIXTURES . '/tpl');

        $this->assertSame(self::FIXTURES . '/tpl/hello.tpl', $finder->find('hello', 'tpl'));
        $this->assertSame(self::FIXTURES . '/blog/post.tpl', $finder->find('post', 'tpl'));
    }

    /**
     * @dataProvider unsafeNames
     */
    public function testRejectsUnsafeNames(string $name): void
    {
        $finder = new TemplateFinder();
        $finder->addPath(self::FIXTURES . '/tpl/sub');
        $finder->addPath(self::FIXTURES . '/blog', 'blog');

        $this->assertNull($finder->find($name, 'tpl'));
    }

    /**
     * @return array<string, array{string}>
     */
    public function unsafeNames(): array
    {
        return [
            'parent' => ['../hello'],
            'nested parent' => ['a/../../hello'],
            'absolute' => ['/etc/passwd'],
            'backslash' => ['..\\hello'],
            'nul byte' => ["page\0"],
            'dot segment' => ['./page'],
            'double slash' => ['a//b'],
            'empty' => [''],
            'namespaced parent' => ['@blog/../tpl/hello'],
        ];
    }

    public function testMissingDirectoryThrows(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('does not exist');

        (new TemplateFinder())->addPath(self::FIXTURES . '/nope');
    }
}
