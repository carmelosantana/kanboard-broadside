<?php

use Kanboard\Plugin\Broadside\Model\AiConnectorRewriter;
use Kanboard\Plugin\Broadside\Plugin;
use KanboardTests\units\Base;

class AiRoutingTest extends Base
{
    private function registry(string $profile): object
    {
        return new class ($profile) {
            public array $resolved = [];
            public array $structured = [];

            public function __construct(private string $profile)
            {
            }

            public function resolve(string $feature, ?int $projectId = null, ?string $pick = null): string
            {
                $this->resolved[] = [$feature, $projectId, $pick];

                return $this->profile;
            }

            public function structured(array $messages, string $schema, ?string $profileId = null): array
            {
                $this->structured[] = $profileId;

                return ['html' => '<p>ok</p>'];
            }
        };
    }

    public function testRewriteFeatureIsRegistered(): void
    {
        (new Plugin($this->container))->initialize();
        $features = [];
        foreach ($this->container['hook']->getListeners('aiconnector:features') as $listener) {
            $features = array_merge($features, $listener());
        }
        $this->assertSame([['id' => 'broadside.rewrite', 'label' => 'Rewrite imports', 'plugin' => 'Broadside']], $features);
    }

    public function testStructuredPassesTheResolvedProfileForTheProject(): void
    {
        $registry = $this->registry('profile-a');
        $result = (new AiConnectorRewriter($registry))->structured([], '{}', 7);

        $this->assertSame(['html' => '<p>ok</p>'], $result);
        $this->assertSame([['broadside.rewrite', 7, null]], $registry->resolved);
        $this->assertSame(['profile-a'], $registry->structured);
    }

    public function testEmptyResolutionIsUnavailableAndMakesNoCall(): void
    {
        $registry = $this->registry('');
        $rewriter = new AiConnectorRewriter($registry);

        $this->assertFalse($rewriter->isReady(7));
        $this->assertTrue((new AiConnectorRewriter($this->registry('p')))->isReady(7));
        try {
            $rewriter->structured([], '{}', 7);
            $this->fail('expected an exception');
        } catch (RuntimeException) {
        }
        $this->assertSame([], $registry->structured);
    }
}
