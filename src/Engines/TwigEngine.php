<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Engines;

use EchoFusion\Contracts\TemplateManager\TemplateEngineInterface;
use EchoFusion\TemplateManager\Exceptions\TemplateException;
use EchoFusion\TemplateManager\Exceptions\TemplateNotFoundException;
use EchoFusion\TemplateManager\TemplateFinder;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Loader\FilesystemLoader;
use function class_exists;
use function serialize;
use function sprintf;

/**
 * Adapter for Twig. Twig is optional: install `twig/twig` to use it.
 *
 * The directories and namespaces of the {@see TemplateFinder} are given to Twig's
 * filesystem loader, so "@blog/posts/index" works the same way in both engines.
 * `options` are passed to `Twig\Environment` (e.g. `cache`, `debug`, `strict_variables`).
 */
final class TwigEngine implements TemplateEngineInterface
{
    private ?Environment $twig = null;

    private string $signature = '';

    /**
     * @param array<string, mixed> $options Twig\Environment options
     *
     * @throws TemplateException if twig/twig is not installed
     */
    public function __construct(
        private readonly TemplateFinder $finder,
        private readonly string $extension = 'twig',
        private readonly array $options = [],
    ) {
        if (!class_exists(Environment::class)) {
            throw new TemplateException('The Twig engine needs twig/twig. Run "composer require twig/twig".');
        }
    }

    public function render(string $name, array $params = []): string
    {
        if ($this->finder->find($name, $this->extension) === null) {
            throw new TemplateNotFoundException(sprintf('Template "%s" (.%s) was not found.', $name, $this->extension));
        }

        try {
            return $this->twig()->render($name . '.' . $this->extension, $params);
        } catch (Error $e) {
            throw new TemplateException($e->getMessage(), 0, $e);
        }
    }

    public function exists(string $name): bool
    {
        return $this->finder->find($name, $this->extension) !== null;
    }

    /**
     * The Twig environment, rebuilt when template directories were added since the last call.
     */
    public function twig(): Environment
    {
        $signature = serialize($this->finder->paths());
        if ($this->twig === null || $signature !== $this->signature) {
            $loader = new FilesystemLoader();
            foreach ($this->finder->paths() as $namespace => $directories) {
                foreach ($directories as $directory) {
                    $loader->addPath($directory, $namespace === '' ? FilesystemLoader::MAIN_NAMESPACE : $namespace);
                }
            }

            $this->twig = new Environment($loader, $this->options);
            $this->signature = $signature;
        }

        return $this->twig;
    }
}
