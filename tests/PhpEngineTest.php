<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Tests;

use EchoFusion\TemplateManager\Engines\PhpEngine;
use EchoFusion\TemplateManager\Exceptions\TemplateException;
use EchoFusion\TemplateManager\Exceptions\TemplateNotFoundException;
use EchoFusion\TemplateManager\TemplateFinder;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function ob_get_level;

final class PhpEngineTest extends TestCase
{
    private PhpEngine $engine;

    protected function setUp(): void
    {
        $finder = new TemplateFinder();
        $finder->addPath(__DIR__ . '/fixtures/php');
        $this->engine = new PhpEngine($finder);
    }

    public function testRendersWithEscaping(): void
    {
        $this->assertSame("<p>&lt;b&gt;&amp;&quot;</p>\n", $this->engine->render('plain', ['name' => '<b>&"']));
        $this->assertTrue($this->engine->exists('plain'));
        $this->assertFalse($this->engine->exists('missing'));
    }

    public function testLayoutSectionsAndPartials(): void
    {
        $output = $this->engine->render('page', ['name' => 'Echo']);

        $this->assertStringContainsString('[Home|side|', $output);
        $this->assertStringContainsString('body:Echo', $output);
        $this->assertStringContainsString('partial:&lt;1&gt;', $output);
        $this->assertStringContainsString('|default-footer]', $output);
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(TemplateNotFoundException::class);

        $this->engine->render('missing');
    }

    public function testExceptionsCleanUpOutputBuffers(): void
    {
        $level = ob_get_level();

        try {
            $this->engine->render('boom');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($level, ob_get_level());
    }

    public function testStopWithoutStartThrows(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('without a matching start()');

        $this->engine->render('bad_stop');
    }

    public function testEscapingRejectsArrays(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('Cannot output a value of type "array"');

        $this->engine->render('plain', ['name' => ['x']]);
    }
}
