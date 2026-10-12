<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Engines;

use EchoFusion\TemplateManager\Exceptions\TemplateException;
use Stringable;
use Throwable;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const EXTR_SKIP;
use function array_pop;
use function extract;
use function get_debug_type;
use function htmlspecialchars;
use function is_scalar;
use function ob_end_clean;
use function ob_get_clean;
use function ob_get_level;
use function ob_start;
use function sprintf;

/**
 * The `$this` of a plain PHP template: escaping, partials and layouts.
 *
 * @internal
 */
final class PhpTemplateContext
{
    /**
     * @var array<string, string>
     */
    private array $sections = [];

    /**
     * @var list<string>
     */
    private array $openSections = [];

    /**
     * @var array{0: string, 1: array<string, mixed>}|null
     */
    private ?array $layout = null;

    public function __construct(private readonly PhpEngine $engine)
    {
    }

    /**
     * Render a template file; honours a layout chosen with {@see layout()}.
     *
     * @param array<string, mixed> $params
     */
    public function renderFile(string $file, array $params): string
    {
        $output = $this->capture($file, $params);

        while ($this->layout !== null) {
            [$layoutName, $layoutParams] = $this->layout;
            $this->layout = null;
            $this->sections['content'] = $output;
            $params = $layoutParams + $params;
            $output = $this->capture($this->engine->locate($layoutName), $params);
        }

        return $output;
    }

    /**
     * Escape a value for HTML output.
     */
    public function e(mixed $value): string
    {
        if ($value !== null && !is_scalar($value) && !$value instanceof Stringable) {
            throw new TemplateException(sprintf('Cannot output a value of type "%s".', get_debug_type($value)));
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Render another template (a partial) and return its output.
     *
     * @param array<string, mixed> $params
     */
    public function insert(string $name, array $params = []): string
    {
        return $this->engine->render($name, $params);
    }

    /**
     * Wrap this template in a layout. The template output is available there as the "content" section.
     *
     * @param array<string, mixed> $params
     */
    public function layout(string $name, array $params = []): void
    {
        $this->layout = [$name, $params];
    }

    public function start(string $section): void
    {
        $this->openSections[] = $section;
        ob_start();
    }

    public function stop(): void
    {
        if ($this->openSections === []) {
            throw new TemplateException('stop() was called without a matching start().');
        }

        $section = array_pop($this->openSections);
        $this->sections[$section] = (string) ob_get_clean();
    }

    /**
     * The (already rendered, so not escaped again) content of a section.
     */
    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function capture(string $__file, array $params): string
    {
        extract($params, EXTR_SKIP);
        $level = ob_get_level();
        ob_start();

        try {
            include $__file;
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }

        return (string) ob_get_clean();
    }
}
