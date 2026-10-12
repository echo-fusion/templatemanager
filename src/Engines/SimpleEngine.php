<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Engines;

use Closure;
use EchoFusion\Contracts\TemplateManager\TemplateEngineInterface;
use EchoFusion\TemplateManager\Exceptions\TemplateException;
use EchoFusion\TemplateManager\Exceptions\TemplateNotFoundException;
use EchoFusion\TemplateManager\Simple\Compiler;
use EchoFusion\TemplateManager\Simple\Filters;
use EchoFusion\TemplateManager\Simple\Runtime;
use EchoFusion\TemplateManager\TemplateFinder;
use Throwable;
use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function filesize;
use function glob;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function ob_end_clean;
use function ob_get_clean;
use function ob_get_level;
use function ob_start;
use function rename;
use function sha1;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

/**
 * The built-in template language: `{{ value|filter }}`, `{% if %}`, `{% for %}`,
 * `{% extends %}`/`{% block %}`, `{% include %}`, `{% set %}` and `{# comments #}`.
 *
 * Templates are compiled to PHP files in a cache directory. Options:
 *
 * - `cache` (string|null): cache directory, default `<system temp dir>/echofusion-templates`
 * - `auto_reload` (bool, default true): recompile when the template file changes
 * - `autoescape` (bool, default true): HTML-escape `{{ }}` output
 */
final class SimpleEngine implements TemplateEngineInterface
{
    private const MAX_RENDER_DEPTH = 50;

    private readonly string $cacheDirectory;

    private readonly bool $autoReload;

    private readonly bool $autoescape;

    /**
     * @var array<string, callable>
     */
    private array $filters;

    /**
     * @var array<string, Closure> compiled templates by cache file
     */
    private array $loaded = [];

    private int $depth = 0;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly TemplateFinder $finder,
        private readonly string $extension = 'tpl',
        array $options = [],
    ) {
        $cache = $options['cache'] ?? null;
        $this->cacheDirectory = is_string($cache) && $cache !== '' ? $cache : sys_get_temp_dir() . '/echofusion-templates';
        $this->autoReload = (bool) ($options['auto_reload'] ?? true);
        $this->autoescape = (bool) ($options['autoescape'] ?? true);
        $this->filters = Filters::defaults();
    }

    /**
     * Register or replace a filter: `{{ value|name(arg) }}` calls `$filter($value, $arg)`.
     */
    public function addFilter(string $name, callable $filter): void
    {
        $this->filters[$name] = $filter;
    }

    public function exists(string $name): bool
    {
        return $this->finder->find($name, $this->extension) !== null;
    }

    public function render(string $name, array $params = []): string
    {
        if (++$this->depth > self::MAX_RENDER_DEPTH) {
            $this->depth = 0;

            throw new TemplateException('Templates are included too deeply (is there a loop?).');
        }

        $level = ob_get_level();
        ob_start();

        try {
            $this->execute($name, $params, new Runtime($this, $this->autoescape, $this->filters));
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        } finally {
            if ($this->depth > 0) {
                --$this->depth;
            }
        }

        return (string) ob_get_clean();
    }

    /**
     * Run a compiled template, writing to the current output buffer.
     *
     * @internal used by {@see Runtime}
     *
     * @param array<string, mixed> $vars
     */
    public function execute(string $name, array $vars, Runtime $runtime): void
    {
        ($this->load($name))($runtime, $vars);
    }

    private function load(string $name): Closure
    {
        $file = $this->finder->find($name, $this->extension)
            ?? throw new TemplateNotFoundException(sprintf('Template "%s" (.%s) was not found.', $name, $this->extension));

        $key = sha1($file);
        $version = '';
        if ($this->autoReload) {
            // long-running workers would otherwise see a stale modification time
            clearstatcache(true, $file);
            $version = sprintf('.%d-%d', (int) filemtime($file), (int) filesize($file));
        }
        $cacheFile = sprintf('%s/%s%s.php', $this->cacheDirectory, $key, $version);

        if (isset($this->loaded[$cacheFile])) {
            return $this->loaded[$cacheFile];
        }

        if (!is_file($cacheFile)) {
            $source = file_get_contents($file);
            if ($source === false) {
                throw new TemplateException(sprintf('Template file "%s" could not be read.', $file));
            }

            $this->write($cacheFile, (new Compiler())->compile($source, $name), $key);
        }

        $compiled = require $cacheFile;
        if (!$compiled instanceof Closure) {
            throw new TemplateException(sprintf('Compiled template "%s" is corrupt; delete the cache directory.', $cacheFile));
        }

        return $this->loaded[$cacheFile] = $compiled;
    }

    private function write(string $cacheFile, string $code, string $key): void
    {
        if (!is_dir($this->cacheDirectory) && !@mkdir($this->cacheDirectory, 0775, true) && !is_dir($this->cacheDirectory)) {
            throw new TemplateException(sprintf('Template cache directory "%s" could not be created.', $this->cacheDirectory));
        }

        $temporary = sprintf('%s.%s.tmp', $cacheFile, sha1($code . $cacheFile));
        if (file_put_contents($temporary, $code) === false || !rename($temporary, $cacheFile)) {
            throw new TemplateException(sprintf('Compiled template "%s" could not be written.', $cacheFile));
        }

        // drop stale versions of the same template
        foreach (glob(sprintf('%s/%s.*.php', $this->cacheDirectory, $key)) ?: [] as $old) {
            if ($old !== $cacheFile) {
                @unlink($old);
            }
        }
    }
}
