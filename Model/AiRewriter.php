<?php

namespace Kanboard\Plugin\Broadside\Model;

interface AiRewriter
{
    public function isReady(int $projectId): bool;

    public function structured(array $messages, string $schema, int $projectId): array;
}
