<?php

namespace Kanboard\Plugin\Broadside\Model;

interface AiRewriter
{
    public function isReady(): bool;

    public function structured(array $messages, string $schema): array;
}
