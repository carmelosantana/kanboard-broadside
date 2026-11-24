<?php

use Kanboard\Plugin\Broadside\Model\Renderer;
use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class ArtifactModelTest extends Base
{
    use BroadsideHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBroadside();
    }

    public function testPublishCreatesArtifactAndVersion(): void
    {
        $taskId = (int) $this->container['taskCreationModel']->create(['project_id' => $this->project, 'title' => 'Local task']);
        $model = $this->container['broadsideArtifactModel'];
        $result = $model->publish($this->project, $this->users['member'], [
            'title' => 'Report',
            'type' => 'markdown',
            'body' => '# First',
            'task_id' => $taskId,
            'source_url' => 'https://example.com/report',
        ]);

        $id = $result['artifact_id'];
        $this->assertGreaterThan(0, $id);
        $this->assertSame(['artifact_id' => $id, 'version' => 1], $result);
        $artifact = $this->artifactRow($id);
        $this->assertSame($this->project, (int) $artifact['project_id']);
        $this->assertSame($taskId, (int) $artifact['task_id']);
        $this->assertSame('Report', $artifact['title']);
        $this->assertSame('markdown', $artifact['type']);
        $this->assertSame('https://example.com/report', $artifact['source_url']);
        $this->assertSame($this->users['member'], (int) $artifact['creator_id']);
        $this->assertSame(1, (int) $artifact['current_version']);
        $this->assertGreaterThan(0, (int) $artifact['created_at']);
        $this->assertSame($artifact['created_at'], $artifact['updated_at']);
        $this->assertSame('# First', $model->getVersion($id, 1));
        $this->assertNull($model->getVersion($id, 2));
    }

    public function testPublishAppendsAnImmutableVersion(): void
    {
        $model = $this->container['broadsideArtifactModel'];
        $id = $this->publishArtifact();
        $this->container['db']->table('broadside_artifacts')->eq('id', $id)->update(['updated_at' => 1]);

        $result = $model->publish($this->project, $this->users['manager'], [
            'artifact_id' => $id,
            'title' => 'Revised report',
            'type' => 'html',
            'body' => '<p>Second</p>',
        ]);

        $this->assertSame(['artifact_id' => $id, 'version' => 2], $result);
        $artifact = $this->artifactRow($id);
        $this->assertSame(2, (int) $artifact['current_version']);
        $this->assertGreaterThan(1, (int) $artifact['updated_at']);
        $this->assertSame('<p>First</p>', $model->getVersion($id, 1));
        $this->assertSame('<p>Second</p>', $model->getVersion($id, 2));
        $this->assertSame($this->users['member'], (int) $artifact['creator_id']);
        $version = $this->container['db']->table('broadside_versions')->eq('artifact_id', $id)->eq('number', 2)->findOne();
        $this->assertSame($this->users['manager'], (int) $version['creator_id']);
    }

    public function testRejectsUnsupportedType(): void
    {
        $model = $this->container['broadsideArtifactModel'];
        $this->expectException(InvalidArgumentException::class);
        $model->publish($this->project, $this->users['member'], [
            'title' => 'Report',
            'type' => 'pdf',
            'body' => 'Report',
        ]);
    }

    /** @dataProvider typeChanges */
    public function testRejectsTypeChangesAndPreservesOriginalRendering(
        string $type,
        string $newType,
        string $expectedMarkup,
    ): void {
        $model = $this->container['broadsideArtifactModel'];
        $body = '<p id="original">First</p>';
        $result = $model->publish($this->project, $this->users['member'], [
            'title' => 'Report',
            'type' => $type,
            'body' => $body,
        ]);
        $id = $result['artifact_id'];

        try {
            $model->publish($this->project, $this->users['member'], [
                'artifact_id' => $id,
                'title' => 'Revised report',
                'type' => $newType,
                'body' => 'Second',
            ]);
            $this->fail('Republish accepted a type change.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('type cannot change; publish a new artifact', $e->getMessage());
        }

        $artifact = $this->artifactRow($id);
        $this->assertSame($type, $artifact['type']);
        $this->assertSame(1, (int) $artifact['current_version']);
        $this->assertNull($model->getVersion($id, 2));
        $originalBody = $model->getVersion($id, 1);
        $this->assertSame($body, $originalBody);
        $html = (new Renderer($this->container))->render($originalBody, $artifact['type']);
        $this->assertStringContainsString($expectedMarkup, $html);
    }

    public static function typeChanges(): array
    {
        return [
            'HTML to Markdown' => ['html', 'markdown', '<p id="original">First</p>'],
            'Markdown to HTML' => ['markdown', 'html', '&lt;p id="original"&gt;First&lt;/p&gt;'],
        ];
    }

    /** @dataProvider invalidTitles */
    public function testRejectsTitlesOutsideCharacterBounds(string $title): void
    {
        $model = $this->container['broadsideArtifactModel'];
        $this->expectException(InvalidArgumentException::class);
        $model->publish($this->project, $this->users['member'], [
            'title' => $title,
            'type' => 'html',
            'body' => '<p>Report</p>',
        ]);
    }

    public static function invalidTitles(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => [" \t\n "],
            '256 characters' => [str_repeat('é', 256)],
        ];
    }

    public function testStoresTrimmedTitlesAtCharacterBounds(): void
    {
        $model = $this->container['broadsideArtifactModel'];
        foreach (['é', str_repeat('é', 255)] as $title) {
            $result = $model->publish($this->project, $this->users['member'], [
                'title' => " \t".$title."\n ",
                'type' => 'html',
                'body' => '<p>Report</p>',
            ]);
            $this->assertSame($title, $this->artifactRow($result['artifact_id'])['title']);
        }
    }

    public function testRejectsTaskFromAnotherProject(): void
    {
        $taskId = $this->container['taskCreationModel']->create(['project_id' => $this->otherProject, 'title' => 'Foreign task']);
        $model = $this->container['broadsideArtifactModel'];
        $this->expectException(InvalidArgumentException::class);
        $model->publish($this->project, $this->users['member'], [
            'title' => 'Report',
            'type' => 'html',
            'body' => '<p>Report</p>',
            'task_id' => $taskId,
        ]);
    }

    public function testRejectsVersionForArtifactInAnotherProject(): void
    {
        $id = $this->publishArtifact();
        $model = $this->container['broadsideArtifactModel'];
        $this->expectException(InvalidArgumentException::class);
        $model->publish($this->otherProject, $this->users['outsider'], [
            'artifact_id' => $id,
            'title' => 'Report',
            'type' => 'html',
            'body' => '<p>Second</p>',
        ]);
    }

    public function testBodyLimitIsMeasuredInBytes(): void
    {
        $model = $this->container['broadsideArtifactModel'];
        $body = str_repeat('é', 5 * 512 * 1024);
        $data = ['title' => 'Report', 'type' => 'html', 'body' => $body];
        $result = $model->publish($this->project, $this->users['member'], $data);
        $this->assertSame($body, $model->getVersion($result['artifact_id'], 1));

        $this->expectException(InvalidArgumentException::class);
        $model->publish($this->project, $this->users['member'], array_replace($data, ['body' => $body.'x']));
    }

    public function testFailedVersionInsertRollsBackArtifactUpdate(): void
    {
        $id = $this->publishArtifact();
        $before = $this->artifactRow($id);
        $this->assertTrue($this->container['db']->table('broadside_versions')->insert([
            'artifact_id' => $id,
            'number' => 2,
            'body' => '<p>Conflicting version</p>',
            'creator_id' => $this->users['member'],
            'created_at' => time(),
        ]));

        $model = $this->container['broadsideArtifactModel'];
        try {
            $model->publish($this->project, $this->users['member'], [
                'artifact_id' => $id,
                'title' => 'Conflicting update',
                'type' => 'html',
                'body' => '<p>Second</p>',
            ]);
            $this->fail('Publish accepted a duplicate version number');
        } catch (RuntimeException $e) {
            $this->assertSame($before, $this->artifactRow($id));
            $this->assertFalse($this->container['db']->getConnection()->inTransaction());
        }
    }

    public function testListOrdersByUpdatedAtAndExcludesRemovedAndOtherProjects(): void
    {
        $model = $this->container['broadsideArtifactModel'];
        $newestUpdated = $this->publishArtifact();
        $olderUpdated = $this->publishArtifact();
        $removed = $this->publishArtifact();
        $model->publish($this->otherProject, $this->users['outsider'], [
            'title' => 'Other report',
            'type' => 'html',
            'body' => '<p>Other</p>',
        ]);
        $this->container['db']->table('broadside_artifacts')->eq('id', $olderUpdated)->update(['updated_at' => 1]);

        $this->assertTrue($model->remove($removed));
        $this->assertSame(1, (int) $this->artifactRow($removed)['is_removed']);
        $this->assertSame(
            [$newestUpdated, $olderUpdated],
            array_map('intval', array_column($model->listByProject($this->project), 'id')),
        );
    }

    private function publishArtifact(): int
    {
        $result = $this->container['broadsideArtifactModel']->publish($this->project, $this->users['member'], [
            'title' => 'Report',
            'type' => 'html',
            'body' => '<p>First</p>',
        ]);

        return $result['artifact_id'];
    }

    private function artifactRow(int $id): array
    {
        return $this->container['db']->table('broadside_artifacts')->eq('id', $id)->findOne();
    }
}
