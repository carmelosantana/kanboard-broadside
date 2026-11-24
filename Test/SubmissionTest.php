<?php

use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Response\ResponseBuilder;
use JsonRPC\Server;
use Kanboard\Plugin\Broadside\Api\BroadsideProcedure;
use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class SubmissionTest extends Base
{
    use BroadsideHarness;

    private int $artifactId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['api'] = new Server();
        $this->setUpBroadside();
        $this->artifactId = $this->container['broadsideArtifactModel']->publish($this->project, $this->users['member'], [
            'title' => 'Poll', 'type' => 'html', 'body' => '<h1>Vote</h1>',
        ])['artifact_id'];
    }

    public function testViewerCanListSubmissionsWithAnExclusiveCursorAndOptionalForm(): void
    {
        $first = $this->seed('poll', '{"choice":"yes"}');
        $other = $this->seed('feedback', '{}');
        $last = $this->seed('poll', '{"choice":"no"}');
        $this->seed('poll', '{"choice":"elsewhere"}', $this->container['broadsideArtifactModel']->publish(
            $this->project, $this->users['member'], ['title' => 'Other poll', 'type' => 'html', 'body' => '<h1>Other poll</h1>']
        )['artifact_id']);
        $_SESSION['user'] = $this->container['userModel']->getById($this->users['viewer']);

        $handler = $this->container['api']->getProcedureHandler();
        $all = $handler->executeProcedure('artifactSubmissionList', ['artifact_id' => $this->artifactId]);
        $this->assertSame([$first, $other, $last], array_column($all, 'id'));
        $rows = $handler->executeProcedure('artifactSubmissionList', [
            'artifact_id' => $this->artifactId, 'form_key' => 'poll', 'since_id' => $first,
        ]);
        $this->assertSame([$last], array_column($rows, 'id'));
        $this->assertSame($this->artifactId, $rows[0]['artifact_id']);
        $this->assertSame(1, $rows[0]['version']);
        $this->assertSame('poll', $rows[0]['form_key']);
        $this->assertSame($this->users['viewer'], $rows[0]['user_id']);
        $this->assertSame('viewer', $rows[0]['username']);
        $this->assertSame('{"choice":"no"}', json_encode($rows[0]['data']));
        $this->assertSame(123, $rows[0]['created_at']);
    }

    public function testOutsiderAndApplicationCredentialGetForbidden(): void
    {
        $procedure = new BroadsideProcedure($this->container);
        foreach (['outsider', 'viewer'] as $user) {
            $_SESSION['user'] = $this->container['userModel']->getById($this->users[$user]);
            if ($user === 'viewer') {
                $this->container['api'] = new Server('', ['PHP_AUTH_USER' => 'jsonrpc']);
            }

            try {
                $procedure->artifactSubmissionList($this->artifactId);
                $this->fail('Expected JSON-RPC 403');
            } catch (AccessDeniedException $e) {
                $response = json_decode(ResponseBuilder::create()->withException($e)->build(), true);
                $this->assertSame(403, $response['error']['code']);
            }
        }
    }

    public function testSubmissionListLimitsPagesInAscendingIdOrder(): void
    {
        $ids = [];
        for ($i = 0; $i < 505; $i++) {
            $ids[] = $this->seed('poll', '{}');
        }
        $_SESSION['user'] = $this->container['userModel']->getById($this->users['viewer']);
        $handler = $this->container['api']->getProcedureHandler();
        $params = ['artifact_id' => $this->artifactId, 'form_key' => 'poll'];

        $first = $handler->executeProcedure('artifactSubmissionList', $params);
        $this->assertCount(100, $first);
        $this->assertSame(array_slice($ids, 0, 100), array_column($first, 'id'));
        foreach ([0, -1] as $limit) {
            $page = $handler->executeProcedure('artifactSubmissionList', $params + ['limit' => $limit]);
            $this->assertSame(array_slice($ids, 0, 100), array_column($page, 'id'));
        }

        $page = $handler->executeProcedure('artifactSubmissionList', $params + ['limit' => 600]);
        $this->assertSame(array_slice($ids, 0, 500), array_column($page, 'id'));
        $cursor = end($page)['id'];
        $next = $handler->executeProcedure('artifactSubmissionList', $params + ['since_id' => $cursor, 'limit' => 3]);
        $this->assertSame(array_slice($ids, 500, 3), array_column($next, 'id'));
        $last = $handler->executeProcedure('artifactSubmissionList', $params + ['since_id' => end($next)['id'], 'limit' => 3]);
        $this->assertSame(array_slice($ids, 503), array_column($last, 'id'));
    }

    private function seed(string $form, string $payload, ?int $artifactId = null): int
    {
        $db = $this->container['db'];
        $db->table('broadside_submissions')->insert([
            'artifact_id' => $artifactId ?? $this->artifactId, 'version' => 1, 'form_key' => $form,
            'user_id' => $this->users['viewer'], 'payload' => $payload, 'created_at' => 123,
        ]);

        return (int) $db->getLastId();
    }
}
