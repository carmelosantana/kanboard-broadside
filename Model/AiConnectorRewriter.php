<?php

namespace Kanboard\Plugin\Broadside\Model;

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Pimple\Container;

final class AiConnectorRewriter implements AiRewriter
{
    private function __construct(private readonly ProviderRegistry $registry)
    {
    }

    public static function fromInstalledPlugin(Container $container): ?self
    {
        if (PHP_VERSION_ID < 80400 || ! class_exists(ProviderRegistry::class)) {
            return null;
        }

        return new self(new ProviderRegistry($container));
    }

    public function isReady(): bool
    {
        return $this->registry->isReady();
    }

    public function structured(array $messages, string $schema): array
    {
        return $this->registry->structured($messages, $schema);
    }
}
