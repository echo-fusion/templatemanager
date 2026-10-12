<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager;

use EchoFusion\TemplateManager\Exceptions\TemplateException;
use function array_key_exists;
use function is_dir;
use function is_file;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_contains;
use function strpos;
use function substr;

/**
 * Maps template names to files.
 *
 * A name is either plain ("home", "auth/login") and searched in the default
 * directories, or namespaced ("@blog/posts/index") and searched in the
 * directories added for that namespace. Directories are searched in the order
 * they were added.
 */
final class TemplateFinder
{
    /**
     * @var array<string, list<string>> namespace => directories, the default namespace is ""
     */
    private array $paths = [];

    /**
     * @throws TemplateException if the directory does not exist
     */
    public function addPath(string $path, ?string $namespace = null): void
    {
        if (!is_dir($path)) {
            throw new TemplateException(sprintf('Template directory "%s" does not exist.', $path));
        }

        $this->paths[$namespace ?? ''][] = rtrim($path, '/\\');
    }

    /**
     * @return array<string, list<string>> namespace => directories, the default namespace is ""
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * Absolute file for the template, or null when it does not exist or the name is not valid.
     */
    public function find(string $name, string $extension): ?string
    {
        [$namespace, $relative] = self::split($name);

        if (!self::isSafe($relative) || !array_key_exists($namespace, $this->paths)) {
            return null;
        }

        foreach ($this->paths[$namespace] as $directory) {
            $file = sprintf('%s/%s.%s', $directory, $relative, $extension);
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string} namespace ("" for none) and the name inside it
     */
    private static function split(string $name): array
    {
        if (!str_contains($name, '@') || $name[0] !== '@') {
            return ['', $name];
        }

        $slash = strpos($name, '/');
        if ($slash === false) {
            return [substr($name, 1), ''];
        }

        return [substr($name, 1, $slash - 1), substr($name, $slash + 1)];
    }

    /**
     * Reject empty names, absolute paths, backslashes, NUL bytes and "." / ".." segments.
     */
    private static function isSafe(string $relative): bool
    {
        return $relative !== ''
            && preg_match('#(^/|\\\\|\x00|(^|/)\.{1,2}(/|$)|//)#', $relative) !== 1;
    }
}
