# TemplateManager

[![CI](https://github.com/echo-fusion/templatemanager/actions/workflows/ci.yml/badge.svg)](https://github.com/echo-fusion/templatemanager/actions/workflows/ci.yml) [![Packagist](https://img.shields.io/packagist/v/echo-fusion/templatemanager.svg)](https://packagist.org/packages/echo-fusion/templatemanager) [![PHP](https://img.shields.io/badge/php-8.1%20%7C%208.2%20%7C%208.3-777bb4.svg)](https://www.php.net) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**TemplateManager** renders templates for the EchoFusion framework. It has **no dependency on Symfony or Laravel**: it ships its own small template language (in the spirit of Twig/Smarty), supports plain PHP templates, and lets you plug in other engines such as Twig through configuration.

It implements `EchoFusion\Contracts\TemplateManager\TemplateManagerInterface` from [echo-fusion/contracts](https://github.com/echo-fusion/contracts), so other packages can depend on the interface only.

> Part of [EchoFusion](https://github.com/echo-fusion), a modular PHP framework built from small packages.

## Installation

```bash
composer require echo-fusion/templatemanager
```

## Requirements

* PHP 8.1, 8.2 or 8.3
* ext-mbstring

## Quick start

```php
use EchoFusion\TemplateManager\TemplateManager;

$templates = TemplateManager::fromConfig([
    'paths' => [__DIR__ . '/templates'],
    'globals' => ['appName' => 'My app'],
]);

echo $templates->render('home', ['user' => ['name' => 'Ann']]);   // templates/home.tpl
```

With no `engines` setting the built-in `tpl` engine and plain `php` engine are enabled. `render()` returns a string; wrap it yourself in a response, e.g. `new HtmlResponse($templates->render('home'))` from [echo-fusion/responsemanager](https://github.com/echo-fusion/responsemanager).

## The built-in template language (`.tpl`)

```twig
{# a comment #}
<h1>{{ title|upper }}</h1>                   {# output is HTML-escaped #}
<p>{{ user.name }} ({{ user.email|default('no email') }})</p>
<div>{{ html|raw }}</div>                    {# raw = not escaped #}

{% set total = price * qty %}
{% if total > 100 %}big{% elseif total > 10 %}medium{% else %}small{% endif %}

<ul>
{% for post in posts %}
  <li class="{{ loop.first ? 'first' : '' }}">{{ loop.index }}. {{ post.title }}</li>
{% else %}
  <li>No posts</li>
{% endfor %}
</ul>

{% for key, value in settings %}{{ key }}={{ value }} {% endfor %}

{% include "partials/footer" %}
```

* **Variables:** `a.b.c` reads array keys, `ArrayAccess`, public properties, and the getters `getB()`, `isB()`, `hasB()`. Missing values print nothing.
* **Expressions:** strings, numbers, `true`/`false`/`null`, `[lists]`, `+ - * / %`, `~` (concatenate), `== != < > <= >= === !==`, `and`/`or`/`not`, `?:` and `??`. Function calls are not allowed; use filters.
* **Loops:** `loop.index`, `loop.index0`, `loop.first`, `loop.last`, `loop.length`. An `{% else %}` runs when there is nothing to loop over.
* **Filters:** `upper lower capitalize title trim length default join keys first last reverse date json number truncate replace url_encode raw escape`. Chain them: `{{ name|trim|upper }}`.
* **Auto-escaping** is on. Turn it off with the `autoescape` option, or force it with `|e`.

### Layouts: `extends` and `block`

```twig
{# templates/layouts/base.tpl #}
<html><head><title>{% block title %}My site{% endblock %}</title></head>
<body>{% block content %}{% endblock %}</body></html>
```

```twig
{# templates/blog/index.tpl #}
{% extends "layouts/base" %}
{% block title %}Blog{% endblock %}
{% block content %}<h1>{{ heading }}</h1>{% endblock %}
```

Inheritance can be nested. In a child template only `{% block %}` and `{% set %}` may appear outside blocks.

### Custom filters

```php
use EchoFusion\TemplateManager\Engines\SimpleEngine;

$engine = new SimpleEngine($templates->getFinder(), 'tpl');
$engine->addFilter('money', fn (float $v, string $cur = '$') => $cur . number_format($v, 2));
$templates->addEngine('tpl', $engine);
```

### Compilation and cache

Templates are compiled to PHP files. Options for the `tpl` engine:

| Option | Default | |
| --- | --- | --- |
| `cache` | `<system temp>/echofusion-templates` | cache directory |
| `auto_reload` | `true` | recompile when the template changes; set `false` in production for the fastest start |
| `autoescape` | `true` | HTML-escape `{{ }}` |

Expressions are translated token by token, so a template cannot inject PHP code.

## Plain PHP templates (`.php`)

```php
<?php $this->layout('layouts/main', ['title' => 'Home']) ?>
<?php $this->start('sidebar') ?><nav>...</nav><?php $this->stop() ?>

<h1><?= $this->e($title) ?></h1>
<?= $this->insert('partials/list', ['items' => $items]) ?>
```

In a layout, `<?= $this->section('content') ?>` is the child output and `$this->section('sidebar', 'default')` any named section. `$this->e()` escapes. Section output is already rendered, so it is not escaped again.

## Namespaces (modules)

Give a directory a namespace and address its templates as `@namespace/name`:

```php
$templates->addPath(__DIR__ . '/modules/blog/templates', 'blog');
$templates->render('@blog/posts/index');
```

This pairs with [echo-fusion/modulemanager](https://github.com/echo-fusion/modulemanager):

```php
foreach ($modules->getTemplatePaths() as $name => $path) {
    $templates->addPath($path, $name);
}
```

Directories are searched in the order they were added, so add an application directory first to override a module template.

## Configuration

```php
return [
    'paths' => [
        __DIR__ . '/templates',                      // no key: default namespace
        'blog' => __DIR__ . '/modules/blog/views',   // string key: namespace
    ],
    'globals' => ['appName' => 'My app'],
    'engines' => [                                   // order matters: first engine that has the template wins
        'tpl'  => ['options' => ['cache' => __DIR__ . '/storage/templates', 'auto_reload' => false]],
        'php'  => [],
        'twig' => ['options' => ['cache' => __DIR__ . '/storage/twig']],   // needs twig/twig
        'blade' => ['class' => MyBladeEngine::class, 'options' => []],       // any engine class
    ],
];
```

An engine entry is the file extension. `tpl`, `php` and `twig` are built in; for any other extension give a `class`. Names can carry the extension (`render('home.twig')`) to pick an engine explicitly. Pass the same array to `TemplateManager::fromConfig()`, or to `$manager->configure()` on an existing manager.

### Your own engine

Implement `EchoFusion\Contracts\TemplateManager\TemplateEngineInterface` (`render()`, `exists()`). The manager creates it as `new YourEngine(TemplateFinder $finder, string $extension, array $options)`; use `$finder->find($name, $extension)` to locate files and namespaces.

### Twig

`TwigEngine` hands the manager's directories and namespaces to Twig, so `@blog/post` works the same way. `options` go to `Twig\Environment`. Twig is optional: without `twig/twig` installed, only configuring a `twig` engine fails.

## Service provider

`EchoFusion\TemplateManager\Providers\TemplateManagerProvider` registers `TemplateManagerInterface` and `TemplateEngineInterface` in a container and, on `boot()`, configures the manager from the `templates` section of your config.

## Errors

All exceptions extend `EchoFusion\TemplateManager\Exceptions\TemplateException`: `TemplateNotFoundException`, `TemplateSyntaxException` (with template name and line), `InvalidConfigException`.

Template names are validated: `..`, absolute paths, backslashes and NUL bytes are never resolved, so a user-controlled name cannot read files outside the template directories.

## Testing

```bash
composer test
```

## Credits

- [Amir Shadanfar](https://github.com/amir-shadanfar)

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
