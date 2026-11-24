<?php

use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Response\ResponseBuilder;
use JsonRPC\Server;
use Kanboard\Plugin\Broadside\Api\BroadsideProcedure;
use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class CommentApiTest extends Base
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

    public function testPollerContractUsesExclusiveIdCursorAndProjectScope(): void
    {
        $artifact = $this->publish($this->project, 'Roadmap');
        $other = $this->publish($this->otherProject, 'Other');
        $removed = $this->publish($this->project, 'Removed');
        $this->seedComment($artifact, 30);
        $first = $this->seedComment($artifact, 20);
        $second = $this->seedComment($artifact, 10, $first);
        $this->seedComment($other, 25);
        $this->seedComment($removed, 25);
        $this->container['broadsideArtifactModel']->remove($removed);

        $this->loginAs('viewer');
        $rows = $this->container['api']->getProcedureHandler()->executeProcedure('artifactCommentList', [
            'project_id' => $this->project,
            'since_id' => $first - 1,
        ]);
        $this->assertSame([$first, $second], array_column($rows, 'id'));
        $this->assertSame([
            'id' => $second,
            'artifact_id' => $artifact,
            'artifact_title' => 'Roadmap',
            'url' => 'http://localhost/?controller=ArtifactController&action=show&plugin=Broadside&artifact_id='
                .$artifact.'&version=1#comment-'.$second,
            'user_id' => $this->users['viewer'],
            'username' => 'viewer',
            'body' => '**Looks good**',
            'quote' => 'Milestone',
            'parent_id' => $first,
            'version' => 1,
            'created_at' => 10,
            'publisher_username' => 'member',
        ], $rows[1]);
        $this->assertSame([$second], array_column($this->procedure->artifactCommentList($this->project, $first, 100, $artifact), 'id'));
        $this->assertSame([], $this->procedure->artifactCommentList($this->project, 0, 100, $other));
    }

    public function testPollerDefaultsToOneHundredAndCapsAtFiveHundred(): void
    {
        $artifact = $this->publish($this->project, 'Report');
        for ($i = 0; $i < 501; ++$i) {
            $this->seedComment($artifact, 10);
        }

        $this->loginAs('viewer');
        $this->assertCount(100, $this->procedure->artifactCommentList($this->project));
        $this->assertCount(500, $this->procedure->artifactCommentList($this->project, 0, 1000));
    }

    public function testPollerUsesTheDefaultForNonPositiveLimits(): void
    {
        $artifact = $this->publish($this->project, 'Report');
        for ($i = 0; $i < 101; ++$i) {
            $this->seedComment($artifact, 10);
        }

        $this->loginAs('viewer');
        $expected = $this->procedure->artifactCommentList($this->project);
        foreach ([0, -1, '-2'] as $limit) {
            $this->assertSame($expected, $this->procedure->artifactCommentList($this->project, 0, $limit));
        }
    }

    public function testUnauthorizedCallersGetForbidden(): void
    {
        $artifact = $this->publish($this->project, 'Report');
        foreach (['outsider', 'viewer'] as $user) {
            $this->loginAs($user);
            if ($user === 'viewer') {
                $this->container['api'] = new Server('', ['PHP_AUTH_USER' => 'jsonrpc']);
            }
            foreach ([
                fn () => $this->procedure->artifactCommentList($this->project),
                fn () => $this->procedure->artifactCommentCreate($artifact, 'Comment'),
            ] as $call) {
                try {
                    $call();
                    $this->fail('Expected JSON-RPC 403');
                } catch (AccessDeniedException $e) {
                    $response = json_decode(ResponseBuilder::create()->withException($e)->build(), true);
                    $this->assertSame(403, $response['error']['code']);
                }
            }
        }
    }

    public function testViewerCanCreateAndReplyOnTheCurrentVersion(): void
    {
        $artifact = $this->publish($this->project, 'Report');
        $parent = $this->seedComment($artifact, 10);
        $this->container['broadsideArtifactModel']->publish($this->project, $this->users['member'], [
            'artifact_id' => $artifact, 'title' => 'Report', 'type' => 'markdown', 'body' => 'Revised report',
        ]);

        $this->loginAs('viewer');
        $this->container['api']->getProcedureHandler()->executeProcedure('artifactCommentCreate', [
            'artifact_id' => $artifact, 'body' => 'Thanks!', 'parent_id' => $parent, 'quote' => 'Revised',
        ]);

        [$reply] = $this->procedure->artifactCommentList($this->project, $parent);
        $this->assertSame($parent, $reply['parent_id']);
        $this->assertSame(2, $reply['version']);
        $this->assertSame('Thanks!', $reply['body']);
        $this->assertSame('Revised', $reply['quote']);
        $this->assertSame($this->users['viewer'], $reply['user_id']);
    }

    public function testRejectsEmptyBody(): void
    {
        $artifact = $this->publish($this->project, 'Report');
        $this->loginAs('viewer');

        $this->expectException(InvalidArgumentException::class);
        $this->procedure->artifactCommentCreate($artifact, " \n ");
    }

    public function testRejectsParentFromAnotherArtifactEvenInTheSameProject(): void
    {
        $artifact = $this->publish($this->project, 'Report');
        $other = $this->publish($this->project, 'Other artifact');
        $parent = $this->seedComment($other, 10);
        $this->loginAs('viewer');

        $this->expectException(InvalidArgumentException::class);
        $this->procedure->artifactCommentCreate($artifact, 'Reply', $parent);
    }

    private function publish(int $project, string $title): int
    {
        return $this->container['broadsideArtifactModel']->publish($project, $this->users['member'], [
            'title' => $title, 'type' => 'markdown', 'body' => 'Report body',
        ])['artifact_id'];
    }

    private function seedComment(int $artifact, int $time, ?int $parent = null): int
    {
        $db = $this->container['db'];
        $db->table('broadside_comments')->insert([
            'artifact_id' => $artifact,
            'user_id' => $this->users['viewer'],
            'version' => 1,
            'parent_id' => $parent,
            'body' => '**Looks good**',
            'quote' => 'Milestone',
            'created_at' => $time,
        ]);
        return $db->getLastId();
    }

    private function loginAs(string $user): void
    {
        $_SESSION['user'] = $this->container['userModel']->getById($this->users[$user]);
    }
}
