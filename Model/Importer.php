<?php

namespace Kanboard\Plugin\Broadside\Model;

use InvalidArgumentException;
use Kanboard\Core\Base;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Pimple\Container;
use Throwable;

final class Importer extends Base
{
    public const MAX_CONTENT_BYTES = 5 * 1024 * 1024;

    public function __construct(Container $container, private readonly ?ProviderRegistry $registry = null)
    {
        parent::__construct($container);
    }

    public function import(
        int $projectId,
        int $userId,
        mixed $title,
        mixed $content,
        mixed $format = 'html',
        string $sourceUrl = '',
        int $artifactId = 0
    ): array {
        if (! in_array($format, ['html', 'markdown'], true)) {
            throw new InvalidArgumentException('Artifact format must be html or markdown.');
        }
        if (! is_string($title) || ! is_string($content) || strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new InvalidArgumentException('Import requires a string title and string content of at most 5 MiB.');
        }

        $warnings = $format === 'html' ? Lint::check($content) : [];
        $rewritten = false;
        $aiError = [];
        if ($warnings !== [] && ($registry = $this->readyRegistry()) !== null) {
            try {
                $result = $registry->structured([
                    [
                        'role' => 'system',
                        'content' => "Rewrite the supplied HTML to follow this contract. Preserve the page's content and purpose.\n\n"
                            .file_get_contents(__DIR__.'/../CONTRACT.md'),
                    ],
                    ['role' => 'user', 'content' => $content],
                ], '{"type":"object","properties":{"html":{"type":"string"}},"required":["html"],"additionalProperties":false}');
                $html = $result['html'] ?? null;
                if (! is_string($html) || strlen($html) > self::MAX_CONTENT_BYTES) {
                    throw new InvalidArgumentException('AI rewrite must return HTML of at most 5 MiB.');
                }
                $warnings = Lint::check($html);
                $content = $html;
                $rewritten = true;
            } catch (Throwable $e) {
                $aiError = ['ai_error' => $e->getMessage()];
            }
        }

        return $this->container['broadsideArtifactModel']->publish($projectId, $userId, [
            'title' => $title,
            'body' => $content,
            'type' => $format,
            'source_url' => $sourceUrl,
            'artifact_id' => $artifactId,
        ]) + ['warnings' => $warnings, 'rewritten' => $rewritten] + $aiError;
    }

    private function readyRegistry(): ?ProviderRegistry
    {
        if (PHP_VERSION_ID < 80400 || ! class_exists(ProviderRegistry::class)) {
            return null;
        }

        $registry = $this->registry ?? new ProviderRegistry($this->container);

        return $registry->isReady() ? $registry : null;
    }
}
