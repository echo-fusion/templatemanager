<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Simple;

use EchoFusion\TemplateManager\Exceptions\TemplateSyntaxException;
use ParseError;
use const PREG_SPLIT_DELIM_CAPTURE;
use const TOKEN_PARSE;
use function array_map;
use function array_pop;
use function array_shift;
use function array_slice;
use function count;
use function explode;
use function implode;
use function in_array;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function mb_substr_count;
use function preg_match;
use function preg_split;
use function rtrim;
use function sprintf;
use function str_replace;
use function token_get_all;
use function trim;
use function var_export;

/**
 * Compiles the simple template language to a PHP file that returns a closure.
 *
 * Syntax:
 *   {{ expression|filter(arg) }}   output, HTML-escaped unless the |raw filter is used
 *   {# comment #}
 *   {% if a %}..{% elseif b %}..{% else %}..{% endif %}
 *   {% for item in items %}..{% else %}..{% endfor %}   (also: {% for key, item in items %}, loop.index ...)
 *   {% set name = expression %}
 *   {% extends "layout" %} / {% block name %}..{% endblock %}
 *   {% include "partial" %}
 *
 * Expressions are a safe subset: variables (a.b.c), strings, numbers, true/false/null,
 * [lists], comparison and arithmetic operators, and/or/not, ?: and ??. They are translated
 * token by token, so no template can inject PHP.
 *
 * @internal
 */
final class Compiler
{
    private const TOKEN = '#\G\s*(?:(?<str>\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")|(?<num>\d+(?:\.\d+)?)|(?<id>[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*)|(?<op>===|!==|==|!=|<=|>=|&&|\|\||\?\?|<|>|\+|-|\*|/|%|!|~|\?|:)|(?<punc>[()\[\],]))#A';

    private int $counter = 0;

    /**
     * @var list<array{type: string, line: int, id: int, hasElse: bool}>
     */
    private array $stack = [];

    private string $name = '';

    private int $line = 1;

    /**
     * @throws TemplateSyntaxException
     */
    public function compile(string $source, string $name): string
    {
        $this->counter = 0;
        $this->stack = [];
        $this->name = $name;
        $this->line = 1;

        $parent = null;
        if (preg_match('~^\s*\{%\s*extends\s+(?:\'([^\']+)\'|"([^"]+)")\s*%\}~', $source, $m) === 1) {
            $parent = $m[2] ?? $m[1];
            $source = mb_substr($source, mb_strlen($m[0]));
            $this->line += mb_substr_count($m[0], "\n");
        }

        $code = '';
        $blockDepth = 0;
        $parts = preg_split('/(\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\})/s', $source, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        foreach ($parts as $part) {
            $lines = mb_substr_count($part, "\n");

            if ($part === '') {
                continue;
            }

            $kind = mb_substr($part, 0, 2);
            $suppressed = $parent !== null && $blockDepth === 0;

            if ($kind === '{#') {
                // comment
            } elseif ($kind === '{{') {
                if ($suppressed) {
                    $this->fail('Only {% block %} and {% set %} are allowed outside blocks in a template that extends another.');
                }
                $code .= $this->output(mb_substr($part, 2, -2));
            } elseif ($kind === '{%') {
                $code .= $this->tag(trim(mb_substr($part, 2, -2)), $parent, $blockDepth);
            } elseif (!$suppressed) {
                $code .= sprintf("echo %s;\n", var_export($part, true));
            } elseif (trim($part) !== '') {
                $this->fail('Only {% block %} and {% set %} are allowed outside blocks in a template that extends another.');
            }

            $this->line += $lines;
        }

        $open = $this->top();
        if ($open !== null) {
            $this->line = $open['line'];
            $this->fail(sprintf('Unclosed {%% %s %%} tag.', $open['type']));
        }

        if ($parent !== null) {
            $code .= sprintf("\$t->renderParent(%s, \$v);\n", var_export($parent, true));
        }

        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn static function (\\EchoFusion\\TemplateManager\\Simple\\Runtime \$t, array \$v): void {\n" . $code . "};\n";

        // the token-by-token translation only allows safe tokens; this also rejects malformed combinations such as "a->b" or "1 +"
        try {
            /** @psalm-suppress UnusedFunctionCall */
            token_get_all($php, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new TemplateSyntaxException(sprintf('Invalid expression in "%s": %s.', $name, rtrim($e->getMessage(), '.')), 0, $e);
        }

        return $php;
    }

    private function tag(string $tag, ?string $parent, int &$blockDepth): string
    {
        $word = mb_strtolower(preg_split('/\s+/', $tag, 2)[0] ?? '');
        $rest = trim(mb_substr($tag, mb_strlen($word)));

        if ($parent !== null && $blockDepth === 0 && $word !== 'block' && $word !== 'set') {
            $this->fail('Only {% block %} and {% set %} are allowed outside blocks in a template that extends another.');
        }

        switch ($word) {
            case 'if':
                $this->stack[] = ['type' => 'if', 'line' => $this->line, 'id' => 0, 'hasElse' => false];

                return sprintf("if (%s) {\n", $this->expression($rest));
            case 'elseif':
                $this->expect('if', 'elseif');

                return sprintf("} elseif (%s) {\n", $this->expression($rest));
            case 'else':
                return $this->elseTag();
            case 'endif':
                $this->close('if', 'endif');

                return "}\n";
            case 'for':
                return $this->forTag($rest);
            case 'endfor':
                $entry = $this->close('for', 'endfor');

                return sprintf(
                    "}\nif (\$__l%1\$d === null) { unset(\$v['loop']); } else { \$v['loop'] = \$__l%1\$d; }\n",
                    $entry['id'],
                );

            case 'set':
                if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=(?!=)\s*(.+)$/s', $rest, $m) !== 1) {
                    $this->fail('Invalid {% set %} tag, expected {% set name = expression %}.');
                }

                return sprintf("\$v[%s] = %s;\n", var_export($m[1], true), $this->expression($m[2]));
            case 'include':
                return sprintf("echo \$t->include(%s, \$v);\n", $this->expression($rest));
            case 'extends':
                $this->fail('{% extends %} must be the first tag of the template and its argument must be a quoted string.');

                // no break: fail() always throws

            case 'block':
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $rest) !== 1) {
                    $this->fail('Invalid {% block %} tag, expected {% block name %}.');
                }
                $this->stack[] = ['type' => 'block', 'line' => $this->line, 'id' => 0, 'hasElse' => false];
                $call = $parent !== null && $blockDepth === 0 ? 'defineBlock' : 'block';
                $blockDepth++;

                return $call === 'block'
                    ? sprintf("\$t->block(%s, \$v, static function (array \$v) use (\$t): void {\n", var_export($rest, true))
                    : sprintf("\$t->defineBlock(%s, static function (array \$v) use (\$t): void {\n", var_export($rest, true));

            case 'endblock':
                $this->close('block', 'endblock');
                $blockDepth--;

                return "});\n";
            default:
                $this->fail(sprintf('Unknown tag "%s".', $word));
        }
    }

    private function forTag(string $rest): string
    {
        if (preg_match('/^(?:([A-Za-z_][A-Za-z0-9_]*)\s*,\s*)?([A-Za-z_][A-Za-z0-9_]*)\s+in\s+(.+)$/s', $rest, $m) !== 1) {
            $this->fail('Invalid {% for %} tag, expected {% for item in items %} or {% for key, item in items %}.');
        }

        $id = ++$this->counter;
        $this->stack[] = ['type' => 'for', 'line' => $this->line, 'id' => $id, 'hasElse' => false];

        $code = sprintf("\$__r%1\$d = \$t->each(%2\$s);\n\$__l%1\$d = \$v['loop'] ?? null;\nforeach (\$__r%1\$d as \$__e%1\$d) {\n", $id, $this->expression($m[3]));
        if ($m[1] !== '') {
            $code .= sprintf("\$v[%s] = \$__e%d[0];\n", var_export($m[1], true), $id);
        }

        return $code . sprintf("\$v[%s] = \$__e%d[1];\n\$v['loop'] = \$__e%d[2];\n", var_export($m[2], true), $id, $id);
    }

    private function elseTag(): string
    {
        $top = $this->top();
        if ($top === null || ($top['type'] !== 'if' && $top['type'] !== 'for')) {
            $this->fail('{% else %} without a matching {% if %} or {% for %}.');
        }
        if ($top['hasElse']) {
            $this->fail('A tag can only have one {% else %}.');
        }

        $this->stack[count($this->stack) - 1]['hasElse'] = true;

        return $top['type'] === 'if'
            ? "} else {\n"
            : sprintf("}\nif (\$__r%d === []) {\n", $top['id']);
    }

    /**
     * @return array{type: string, line: int, id: int, hasElse: bool}|null
     */
    private function top(): ?array
    {
        return $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
    }

    private function expect(string $type, string $tag): void
    {
        $top = $this->top();
        if ($top === null || $top['type'] !== $type || $top['hasElse']) {
            $this->fail(sprintf('{%% %s %%} without a matching {%% %s %%}.', $tag, $type));
        }
    }

    /**
     * @return array{type: string, line: int, id: int, hasElse: bool}
     */
    private function close(string $type, string $tag): array
    {
        $top = $this->top();
        if ($top === null || $top['type'] !== $type) {
            $this->fail(sprintf('Unexpected {%% %s %%}.', $tag));
        }

        array_pop($this->stack);

        return $top;
    }

    private function output(string $source): string
    {
        $segments = $this->splitFilters($source);
        $expression = $this->expression($segments[0]);
        $raw = false;
        $force = false;

        foreach (array_slice($segments, 1) as $segment) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*(?:\((.*)\))?$/s', trim($segment), $m) !== 1) {
                $this->fail(sprintf('Invalid filter "%s".', trim($segment)));
            }

            $name = $m[1];
            if ($name === 'raw') {
                $raw = true;

                continue;
            }
            if ($name === 'escape' || $name === 'e') {
                $force = true;

                continue;
            }

            $arguments = isset($m[2]) && trim($m[2]) !== '' ? ', ' . implode(', ', $this->expressionList($m[2])) : '';
            $expression = sprintf('$t->filter(%s, [%s%s])', var_export($name, true), $expression, $arguments);
        }

        if ($raw && $force) {
            $this->fail('A filter chain cannot use both "raw" and "escape".');
        }

        return $raw
            ? sprintf("echo \$t->out(%s);\n", $expression)
            : sprintf("echo \$t->escape(%s%s);\n", $expression, $force ? ', true' : '');
    }

    /**
     * Split on single "|" characters that are outside strings and parentheses.
     *
     * @return non-empty-list<string>
     */
    private function splitFilters(string $source): array
    {
        $segments = [];
        $current = '';
        $quote = null;
        $depth = 0;
        $length = mb_strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $source[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === '|' && $depth === 0) {
                if (($source[$i + 1] ?? '') === '|') {
                    $current .= '||';
                    $i++;

                    continue;
                }
                $segments[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $segments[] = $current;

        return $segments;
    }

    private function expression(string $source): string
    {
        $tokens = $this->tokenize($source);
        if ($tokens === []) {
            $this->fail('Empty expression.');
        }

        return $this->translate($tokens);
    }

    /**
     * @return list<string>
     */
    private function expressionList(string $source): array
    {
        $groups = [[]];
        $depth = 0;
        foreach ($this->tokenize($source) as $token) {
            if ($token[0] === 'punc') {
                if ($token[1] === '(' || $token[1] === '[') {
                    $depth++;
                } elseif ($token[1] === ')' || $token[1] === ']') {
                    $depth--;
                } elseif ($token[1] === ',' && $depth === 0) {
                    $groups[] = [];

                    continue;
                }
            }
            $groups[count($groups) - 1][] = $token;
        }

        $expressions = [];
        foreach ($groups as $group) {
            if ($group === []) {
                $this->fail('Empty argument in a filter call.');
            }
            $expressions[] = $this->translate($group);
        }

        return $expressions;
    }

    /**
     * @return list<array{0: string, 1: string}> type and text of every token
     */
    private function tokenize(string $source): array
    {
        $tokens = [];
        $offset = 0;
        $length = mb_strlen($source);

        while ($offset < $length) {
            if (preg_match(self::TOKEN, $source, $m, 0, $offset) !== 1) {
                if (trim(mb_substr($source, $offset)) === '') {
                    break;
                }
                $this->fail(sprintf('Unexpected character "%s" in expression "%s".', trim(mb_substr($source, $offset))[0], trim($source)));
            }

            foreach (['str', 'num', 'id', 'op', 'punc'] as $type) {
                if (isset($m[$type]) && $m[$type] !== '') {
                    $tokens[] = [$type, $m[$type]];

                    break;
                }
            }
            $offset += mb_strlen($m[0]);
        }

        return $tokens;
    }

    /**
     * @param list<array{0: string, 1: string}> $tokens
     */
    private function translate(array $tokens): string
    {
        $php = [];
        $endsOperand = false;

        foreach ($tokens as $i => [$type, $text]) {
            $isOperator = $type === 'id' && in_array(mb_strtolower($text), ['and', 'or', 'not'], true);
            $startsOperand = $type === 'str' || $type === 'num' || ($type === 'id' && !$isOperator) || ($type === 'punc' && ($text === '(' || $text === '['));
            if ($endsOperand && $startsOperand) {
                $this->fail('Missing operator between two values');
            }
            $endsOperand = $type === 'str' || $type === 'num' || ($type === 'id' && !$isOperator) || ($type === 'punc' && ($text === ')' || $text === ']'));

            switch ($type) {
                case 'str':
                    $quote = $text[0];
                    $php[] = var_export(str_replace(['\\' . $quote, '\\\\'], [$quote, '\\'], mb_substr($text, 1, -1)), true);

                    break;
                case 'id':
                    $php[] = $this->identifier($text, ($tokens[$i + 1][1] ?? '') === '(');

                    break;
                case 'op':
                    $php[] = $text === '~' ? '.' : $text;

                    break;
                default:
                    $php[] = $text;
            }
        }

        return implode(' ', $php);
    }

    private function identifier(string $text, bool $isCall): string
    {
        $lower = mb_strtolower($text);
        $keywords = ['and' => '&&', 'or' => '||', 'not' => '!', 'true' => 'true', 'false' => 'false', 'null' => 'null'];
        if (isset($keywords[$lower])) {
            return $keywords[$lower];
        }

        if ($isCall) {
            $this->fail(sprintf('Function calls are not allowed ("%s(...)"); use a filter instead.', $text));
        }

        $path = explode('.', $text);
        $base = sprintf('($v[%s] ?? null)', var_export(array_shift($path), true));
        if ($path === []) {
            return $base;
        }

        return sprintf('$t->attr(%s, %s)', $base, implode(', ', array_map(static fn (string $segment): string => var_export($segment, true), $path)));
    }

    /**
     * @return never
     */
    private function fail(string $message): void
    {
        throw new TemplateSyntaxException(sprintf('%s in "%s" at line %d.', rtrim($message, '.'), $this->name, $this->line));
    }
}
