<?php

namespace Kanboard\Plugin\Broadside;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\Broadside\Model\ArtifactModel;

class Plugin extends Base
{
    public function initialize(): void
    {
        $this->container['broadsideArtifactModel'] = static fn ($container) => new ArtifactModel($container);
    }

    public function getPluginName(): string
    {
        return 'Broadside';
    }

    public function getPluginDescription(): string
    {
        return 'Publish project artifacts as sandboxed HTML or Markdown pages.';
    }

    public function getPluginVersion(): string
    {
        return '1.0.0';
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/kanboard-broadside';
    }

    public function getPluginLicense(): string
    {
        return 'MIT';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }
}
