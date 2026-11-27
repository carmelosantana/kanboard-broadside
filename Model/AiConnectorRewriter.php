<?php

namespace Kanboard\Plugin\Broadside\Model;

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Pimple\Container;

final class AiConnectorRewriter implements AiRewriter
{
    public const FEATURE = 'broadside.rewrite';

    /** @param object $registry An AiConnector ProviderRegistry (resolve() and structured()). */
    public function __construct(private readonly object $registry)
    {
    }

    public static function fromInstalledPlugin(Container $container): ?self
    {
        if (
            PHP_VERSION_ID < 80400
            || ! class_exists(ProviderRegistry::class)
            || ! method_exists(ProviderRegistry::class, 'resolve')
        ) {
            return null;
        }

        return new self(new ProviderRegistry($container));
    }

    public function isReady(int $projectId): bool
    {
        return $this->profileFor($projectId) !== '';
    }

    public function structured(array $messages, string $schema, int $projectId): array
    {
        $profile = $this->profileFor($projectId);
        if ($profile === '') {
            throw new \RuntimeException('AI rewriting is not available.');
        }

        return $this->registry->structured($messages, $schema, $profile);
    }

    private function profileFor(int $projectId): string
    {
        return $this->registry->resolve(self::FEATURE, $projectId);
    }
}
