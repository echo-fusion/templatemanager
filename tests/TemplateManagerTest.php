<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Tests;

use EchoFusion\Contracts\ConfigManager\ConfigInterface;
use EchoFusion\Contracts\TemplateManager\TemplateEngineInterface;
use EchoFusion\TemplateManager\Engines\PhpEngine;
use EchoFusion\TemplateManager\Engines\SimpleEngine;
use EchoFusion\TemplateManager\Exceptions\InvalidConfigException;
use EchoFusion\TemplateManager\Exceptions\TemplateNotFoundException;
use EchoFusion\TemplateManager\TemplateFinder;
use EchoFusion\TemplateManager\TemplateManager;
use PHPUnit\Framework\TestCase;
use stdClass;
use function sys_get_temp_dir;

final class TemplateManagerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures';

    public function testFromConfigWithDefaultEnginesPathsNamespacesAndGlobals(): void
    {
        $manager = TemplateManager::fromConfig([
            'paths' => [self::FIXTURES . '/tpl', self::FIXTURES . '/php', 'blog' => self::FIXTURES . '/blog'],
            'globals' => ['name' => 'Global'],
        ]);

        $this->assertSame("Hello Global!\n", $manager->render('hello'));
        $this->assertSame("Hello Local!\n", $manager->render('hello', ['name' => 'Local']));
        $this->assertSame("<p>Global</p>\n", $manager->render('plain'));
        $this->assertSame("Post: T\n", $manager->render('@blog/post', ['title' => 'T']));
        $this->assertTrue($manager->exists('hello'));
        $this->assertTrue($manager->exists('plain.php'));
        $this->assertFalse($manager->exists('missing'));
        $this->assertSame(['' => [self::FIXTURES . '/tpl', self::FIXTURES . '/php'], 'blog' => [self::FIXTURES . '/blog']], $manager->getFinder()->paths());
    }

    public function testConfigObjectIsAccepted(): void
    {
        $config = new class() implements ConfigInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function has(string $key): bool
            {
                return true;
            }

            public function all(): array
            {
                return ['paths' => [__DIR__ . '/fixtures/tpl']];
            }
        };

        $this->assertSame("Hello A!\n", TemplateManager::fromConfig($config)->render('hello', ['name' => 'A']));
    }

    public function testAddPathAndGlobalAtRuntime(): void
    {
        $manager = new TemplateManager();
        $manager->addEngine('tpl', new SimpleEngine($manager->getFinder(), 'tpl', ['cache' => sys_get_temp_dir() . '/ef-manager-cache']));
        $manager->addPath(self::FIXTURES . '/blog', 'blog');
        $manager->addGlobal('title', 'Late');

        $this->assertSame("Post: Late\n", $manager->render('@blog/post'));
    }

    public function testEnginesAreTriedInOrderAndExtensionPicksTheEngine(): void
    {
        $manager = TemplateManager::fromConfig([
            'paths' => [self::FIXTURES . '/both'],
            'engines' => ['php' => [], 'tpl' => ['options' => ['cache' => sys_get_temp_dir() . '/ef-manager-cache']]],
        ]);
        $both = self::FIXTURES . '/both';

        $this->assertSame('php-version', $manager->render('same'));
        $this->assertSame('tpl-version', $manager->render('same.tpl'));
        $this->assertSame('php-version', $manager->render('same.php'));
        $this->assertTrue($manager->exists($both === '' ? 'x' : 'same'));
    }

    public function testUnknownExtensionSuffixAndEmptyBareNameAreNotFound(): void
    {
        $manager = TemplateManager::fromConfig(['paths' => [self::FIXTURES . '/tpl']]);

        $this->assertFalse($manager->exists('hello.twig'));
        $this->assertFalse($manager->exists('.tpl'));
        $this->assertFalse($manager->exists('missing.tpl'));
    }

    public function testNotFoundListsEngines(): void
    {
        $manager = TemplateManager::fromConfig(['paths' => [self::FIXTURES . '/tpl']]);

        $this->expectException(TemplateNotFoundException::class);
        $this->expectExceptionMessage('Template "nope" was not found (engines: tpl, php).');
        $manager->render('nope');
    }

    public function testNotFoundWithoutEngines(): void
    {
        $this->expectException(TemplateNotFoundException::class);
        $this->expectExceptionMessage('none configured');

        (new TemplateManager())->render('nope');
    }

    public function testCustomEngineClassFromConfig(): void
    {
        $manager = TemplateManager::fromConfig([
            'paths' => [self::FIXTURES . '/tpl'],
            'engines' => ['custom' => ['class' => UpperEngine::class, 'options' => ['suffix' => '!']], 'shorthand' => UpperEngine::class],
        ]);

        $this->assertSame('CUSTOM:HELLO:!', $manager->render('hello.custom'));
        $this->assertSame('CUSTOM:HELLO:', $manager->render('hello.shorthand'));
    }

    /**
     * @dataProvider invalidConfigs
     *
     * @param array<string, mixed> $config
     */
    public function testInvalidConfig(array $config, string $message): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($message);

        TemplateManager::fromConfig($config);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public function invalidConfigs(): array
    {
        return [
            'paths not array' => [['paths' => 'x'], '"paths" setting must be an array'],
            'path not string' => [['paths' => [5]], 'non-empty directory string'],
            'path empty' => [['paths' => ['']], 'non-empty directory string'],
            'globals not array' => [['globals' => 'x'], '"globals" setting must be an array'],
            'engines not array' => [['engines' => 'x'], '"engines" setting must be an array'],
            'engine definition invalid' => [['engines' => ['tpl' => 5]], 'must be an array or a class name'],
            'unknown extension without class' => [['engines' => ['blade' => []]], 'No engine class for extension "blade"'],
            'class not an engine' => [['engines' => ['x' => ['class' => stdClass::class]]], 'No engine class for extension "x"'],
            'class does not exist' => [['engines' => ['x' => ['class' => 'Nope\\Missing']]], 'No engine class for extension "x"'],
            'options not array' => [['engines' => ['tpl' => ['options' => 'x']]], 'options of engine "tpl" must be an array'],
        ];
    }

    public function testBuiltInEngineMap(): void
    {
        $this->assertSame(SimpleEngine::class, TemplateManager::DEFAULT_ENGINES['tpl']);
        $this->assertSame(PhpEngine::class, TemplateManager::DEFAULT_ENGINES['php']);
    }
}

final class UpperEngine implements TemplateEngineInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(private TemplateFinder $finder, private string $extension, private array $options = [])
    {
    }

    public function render(string $name, array $params = []): string
    {
        return 'CUSTOM:' . mb_strtoupper($name) . ':' . ($this->options['suffix'] ?? '');
    }

    public function exists(string $name): bool
    {
        return $this->finder->find($name, 'tpl') !== null;
    }
}
