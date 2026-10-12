<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Simple;

use DateTime;
use DateTimeInterface;
use EchoFusion\TemplateManager\Exceptions\TemplateException;
use const JSON_THROW_ON_ERROR;
use const MB_CASE_TITLE;
use function array_keys;
use function array_reverse;
use function count;
use function end;
use function implode;
use function is_array;
use function is_countable;
use function is_int;
use function is_iterable;
use function is_string;
use function iterator_to_array;
use function json_encode;
use function number_format;
use function rawurlencode;
use function reset;
use function str_replace;
use function strrev;
use function trim;
use function ucfirst;

/**
 * The built-in filters of the simple template language.
 *
 * @internal
 */
final class Filters
{
    /**
     * @return array<string, callable>
     */
    public static function defaults(): array
    {
        return [
            'upper' => static fn (mixed $v): string => mb_strtoupper(self::str($v)),
            'lower' => static fn (mixed $v): string => mb_strtolower(self::str($v)),
            'capitalize' => static fn (mixed $v): string => ucfirst(mb_strtolower(self::str($v))),
            'title' => static fn (mixed $v): string => mb_convert_case(self::str($v), MB_CASE_TITLE),
            'trim' => static fn (mixed $v): string => trim(self::str($v)),
            'length' => static fn (mixed $v): int => is_countable($v) ? count($v) : mb_strlen(self::str($v)),
            'default' => static fn (mixed $v, mixed $default = ''): mixed => ($v === null || $v === '' || $v === false || $v === []) ? $default : $v,
            'join' => static fn (mixed $v, string $glue = ''): string => implode($glue, self::list($v)),
            'keys' => static fn (mixed $v): array => array_keys(self::list($v)),
            'first' => static function (mixed $v): mixed {
                if (is_string($v)) {
                    return $v === '' ? '' : mb_substr($v, 0, 1);
                }
                $list = self::list($v);

                return $list === [] ? null : reset($list);
            },
            'last' => static function (mixed $v): mixed {
                if (is_string($v)) {
                    return $v === '' ? '' : mb_substr($v, -1);
                }
                $list = self::list($v);

                return $list === [] ? null : end($list);
            },
            'reverse' => static fn (mixed $v): mixed => is_string($v) ? strrev($v) : array_reverse(self::list($v)),
            'date' => static fn (mixed $v, string $format = 'Y-m-d H:i'): string => self::date($v, $format),
            'json' => static fn (mixed $v): string => json_encode($v, JSON_THROW_ON_ERROR),
            'number' => static fn (mixed $v, int $decimals = 0, string $point = '.', string $thousands = ','): string => number_format((float) $v, $decimals, $point, $thousands),
            'truncate' => static function (mixed $v, int $length = 80, string $suffix = '…'): string {
                $string = self::str($v);
                $size = mb_strlen($string);
                if ($size <= $length) {
                    return $string;
                }

                return mb_substr($string, 0, $length) . $suffix;
            },
            'replace' => static fn (mixed $v, string $search, string $replace = ''): string => str_replace($search, $replace, self::str($v)),
            'url_encode' => static fn (mixed $v): string => rawurlencode(self::str($v)),
        ];
    }

    private static function str(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            throw new TemplateException('An array cannot be used as text.');
        }

        return (string) $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function list(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_iterable($value)) {
            return iterator_to_array($value, true);
        }

        return $value === null ? [] : [$value];
    }

    private static function date(mixed $value, string $format): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format($format);
        }
        if (is_int($value)) {
            return (new DateTime('@' . $value))->format($format);
        }

        return (new DateTime(self::str($value)))->format($format);
    }
}
