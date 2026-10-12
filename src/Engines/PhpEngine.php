<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Engines;

use EchoFusion\Contracts\TemplateManager\TemplateEngineInterface;
use EchoFusion\TemplateManager\Exceptions\TemplateNotFoundException;
use EchoFusion\TemplateManager\TemplateFinder;
use function sprintf;

/**
 * Plain PHP templates. Variables are available as local variables and `$this`
 * offers `e()`, `insert()`, `layout()`, `start()`/`stop()` and `section()`.
 */
final class PhpEngine implements TemplateEngineInterface
{
    /**
     * @param array<string, mixed> $options currently unused
     *
     * @psalm-suppress UnusedParam
     */
    public function __construct(
        private readonly TemplateFinder $finder,
        private readonly string $extension = 'php',
        array $options = [],
    ) {
    }

    public function render(string $name, array $params = []): string
    {
        return (new PhpTemplateContext($this))->renderFile($this->locate($name), $params);
    }

    public function exists(string $name): bool
    {
        return $this->finder->find($name, $this->extension) !== null;
    }

    /**
     * @internal
     *
     * @throws TemplateNotFoundException
     */
    public function locate(string $name): string
    {
        return $this->finder->find($name, $this->extension)
            ?? throw new TemplateNotFoundException(sprintf('Template "%s" (.%s) was not found.', $name, $this->extension));
    }
}
