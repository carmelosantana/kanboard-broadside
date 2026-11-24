<?php

use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Request\RequestParser;
use JsonRPC\Response\ResponseBuilder;
use JsonRPC\Server;
use Kanboard\Plugin\Broadside\Api\BroadsideProcedure;
use Kanboard\Plugin\Broadside\Model\BroadsideProcedureModel;
use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class ProcedureTest extends Base
{
    use BroadsideHarness;

    private BroadsideProcedure $procedure;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['api'] = new Server();
        $this->setUpBroadside();
        $this->procedure = new BroadsideProcedure($this->container);
    }

    public function testMemberPublishesThroughRegisteredProcedure(): void
    {
        $this->loginAs('member');
        $this->container['configModel']->save(['application_url' => 'https://board.example.test/kanboard/']);
        $this->container['memoryCache']->flush();
        $result = $this->container['api']->getProcedureHandler()->executeProcedure('artifactPublish', [
            'project_id' => $this->project,
            'title' => 'Report',
            'body' => '<h1>Report</h1>',
        ]);

        $id = $result['artifact_id'];
        $this->assertGreaterThan(0, $id);
        $this->assertSame([
            'artifact_id' => $id,
            'version' => 1,
            'url' => 'https://board.example.test/kanboard/?controller=ArtifactController&action=show&plugin=Broadside&artifact_id='.$id,
            'warnings' => [],
        ], $result);
    }

    public function testViewerCannotPublish(): void
    {
        $this->loginAs('viewer');
        $this->assertForbidden(fn () => $this->procedure->artifactPublish($this->project, 'Report', '<h1>Report</h1>'));
        $this->assertSame([], $this->container['broadsideArtifactModel']->listByProject($this->project));
    }

    public function testAllProceduresRequireAPersonalSession(): void
    {
        foreach ($this->calls() as $call) {
            $this->assertForbidden($call);
        }
    }

    public function testApplicationCredentialCannotReuseALoggedInSession(): void
    {
        $this->loginAs('manager');
        $this->container['api'] = new Server('', ['PHP_AUTH_USER' => 'jsonrpc']);
        foreach ($this->calls() as $call) {
            $this->assertForbidden($call);
        }
    }

    public function testViewerGetsCurrentAndHistoricalVersionsAndOutsiderGetsNull(): void
    {
        $published = $this->publishReport();
        $id = $published['artifact_id'];
        $updated = $this->procedure->artifactPublish(
            $this->project, 'Updated report', '<h2>Second</h2>', 'html', $id, 0, 'https://source.example.test/'
        );
        $this->assertSame($id, $updated['artifact_id']);
        $this->assertSame(2, $updated['version']);

        $this->loginAs('viewer');
        $current = $this->procedure->artifactGet($id);
        $this->assertSame($id, $current['artifact_id']);
        $this->assertSame($this->project, $current['project_id']);
        $this->assertSame('Updated report', $current['title']);
        $this->assertSame('html', $current['type']);
        $this->assertSame('https://source.example.test/', $current['source_url']);
        $this->assertSame($this->users['member'], $current['creator_id']);
        $this->assertSame(2, $current['current_version']);
        $this->assertSame(2, $current['version']);
        $this->assertSame('<h2>Second</h2>', $current['body']);
        $this->assertSame($updated['url'], $current['url']);
        $this->assertArrayNotHasKey('share_token', $current);

        $old = $this->procedure->artifactGet($id, 1);
        $this->assertSame(1, $old['version']);
        $this->assertSame('<h1>First</h1>', $old['body']);
        $this->assertNull($this->procedure->artifactGet($id, 99));

        $this->loginAs('outsider');
        $this->assertNull($this->procedure->artifactGet($id));
        $this->assertForbidden(fn () => $this->procedure->artifactList($this->project));
    }

    public function testPublishRejectsTypeChangesWithAJsonRpcError(): void
    {
        $id = $this->publishReport()['artifact_id'];
        $server = $this->container['api'];
        $response = RequestParser::create()
            ->withProcedureHandler($server->getProcedureHandler())
            ->withMiddlewareHandler($server->getMiddlewareHandler())
            ->withPayload([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'artifactPublish',
                'params' => [
                    'project_id' => $this->project,
                    'artifact_id' => $id,
                    'title' => 'Updated report',
                    'body' => '## Second',
                    'type' => 'markdown',
                ],
            ])
            ->parse();

        $this->assertSame([
            'code' => -32602,
            'message' => 'Invalid params',
            'data' => 'type cannot change; publish a new artifact',
        ], json_decode($response, true)['error']);
    }

    public function testViewerListsArtifactSummaries(): void
    {
        $published = $this->publishReport();
        $this->loginAs('viewer');
        $rows = $this->procedure->artifactList($this->project);
        $this->assertCount(1, $rows);
        $this->assertGreaterThan(0, $rows[0]['updated_at']);
        $this->assertSame([
            'artifact_id' => $published['artifact_id'],
            'title' => 'Report',
            'type' => 'html',
            'current_version' => 1,
            'updated_at' => $rows[0]['updated_at'],
            'url' => $published['url'],
            'publisher_username' => 'member',
        ], $rows[0]);
    }

    public function testContractIsServedVerbatim(): void
    {
        $this->loginAs('viewer');
        $contract = $this->procedure->artifactGetContract();
        $this->assertSame(file_get_contents(__DIR__.'/../CONTRACT.md'), $contract);
        $this->assertStringContainsString('var(--primary)', $contract);
        $this->assertStringContainsString('broadside.submit', $contract);
    }

    public function testProcedureModelHasItsOwnAutoloadableClassFile(): void
    {
        $class = new ReflectionClass(BroadsideProcedureModel::class);
        $this->assertSame(realpath(__DIR__.'/../Model/BroadsideProcedureModel.php'), $class->getFileName());
    }

    public function testArtifactLookupReturnsOnlyActiveArtifacts(): void
    {
        $id = $this->publishReport()['artifact_id'];
        $artifacts = $this->container['broadsideArtifactModel'];
        $this->assertSame($id, (int) $artifacts->find($id)['id']);
        $this->assertNull($artifacts->find($id + 1));
        $this->assertTrue($artifacts->remove($id));
        $this->assertNull($artifacts->find($id));
    }

    public function testOutsiderReceivesSameRefusalForExistingAndMissingArtifact(): void
    {
        $id = $this->publishReport()['artifact_id'];
        $this->loginAs('outsider');

        $this->assertForbidden(fn () => $this->procedure->artifactRemove($id));
        $this->assertForbidden(fn () => $this->procedure->artifactRemove($id + 1));
    }

    public function testOnlyManagerCanRemoveAndRemovedArtifactIsHidden(): void
    {
        $id = $this->publishReport()['artifact_id'];
        $this->assertForbidden(fn () => $this->procedure->artifactRemove($id));
        $this->assertNotNull($this->procedure->artifactGet($id));

        $this->loginAs('manager');
        $this->assertTrue($this->procedure->artifactRemove($id));
        $this->assertForbidden(fn () => $this->procedure->artifactRemove($id));

        $this->loginAs('viewer');
        $this->assertNull($this->procedure->artifactGet($id));
        $this->assertNull($this->procedure->artifactGet($id, 1));
        $this->assertSame([], $this->procedure->artifactList($this->project));
    }

    public function testMalformedArtifactIdCannotRemoveAnArtifact(): void
    {
        $id = $this->publishReport()['artifact_id'];
        $this->loginAs('manager');

        $this->expectException(InvalidArgumentException::class);
        $this->procedure->artifactRemove([$id]);
    }

    private function calls(): array
    {
        return [
            fn () => $this->procedure->artifactPublish($this->project, 'Report', '<h1>Report</h1>'),
            fn () => $this->procedure->artifactGet(1),
            fn () => $this->procedure->artifactList($this->project),
            fn () => $this->procedure->artifactGetContract(),
            fn () => $this->procedure->artifactRemove(1),
        ];
    }

    private function publishReport(): array
    {
        $this->loginAs('member');
        return $this->procedure->artifactPublish($this->project, 'Report', '<h1>First</h1>');
    }

    private function loginAs(string $user): void
    {
        $_SESSION['user'] = $this->container['userModel']->getById($this->users[$user]);
    }

    private function assertForbidden(Closure $call): void
    {
        try {
            $call();
            $this->fail('Expected JSON-RPC 403 Forbidden');
        } catch (AccessDeniedException $e) {
            $response = json_decode(ResponseBuilder::create()->withException($e)->build(), true);
            $this->assertSame(['code' => 403, 'message' => 'Forbidden'], $response['error']);
        }
    }
}
