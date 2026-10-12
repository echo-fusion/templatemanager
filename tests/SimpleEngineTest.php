<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Tests;

use ArrayObject;
use DateTimeImmutable;
use EchoFusion\TemplateManager\Engines\SimpleEngine;
use EchoFusion\TemplateManager\Exceptions\TemplateException;
use EchoFusion\TemplateManager\Exceptions\TemplateNotFoundException;
use EchoFusion\TemplateManager\Exceptions\TemplateSyntaxException;
use EchoFusion\TemplateManager\TemplateFinder;
use PHPUnit\Framework\TestCase;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class SimpleEngineTest extends TestCase
{
    private string $dir;

    private string $cache;

    private int $templates = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ef-tpl-' . uniqid('', true);
        $this->cache = $this->dir . '/cache';
        mkdir($this->dir . '/views', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ([...(glob($this->cache . '/*') ?: []), ...(glob($this->dir . '/views/*') ?: [])] as $file) {
            @unlink($file);
        }
        @rmdir($this->cache);
        @rmdir($this->dir . '/views');
        @rmdir($this->dir);
    }

    public function testOutputIsEscapedByDefault(): void
    {
        $this->assertSame('&lt;b&gt; &quot;q&quot; &#039;s&#039; &amp;', $this->render('{{ v }}', ['v' => '<b> "q" \'s\' &']));
    }

    public function testRawFilterAndExplicitEscape(): void
    {
        $this->assertSame('<b>', $this->render('{{ v|raw }}', ['v' => '<b>']));
        $this->assertSame('&lt;b&gt;', $this->render('{{ v|e }}', ['v' => '<b>']));
        $this->assertSame('&lt;b&gt;', $this->render('{{ v|escape }}', ['v' => '<b>'], ['autoescape' => false]));
        $this->assertSame('<b>', $this->render('{{ v }}', ['v' => '<b>'], ['autoescape' => false]));
    }

    public function testRawAndEscapeTogetherIsASyntaxError(): void
    {
        $this->expectException(TemplateSyntaxException::class);

        $this->render('{{ v|raw|e }}');
    }

    public function testNullAndMissingVariablesPrintNothing(): void
    {
        $this->assertSame('[][]', $this->render('[{{ missing }}][{{ a.b.c }}]', ['a' => null]));
    }

    public function testScalarsAndStringables(): void
    {
        $this->assertSame('1|2.5||x', $this->render('{{ a }}|{{ b }}|{{ c }}|{{ d }}', [
            'a' => 1,
            'b' => 2.5,
            'c' => false,
            'd' => new class() {
                public function __toString(): string
                {
                    return 'x';
                }
            },
        ]));
    }

    public function testOutputtingAnArrayThrows(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('Cannot output a value of type "array"');

        $this->render('{{ v }}', ['v' => [1]]);
    }

    public function testDottedAccess(): void
    {
        $user = new class() {
            public string $name = 'Ann';

            private bool $admin = true;

            /**
             * @var array<string, string>
             */
            public array $tags = ['php' => 'P'];

            public function getEmail(): string
            {
                return 'a@b.c';
            }

            public function isAdmin(): bool
            {
                return $this->admin;
            }

            public function hasPhone(): bool
            {
                return false;
            }

            public function getFirst(string $required): string
            {
                return $required;
            }

            public function delete(): string
            {
                return 'deleted';
            }
        };

        $output = $this->render(
            '{{ user.name }}|{{ user.email }}|{{ user.admin }}|{{ user.phone }}|{{ user.first }}|{{ user.delete }}|{{ user.tags.php }}|{{ data.k }}|{{ data.x }}|{{ arr.list.0 }}|{{ s.x }}',
            ['user' => $user, 'data' => new ArrayObject(['k' => 'K']), 'arr' => ['list' => ['zero']], 's' => 'str'],
        );

        $this->assertSame('Ann|a@b.c|1||||P|K||zero|', $output);
    }

    public function testExpressions(): void
    {
        $vars = ['a' => 3, 'b' => 4, 'name' => 'x', 'list' => [1, 2]];

        $this->assertSame('7|12|1|-1', $this->render('{{ a + b }}|{{ a * b }}|{{ b % a }}|{{ a - b }}', $vars));
        $this->assertSame('yes|no', $this->render("{{ a < b ? 'yes' : 'no' }}|{{ a > b ? 'yes' : 'no' }}", $vars));
        $this->assertSame('ok', $this->render('{% if a == 3 and not (b != 4 or false) %}ok{% endif %}', $vars));
        $this->assertSame('fallback', $this->render("{{ missing ?? 'fallback' }}", $vars));
        $this->assertSame('x-y', $this->render("{{ name ~ '-' ~ 'y' }}", $vars));
        $this->assertSame("it's", $this->render("{{ 'it\\'s'|raw }}"));
        $this->assertSame('say "hi"', $this->render('{{ "say \"hi\""|raw }}'));
        $this->assertSame('2', $this->render('{{ list|length }}', $vars));
        $this->assertSame('1.5', $this->render('{{ 1.5 }}'));
        $this->assertSame('T|F|', $this->render('{% if true %}T{% endif %}|{% if false %}T{% else %}F{% endif %}|{% if null %}n{% endif %}'));
    }

    public function testFilters(): void
    {
        $this->assertSame('ABC', $this->render('{{ v|upper }}', ['v' => 'abc']));
        $this->assertSame('abc', $this->render('{{ v|lower }}', ['v' => 'ABC']));
        $this->assertSame('Hello world', $this->render('{{ v|capitalize }}', ['v' => 'hELLO WORLD']));
        $this->assertSame('Hello World', $this->render('{{ v|title }}', ['v' => 'hello world']));
        $this->assertSame('x', $this->render('{{ v|trim }}', ['v' => '  x ']));
        $this->assertSame('5|3', $this->render('{{ s|length }}|{{ l|length }}', ['s' => 'héllo', 'l' => [1, 2, 3]]));
        $this->assertSame('d|v', $this->render("{{ a|default('d') }}|{{ b|default('d') }}", ['a' => '', 'b' => 'v']));
        $this->assertSame('1, 2', $this->render("{{ l|join(', ') }}", ['l' => [1, 2]]));
        $this->assertSame('a,b', $this->render("{{ l|keys|join(',') }}", ['l' => ['a' => 1, 'b' => 2]]));
        $this->assertSame('1|3|a|c||321', $this->render('{{ l|first }}|{{ l|last }}|{{ s|first }}|{{ s|last }}|{{ e|first }}|{{ l|reverse|join }}', ['l' => [1, 2, 3], 's' => 'abc', 'e' => []]));
        $this->assertSame('2024-05-06|2024-05-06|01/02', $this->render("{{ d|date('Y-m-d') }}|{{ t|date('Y-m-d') }}|{{ s|date('d/m') }}", ['d' => new DateTimeImmutable('2024-05-06'), 't' => 1714953600, 's' => '2024-02-01']));
        $this->assertSame('{"a":1}', $this->render('{{ v|json|raw }}', ['v' => ['a' => 1]]));
        $this->assertSame('1,234.50|1,235', $this->render('{{ v|number(2) }}|{{ v|number }}', ['v' => 1234.5]));
        $this->assertSame('abc…|abc', $this->render('{{ v|truncate(3) }}|{{ w|truncate(3) }}', ['v' => 'abcdef', 'w' => 'abc']));
        $this->assertSame('a-c', $this->render("{{ v|replace('b', '-') }}", ['v' => 'abc']));
        $this->assertSame('a%20b', $this->render('{{ v|url_encode }}', ['v' => 'a b']));
    }

    public function testCustomFilterAndUnknownFilter(): void
    {
        $engine = $this->engine();
        $engine->addFilter('shout', static fn (string $v, string $end = '!'): string => $v . $end);
        $this->write('f', "{{ 'hey'|shout }}{{ 'hey'|shout('?') }}");

        $this->assertSame('hey!hey?', $engine->render('f'));

        $this->write('g', '{{ 1|nope }}');
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('Unknown filter "nope"');
        $engine->render('g');
    }

    public function testIfElseifElse(): void
    {
        $template = '{% if n == 1 %}one{% elseif n == 2 %}two{% else %}many{% endif %}';

        $this->assertSame('one', $this->render($template, ['n' => 1]));
        $this->assertSame('two', $this->render($template, ['n' => 2]));
        $this->assertSame('many', $this->render($template, ['n' => 9]));
    }

    public function testForLoops(): void
    {
        $this->assertSame('1:a,2:b,', $this->render('{% for x in items %}{{ loop.index }}:{{ x }},{% endfor %}', ['items' => ['a', 'b']]));
        $this->assertSame('k=a;l=b;', $this->render('{% for key, x in items %}{{ key }}={{ x }};{% endfor %}', ['items' => ['k' => 'a', 'l' => 'b']]));
        $this->assertSame('F.L|0/1/3', $this->render('{% for x in items %}{% if loop.first %}F{% endif %}{% if loop.last %}L{% endif %}{% if not loop.first and not loop.last %}.{% endif %}{% endfor %}|{{ 0 }}/1/3', ['items' => [1, 2, 3]]));
        $this->assertSame('empty', $this->render('{% for x in items %}{{ x }}{% else %}empty{% endfor %}', ['items' => []]));
        $this->assertSame('empty', $this->render('{% for x in missing %}{{ x }}{% else %}empty{% endfor %}'));
        $this->assertSame('ab', $this->render('{% for x in items %}{{ x }}{% else %}empty{% endfor %}', ['items' => new ArrayObject(['a', 'b'])]));
    }

    public function testNestedLoopsRestoreOuterLoopVariable(): void
    {
        $output = $this->render(
            '{% for a in outer %}{{ loop.index }}[{% for b in inner %}{{ loop.index }}{% endfor %}]{{ loop.index }};{% endfor %}|{{ loop.index }}',
            ['outer' => [1, 2], 'inner' => ['x', 'y']],
        );

        $this->assertSame('1[12]1;2[12]2;|', $output);
    }

    public function testLoopingOverANonIterableThrows(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('Cannot loop over a value of type "string"');

        $this->render('{% for x in v %}{% endfor %}', ['v' => 'text']);
    }

    public function testSet(): void
    {
        $this->assertSame('5|x', $this->render("{% set a = 2 + 3 %}{{ a }}|{% set b = 'x' %}{{ b }}"));
    }

    public function testCommentsAndStaticText(): void
    {
        $this->assertSame("a\nb", $this->render("a{# hidden\n comment #}\nb"));
        $this->assertSame('{ not a tag } {x}', $this->render('{ not a tag } {x}'));
    }

    public function testNonAsciiTemplates(): void
    {
        $this->assertSame(
            'سلام علی، خانه-من | بله',
            $this->render("{# نظر فارسی #}سلام {{ name }}، {{ 'خانه' ~ '-' ~ 'من' }} | {% if ok == 'بله' %}بله{% endif %}", ['name' => 'علی', 'ok' => 'بله']),
        );
        $this->assertSame('«ü» é', $this->render("{% set a = 'ü' %}«{{ a }}» é"));
        $this->assertSame('ب', $this->render("{% set v = 'ب' %}{% if v == 'ب' %}{{ v }}{% endif %}"));
    }

    public function testNonAsciiTextAroundBlocksAndExtends(): void
    {
        $this->write('base', 'ع{% block a %}پیش‌فرض{% endblock %}غ');
        $this->write('child', "{% extends \"base\" %}\n{# توضیح #}\n{% block a %}فرزند {{ x }}{% endblock %}");

        $this->assertSame('عفرزند ۱غ', $this->engine()->render('child', ['x' => '۱']));
    }

    public function testNonAsciiSyntaxErrorsReportTheRightCharacter(): void
    {
        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessage('Unexpected character "$"');

        $this->render("{{ 'سلام' ~ \$x }}");
    }

    public function testIncludeSharesVariables(): void
    {
        $this->write('partial', '[partial {{ who }}]');

        $this->assertSame('Hi [partial Ann]', $this->render('Hi {% include "partial" %}', ['who' => 'Ann']));
    }

    public function testIncludeLoopIsStopped(): void
    {
        $this->write('loop', '{% include "loop" %}');

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('included too deeply');
        $this->engine()->render('loop');
    }

    public function testInheritanceAcrossThreeLevels(): void
    {
        $this->write('base', '<t>{% block title %}Default{% endblock %}</t><b>{% block content %}empty{% endblock %}</b><f>{% block footer %}foot{% endblock %}</f>');
        $this->write('child', '{% extends "base" %}{% set h = "H" %}{% block title %}Child {{ h }}{% endblock %}{% block content %}<main>{{ body }}</main>{% endblock %}');
        $this->write('grandchild', '{% extends "child" %}{% block content %}GC:{{ body }}{% block inner %}[in]{% endblock %}{% endblock %}');
        $engine = $this->engine();

        $this->assertSame('<t>Default</t><b>empty</b><f>foot</f>', $engine->render('base'));
        $this->assertSame('<t>Child H</t><b><main>x</main></b><f>foot</f>', $engine->render('child', ['body' => 'x']));
        $this->assertSame('<t>Child H</t><b>GC:x[in]</b><f>foot</f>', $engine->render('grandchild', ['body' => 'x']));
    }

    public function testInheritanceLoopIsStopped(): void
    {
        $this->write('a', '{% extends "b" %}{% block x %}{% endblock %}');
        $this->write('b', '{% extends "a" %}{% block x %}{% endblock %}');

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('nested too deeply');
        $this->engine()->render('a');
    }

    public function testMissingTemplate(): void
    {
        $this->expectException(TemplateNotFoundException::class);

        $this->engine()->render('missing');
    }

    public function testMissingParentTemplate(): void
    {
        $this->write('orphan', '{% extends "ghost" %}{% block x %}{% endblock %}');

        $this->expectException(TemplateNotFoundException::class);
        $this->engine()->render('orphan');
    }

    public function testOutputBuffersAreCleanedOnError(): void
    {
        $this->write('bad', 'before{{ v|nope }}');
        $level = ob_get_level();

        try {
            $this->engine()->render('bad', ['v' => 1]);
            $this->fail('Expected exception');
        } catch (TemplateException) {
            $this->assertSame($level, ob_get_level());
        }
    }

    /**
     * @dataProvider syntaxErrors
     */
    public function testSyntaxErrors(string $template, string $message): void
    {
        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessage($message);

        $this->render($template);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function syntaxErrors(): array
    {
        return [
            'unclosed if' => ["a\n{% if x %}b", 'Unclosed {% if %} tag in "t1" at line 2'],
            'unclosed for' => ['{% for x in y %}', 'Unclosed {% for %}'],
            'unclosed block' => ['{% block a %}', 'Unclosed {% block %}'],
            'stray endif' => ['{% endif %}', 'Unexpected {% endif %}'],
            'stray endfor' => ['{% endfor %}', 'Unexpected {% endfor %}'],
            'stray endblock' => ['{% endblock %}', 'Unexpected {% endblock %}'],
            'wrong end' => ['{% if x %}{% endfor %}', 'Unexpected {% endfor %}'],
            'stray else' => ['{% else %}', '{% else %} without a matching'],
            'else in block' => ['{% block a %}{% else %}{% endblock %}', '{% else %} without a matching'],
            'double else' => ['{% if x %}{% else %}{% else %}{% endif %}', 'only have one {% else %}'],
            'elseif without if' => ['{% for x in y %}{% elseif z %}{% endfor %}', '{% elseif %} without a matching {% if %}'],
            'elseif after else' => ['{% if x %}{% else %}{% elseif z %}{% endif %}', '{% elseif %} without a matching'],
            'unknown tag' => ['{% dance %}', 'Unknown tag "dance"'],
            'bad for' => ['{% for x %}{% endfor %}', 'Invalid {% for %} tag'],
            'bad set' => ['{% set x %}', 'Invalid {% set %} tag'],
            'set with comparison' => ['{% set x == 1 %}', 'Invalid {% set %} tag'],
            'bad block name' => ['{% block a-b %}{% endblock %}', 'Invalid {% block %} tag'],
            'late extends' => ['x{% extends "a" %}', 'must be the first tag'],
            'empty if' => ['{% if %}{% endif %}', 'Empty expression'],
            'php injection' => ['{{ $x }}', 'Unexpected character "$"'],
            'semicolon' => ['{{ 1; phpinfo() }}', 'Unexpected character ";"'],
            'backtick' => ['{{ `ls` }}', 'Unexpected character'],
            'arrow' => ['{{ a->b }}', 'Invalid expression'],
            'dangling operator' => ['{{ 1 + }}', 'Invalid expression'],
            'two values' => ['{{ a b }}', 'Missing operator'],
            'call a variable' => ['{{ a (b) }}', 'Function calls are not allowed'],
            'list after value' => ['{{ 1 [2] }}', 'Missing operator'],
            'index a value' => ['{{ a [0] }}', 'Missing operator'],
            'assignment' => ['{% if a = 1 %}{% endif %}', 'Unexpected character "="'],
            'function call' => ['{{ phpinfo() }}', 'Function calls are not allowed'],
            'method call' => ['{{ a.b() }}', 'Function calls are not allowed'],
            'bad filter' => ['{{ a|1x }}', 'Invalid filter'],
            'empty filter argument' => ['{{ a|join(,) }}', 'Empty argument'],
            'text in child' => ['{% extends "p" %}stray{% block a %}{% endblock %}', 'Only {% block %} and {% set %} are allowed'],
            'output in child' => ['{% extends "p" %}{{ x }}', 'Only {% block %} and {% set %} are allowed'],
            'if in child' => ['{% extends "p" %}{% if x %}{% endif %}', 'Only {% block %} and {% set %} are allowed'],
        ];
    }

    public function testFilterArgumentsWithNestedListsAndOrOperatorInOutput(): void
    {
        $this->assertSame('1|2', $this->render("{{ [1, 2]|join('|') }}"));
        $this->assertSame('a', $this->render('{{ x || y ? "a" : "b" }}', ['x' => true]));
    }

    public function testAutoReloadRecompilesChangedTemplates(): void
    {
        $this->write('page', 'v1');
        $first = $this->engine();
        $this->assertSame('v1', $first->render('page'));

        $this->write('page', 'v2-changed');
        $this->assertSame('v2-changed', $this->engine()->render('page'));

        $this->assertCount(1, glob($this->cache . '/*.php') ?: []);
    }

    public function testWithoutAutoReloadTheCacheIsKept(): void
    {
        $this->write('page', 'v1');
        $this->assertSame('v1', $this->engine(['auto_reload' => false])->render('page'));

        $this->write('page', 'v2');
        $this->assertSame('v1', $this->engine(['auto_reload' => false])->render('page'));
    }

    public function testCompiledTemplatesAreReusedWithinAnEngine(): void
    {
        $this->write('page', '{{ n }}');
        $engine = $this->engine();

        $this->assertSame('1', $engine->render('page', ['n' => 1]));
        $this->assertSame('2', $engine->render('page', ['n' => 2]));
    }

    public function testCorruptCacheFileIsReported(): void
    {
        $this->write('page', 'x');
        $engine = $this->engine(['auto_reload' => false]);
        $engine->render('page');
        foreach (glob($this->cache . '/*.php') ?: [] as $file) {
            file_put_contents($file, '<?php return 5;');
        }

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('is corrupt');
        $this->engine(['auto_reload' => false])->render('page');
    }

    public function testUnwritableCacheDirectoryIsReported(): void
    {
        file_put_contents($this->dir . '/blocker', 'file');
        $this->write('page', 'x');

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('could not be created');
        $this->engine(['cache' => $this->dir . '/blocker/sub'])->render('page');
    }

    public function testDefaultCacheDirectoryIsInTheSystemTempDir(): void
    {
        $this->write('page', 'default-cache');
        $finder = new TemplateFinder();
        $finder->addPath($this->dir . '/views');

        $this->assertSame('default-cache', (new SimpleEngine($finder))->render('page'));
        $this->assertTrue(is_dir(sys_get_temp_dir() . '/echofusion-templates'));
    }

    public function testExists(): void
    {
        $this->write('page', 'x');

        $this->assertTrue($this->engine()->exists('page'));
        $this->assertFalse($this->engine()->exists('nope'));
    }

    /**
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $options
     */
    private function render(string $template, array $vars = [], array $options = []): string
    {
        $name = 't' . ++$this->templates;
        $this->write($name, $template);

        return $this->engine($options)->render($name, $vars);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function engine(array $options = []): SimpleEngine
    {
        $finder = new TemplateFinder();
        $finder->addPath($this->dir . '/views');

        return new SimpleEngine($finder, 'tpl', $options + ['cache' => $this->cache]);
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->dir . '/views/' . $name . '.tpl', $contents);
    }
}
