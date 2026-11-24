<?php

use Kanboard\Plugin\Broadside\Plugin;
use KanboardTests\units\Base;

class PluginVersionTest extends Base
{
    public function testManifestVersionAndRecommendations(): void
    {
        $plugin = new Plugin($this->container);
        $manifest = json_decode(file_get_contents(__DIR__.'/../plugin.json'), false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1.0.0', $plugin->getPluginVersion());
        $this->assertSame($plugin->getPluginVersion(), $manifest->version);

        $this->assertIsArray($manifest->recommends);
        $versions = [];
        foreach ($manifest->recommends as $recommendation) {
            $this->assertIsObject($recommendation);
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/D', $recommendation->min_version);
            $this->assertIsString($recommendation->reason);
            $this->assertNotSame('', trim($recommendation->reason));
            $this->assertArrayNotHasKey($recommendation->plugin, $versions);
            $versions[$recommendation->plugin] = $recommendation->min_version;
        }
        $this->assertEquals(['Harbor' => '2.0.0', 'AiConnector' => '1.2.0'], $versions);
    }
}
