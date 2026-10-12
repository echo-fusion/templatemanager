<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager;

use EchoFusion\Contracts\ConfigManager\ConfigInterface;
use EchoFusion\Contracts\TemplateManager\TemplateEngineInterface;
use EchoFusion\Contracts\TemplateManager\TemplateManagerInterface;
use EchoFusion\TemplateManager\Engines\PhpEngine;
use EchoFusion\TemplateManager\Engines\SimpleEngine;
use EchoFusion\TemplateManager\Engines\TwigEngine;
use EchoFusion\TemplateManager\Exceptions\InvalidConfigException;
use EchoFusion\TemplateManager\Exceptions\TemplateNotFoundException;
use function array_keys;
use function array_merge;
use function implode;
use function is_a;
use function is_array;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function sprintf;
use function str_ends_with;

/**
 * Renders templates through one or more engines, selected by file extension.
 *
 * Engines are tried in the order they were added; the first one that has the
 * template wins. A name that already ends with a registered extension
 * ("home.twig") goes straight to that engine.
 */
final class TemplateManager implements TemplateManagerInterface
{
    /**
     * Engines available by extension when the configuration does not name a class.
     */
    public const DEFAULT_ENGINES = [
        'tpl' => SimpleEngine::class,
        'php' => PhpEngine::class,
        'twig' => TwigEngine::class,
    ];

    private readonly TemplateFinder $finder;

    /**
     * @var array<string, TemplateEngineInterface> extension => engine, in lookup order
     */
    private array $engines = [];

    /**
     * @var array<string, mixed>
     */
    private array $globals = [];

    public function __construct(?TemplateFinder $finder = null)
    {
        $this->finder = $finder ?? new TemplateFinder();
    }

    /**
     * Build a manager from configuration. See {@see configure()} for the format.
     *
     * @param array<string, mixed>|ConfigInterface $config
     *
     * @throws InvalidConfigException
     */
    public static function fromConfig(array|ConfigInterface $config): self
    {
        $manager = new self();
        $manager->configure($config);

        return $manager;
    }

    /**
     * Apply configuration:
     *
     * <code>
     * [
     *     'paths' => ['/app/templates', 'blog' => '/modules/blog/templates'], // string keys are namespaces
     *     'engines' => [
     *         'tpl' => [],                                              // built-in template language
     *         'php' => ['options' => []],                               // plain PHP templates
     *         'twig' => ['options' => ['cache' => '/tmp/twig']],        // needs twig/twig
     *         'blade' => ['class' => MyEngine::class, 'options' => []], // any TemplateEngineInterface
     *     ],
     *     'globals' => ['appName' => 'Echo'],
     * ]
     * </code>
     *
     * An engine class is created as `new Class(TemplateFinder $finder, string $extension, array $options)`.
     * Without an `engines` key the built-in "tpl" and "php" engines are used.
     *
     * @param array<string, mixed>|ConfigInterface $config the whole array, or the template section of the application config
     *
     * @throws InvalidConfigException
     */
    public function configure(array|ConfigInterface $config): void
    {
        $config = $config instanceof ConfigInterface ? $config->all() : $config;

        $paths = $config['paths'] ?? [];
        if (!is_array($paths)) {
            throw new InvalidConfigException('The "paths" setting must be an array.');
        }
        foreach ($paths as $key => $path) {
            if (!is_string($path) || $path === '') {
                throw new InvalidConfigException('Every entry of "paths" must be a non-empty directory string.');
            }
            $this->addPath($path, is_string($key) && $key !== '' ? $key : null);
        }

        $globals = $config['globals'] ?? [];
        if (!is_array($globals)) {
            throw new InvalidConfigException('The "globals" setting must be an array.');
        }
        foreach ($globals as $name => $value) {
            $this->addGlobal((string) $name, $value);
        }

        $engines = $config['engines'] ?? ($this->engines === [] ? ['tpl' => [], 'php' => []] : []);
        if (!is_array($engines)) {
            throw new InvalidConfigException('The "engines" setting must be an array.');
        }
        foreach ($engines as $extension => $definition) {
            $this->addEngine((string) $extension, $this->createEngine((string) $extension, $definition));
        }
    }

    public function addPath(string $path, ?string $namespace = null): void
    {
        $this->finder->addPath($path, $namespace);
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->globals[$name] = $value;
    }

    public function addEngine(string $extension, TemplateEngineInterface $engine): void
    {
        $this->engines[$extension] = $engine;
    }

    public function getFinder(): TemplateFinder
    {
        return $this->finder;
    }

    public function exists(string $name): bool
    {
        return $this->resolve($name) !== null;
    }

    public function render(string $name, array $params = []): string
    {
        $resolved = $this->resolve($name);
        if ($resolved === null) {
            throw new TemplateNotFoundException(sprintf(
                'Template "%s" was not found (engines: %s).',
                $name,
                $this->engines === [] ? 'none configured' : implode(', ', array_keys($this->engines)),
            ));
        }

        [$engine, $engineName] = $resolved;

        return $engine->render($engineName, array_merge($this->globals, $params));
    }

    /**
     * @return array{0: TemplateEngineInterface, 1: string}|null the engine and the name to give it
     */
    private function resolve(string $name): ?array
    {
        foreach ($this->engines as $extension => $engine) {
            $suffix = '.' . $extension;
            if (str_ends_with($name, $suffix)) {
                $bare = mb_substr($name, 0, -mb_strlen($suffix));

                return $bare !== '' && $engine->exists($bare) ? [$engine, $bare] : null;
            }
        }

        foreach ($this->engines as $engine) {
            if ($engine->exists($name)) {
                return [$engine, $name];
            }
        }

        return null;
    }

    /**
     * @throws InvalidConfigException
     */
    private function createEngine(string $extension, mixed $definition): TemplateEngineInterface
    {
        if (is_string($definition)) {
            $definition = ['class' => $definition];
        }
        if (!is_array($definition)) {
            throw new InvalidConfigException(sprintf('The engine definition for "%s" must be an array or a class name.', $extension));
        }

        $class = $definition['class'] ?? self::DEFAULT_ENGINES[$extension] ?? null;
        if (!is_string($class) || !is_a($class, TemplateEngineInterface::class, true)) {
            throw new InvalidConfigException(sprintf(
                'No engine class for extension "%s": set "class" to a %s implementation.',
                $extension,
                TemplateEngineInterface::class,
            ));
        }

        $options = $definition['options'] ?? [];
        if (!is_array($options)) {
            throw new InvalidConfigException(sprintf('The options of engine "%s" must be an array.', $extension));
        }

        return new $class($this->finder, $extension, $options);
    }
}
