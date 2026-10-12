<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Simple;

use ArrayAccess;
use Closure;
use EchoFusion\TemplateManager\Engines\SimpleEngine;
use EchoFusion\TemplateManager\Exceptions\TemplateException;
use ReflectionMethod;
use Stringable;
use Traversable;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use function array_key_exists;
use function count;
use function get_debug_type;
use function htmlspecialchars;
use function is_array;
use function is_callable;
use function is_object;
use function is_scalar;
use function iterator_to_array;
use function method_exists;
use function sprintf;
use function ucfirst;

/**
 * What compiled templates call at run time. Created once per top-level render.
 *
 * @internal
 */
final class Runtime
{
    private const MAX_INHERITANCE_DEPTH = 20;

    /**
     * @var array<string, Closure>
     */
    private array $blocks = [];

    private int $depth = 0;

    /**
     * @param array<string, callable> $filters
     */
    public function __construct(
        private readonly SimpleEngine $engine,
        private readonly bool $autoescape,
        private readonly array $filters,
    ) {
    }

    /**
     * Read `a.b.c`: array keys, ArrayAccess, public properties, then the getters getName(), isName() and hasName().
     */
    public function attr(mixed $base, string ...$path): mixed
    {
        foreach ($path as $key) {
            $base = self::read($base, $key);
        }

        return $base;
    }

    public function escape(mixed $value, bool $force = false): string
    {
        $string = self::text($value);

        return $this->autoescape || $force ? htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $string;
    }

    public function out(mixed $value): string
    {
        return self::text($value);
    }

    /**
     * @param list<mixed> $args first element is the value to filter
     */
    public function filter(string $name, array $args): mixed
    {
        if (!isset($this->filters[$name])) {
            throw new TemplateException(sprintf('Unknown filter "%s".', $name));
        }

        return ($this->filters[$name])(...$args);
    }

    /**
     * Rows for a loop: [key, value, loop info].
     *
     * @return list<array{0: mixed, 1: mixed, 2: array<string, mixed>}>
     */
    public function each(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if ($value instanceof Traversable) {
            $value = iterator_to_array($value, true);
        }
        if (!is_array($value)) {
            throw new TemplateException(sprintf('Cannot loop over a value of type "%s".', get_debug_type($value)));
        }

        $rows = [];
        $length = count($value);
        $index = 0;
        foreach ($value as $key => $item) {
            ++$index;
            $rows[] = [$key, $item, [
                'index' => $index,
                'index0' => $index - 1,
                'first' => $index === 1,
                'last' => $index === $length,
                'length' => $length,
            ]];
        }

        return $rows;
    }

    /**
     * Output a block: the override from a child template if there is one, otherwise the default.
     *
     * @param array<string, mixed> $vars
     */
    public function block(string $name, array $vars, Closure $default): void
    {
        ($this->blocks[$name] ?? $default)($vars);
    }

    /**
     * A child template registers its block; the most-derived template wins.
     */
    public function defineBlock(string $name, Closure $body): void
    {
        $this->blocks[$name] ??= $body;
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function include(mixed $name, array $vars): string
    {
        return $this->engine->render(self::text($name), $vars);
    }

    /**
     * Render the parent template with this runtime, so it sees the child's blocks.
     *
     * @param array<string, mixed> $vars
     */
    public function renderParent(string $name, array $vars): void
    {
        if (++$this->depth > self::MAX_INHERITANCE_DEPTH) {
            throw new TemplateException('Template inheritance is nested too deeply (is there a loop?).');
        }

        $this->engine->execute($name, $vars, $this);
        --$this->depth;
    }

    private static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_scalar($value) && !$value instanceof Stringable) {
            throw new TemplateException(sprintf('Cannot output a value of type "%s".', get_debug_type($value)));
        }

        return (string) $value;
    }

    private static function read(mixed $base, string $key): mixed
    {
        if (is_array($base)) {
            return array_key_exists($key, $base) ? $base[$key] : null;
        }
        if ($base instanceof ArrayAccess) {
            return $base->offsetExists($key) ? $base->offsetGet($key) : null;
        }
        if (!is_object($base)) {
            return null;
        }
        if (isset($base->$key)) {
            return $base->$key;
        }
        foreach (['get', 'is', 'has'] as $prefix) {
            $method = $prefix . ucfirst($key);
            if (method_exists($base, $method) && is_callable([$base, $method]) && (new ReflectionMethod($base, $method))->getNumberOfRequiredParameters() === 0) {
                return $base->$method();
            }
        }

        return null;
    }
}
