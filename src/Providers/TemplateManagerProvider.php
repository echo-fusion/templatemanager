<?php

declare(strict_types=1);

namespace EchoFusion\TemplateManager\Providers;

use EchoFusion\Contracts\ServiceManager\SettableContainerInterface;
use EchoFusion\Contracts\ServiceProvider\ServiceProviderInterface;
use EchoFusion\Contracts\TemplateManager\TemplateEngineInterface;
use EchoFusion\Contracts\TemplateManager\TemplateManagerInterface;
use EchoFusion\TemplateManager\TemplateManager;
use LogicException;
use function sprintf;

class TemplateManagerProvider implements ServiceProviderInterface
{
    public function register(SettableContainerInterface $container): void
    {
        $container->set(TemplateManagerInterface::class, static fn (): TemplateManager => new TemplateManager());
        $container->set(TemplateEngineInterface::class, static fn (): TemplateManagerInterface => $container->get(TemplateManagerInterface::class));
    }

    /**
     * @param array<string, mixed> $config the "templates" section of the application configuration
     */
    public function boot(SettableContainerInterface $container, array $config = []): void
    {
        $manager = $container->get(TemplateManagerInterface::class);
        if (!$manager instanceof TemplateManager) {
            throw new LogicException(sprintf('%s must be a %s to be configured.', TemplateManagerInterface::class, TemplateManager::class));
        }

        $manager->configure($config);

        // persist the configured instance on the container
        $container->set(TemplateManagerInterface::class, static fn (): TemplateManager => $manager);
    }
}
