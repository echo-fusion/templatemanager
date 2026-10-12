<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Tests;

use EchoFusion\Contracts\ServiceManager\SettableContainerInterface;
use EchoFusion\Contracts\TemplateManager\TemplateEngineInterface;
use EchoFusion\Contracts\TemplateManager\TemplateManagerInterface;
use EchoFusion\TemplateManager\Providers\TemplateManagerProvider;
use EchoFusion\TemplateManager\TemplateManager;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use function array_key_exists;
use function is_callable;

final class ProviderTest extends TestCase
{
    public function testRegisterAndBoot(): void
    {
        $container = new ArrayContainer();
        $provider = new TemplateManagerProvider();

        $provider->register($container);
        $this->assertInstanceOf(TemplateManager::class, $container->get(TemplateManagerInterface::class));

        $provider->boot($container, ['paths' => [__DIR__ . '/fixtures/tpl'], 'globals' => ['name' => 'P']]);

        $manager = $container->get(TemplateManagerInterface::class);
        $this->assertSame($manager, $container->get(TemplateManagerInterface::class));
        $this->assertInstanceOf(TemplateEngineInterface::class, $container->get(TemplateEngineInterface::class));
        $this->assertSame("Hello P!\n", $manager->render('hello'));
    }

    public function testBootRequiresTheBundledManager(): void
    {
        $container = new ArrayContainer();
        $container->set(TemplateManagerInterface::class, static fn () => new class() implements TemplateManagerInterface {
            public function render(string $name, array $params = []): string
            {
                return '';
            }

            public function exists(string $name): bool
            {
                return false;
            }

            public function addPath(string $path, ?string $namespace = null): void
            {
            }

            public function addGlobal(string $name, mixed $value): void
            {
            }
        });

        $this->expectException(LogicException::class);
        (new TemplateManagerProvider())->boot($container);
    }
}

final class ArrayContainer implements SettableContainerInterface
{
    /**
     * @var array<string, callable>
     */
    private array $factories = [];

    public function set(string $id, callable|string $service, bool $shared = false): void
    {
        $this->factories[$id] = is_callable($service) ? $service : static fn () => new $service();
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new class('missing') extends RuntimeException implements NotFoundExceptionInterface {
            };
        }

        return ($this->factories[$id])();
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->factories);
    }
}
