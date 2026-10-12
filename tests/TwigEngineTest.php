<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Tests;

use EchoFusion\TemplateManager\Engines\TwigEngine;
use EchoFusion\TemplateManager\Exceptions\TemplateException;
use EchoFusion\TemplateManager\Exceptions\TemplateNotFoundException;
use EchoFusion\TemplateManager\TemplateFinder;
use EchoFusion\TemplateManager\TemplateManager;
use PHPUnit\Framework\TestCase;

final class TwigEngineTest extends TestCase
{
    public function testRendersWithNamespacesAndRebuildsWhenPathsChange(): void
    {
        $finder = new TemplateFinder();
        $finder->addPath(__DIR__ . '/fixtures/twig');
        $engine = new TwigEngine($finder, 'twig', ['autoescape' => 'html']);

        $this->assertTrue($engine->exists('page'));
        $first = $engine->twig();
        $this->assertSame($first, $engine->twig());

        $finder->addPath(__DIR__ . '/fixtures/blog', 'blog');
        $this->assertNotSame($first, $engine->twig());
        $this->assertSame("Twig &lt;A&gt; [T]\n", $engine->render('page', ['name' => '<A>', 'title' => 'T']));
    }

    public function testThroughTheManagerFromConfig(): void
    {
        $manager = TemplateManager::fromConfig([
            'paths' => [__DIR__ . '/fixtures/twig', 'blog' => __DIR__ . '/fixtures/blog'],
            'engines' => ['twig' => []],
        ]);

        $this->assertSame("Twig A [none]\n", $manager->render('page', ['name' => 'A']));
        $this->assertSame("Twig A [none]\n", $manager->render('page.twig', ['name' => 'A']));
    }

    public function testMissingTemplate(): void
    {
        $engine = new TwigEngine(new TemplateFinder());

        $this->expectException(TemplateNotFoundException::class);
        $engine->render('nope');
    }

    public function testTwigErrorsAreWrapped(): void
    {
        $finder = new TemplateFinder();
        $finder->addPath(__DIR__ . '/fixtures/twig');
        $engine = new TwigEngine($finder, 'twig', ['strict_variables' => true]);

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('name');
        $engine->render('page');
    }
}
