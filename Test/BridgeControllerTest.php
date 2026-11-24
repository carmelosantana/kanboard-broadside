<?php

use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Plugin\Broadside\Controller\BridgeController;
use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class BridgeControllerTest extends Base
{
    use BroadsideHarness;

    private int $artifactId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['route']->enable();
        $this->setUpBroadside();
        $this->artifactId = $this->container['broadsideArtifactModel']->publish($this->project, $this->users['member'], [
            'title' => 'Poll', 'type' => 'html', 'body' => '<h1>Vote</h1>',
        ])['artifact_id'];
    }

    public function testViewerCanSubmitForTheDisplayedVersionAndReadResults(): void
    {
        $this->container['broadsideArtifactModel']->publish($this->project, $this->users['member'], [
            'artifact_id' => $this->artifactId, 'title' => 'Poll', 'type' => 'html', 'body' => '<h1>Vote again</h1>',
        ]);
        [$status, $body] = $this->submit('viewer');
        $this->assertSame(201, $status);
        $this->assertSame(['id'], array_keys($body));

        $row = $this->container['db']->table('broadside_submissions')->eq('id', $body['id'])->findOne();
        $this->assertSame($this->artifactId, (int) $row['artifact_id']);
        $this->assertSame(1, (int) $row['version']);
        $this->assertSame('poll', $row['form_key']);
        $this->assertSame($this->users['viewer'], (int) $row['user_id']);
        $this->assertSame(['choice' => 'yes'], json_decode($row['payload'], true));
        $this->assertGreaterThan(0, (int) $row['created_at']);

        [$status, $results] = $this->callBridge('results', 'viewer', 'GET', ['form_key' => 'poll']);
        $this->assertSame(200, $status);
        $this->assertSame(['form_key', 'count', 'rows', 'mine'], array_keys($results));
        $this->assertSame('poll', $results['form_key']);
        $this->assertSame(1, $results['count']);
        $this->assertSame([[
            'username' => 'viewer', 'data' => ['choice' => 'yes'], 'created_at' => (int) $row['created_at'],
        ]], json_decode(json_encode($results['rows']), true));
        $this->assertTrue($results['mine']);
        [, $results] = $this->callBridge('results', 'member', 'GET', ['form_key' => 'poll']);
        $this->assertFalse($results['mine']);
        [$status] = $this->callBridge('results', 'outsider', 'GET', ['form_key' => 'poll']);
        $this->assertSame(404, $status);
    }

    public function testOnceRejectsAnExistingSubmissionFromTheSameUserAcrossVersions(): void
    {
        $this->assertSame(201, $this->submit('viewer')[0]);
        $this->container['broadsideArtifactModel']->publish($this->project, $this->users['member'], [
            'artifact_id' => $this->artifactId, 'title' => 'Poll', 'type' => 'html', 'body' => '<h1>New poll copy</h1>',
        ]);
        [$status, $body] = $this->submit('viewer', ['once' => '1', 'version' => '2']);
        $this->assertSame(409, $status);
        $this->assertSame('conflict', $body['error']);
        $this->assertSame(1, $this->container['db']->table('broadside_submissions')->count());
        $this->assertSame(201, $this->submit('member', ['once' => '1'])[0]);
        $this->assertSame(201, $this->submit('viewer', ['once' => '1', 'form_key' => 'other-poll'])[0]);
        $this->assertSame(201, $this->submit('viewer')[0]);
    }

    public function testResultsKeepTheNewestFiveHundredRowsAndReportTheFullCountAndMine(): void
    {
        $table = $this->container['db']->table('broadside_submissions');
        $submission = ['artifact_id' => $this->artifactId, 'version' => 1, 'form_key' => 'poll', 'created_at' => 123];
        for ($i = 0; $i <= 501; $i++) {
            $table->insert($submission + [
                'user_id' => $this->users[$i === 0 ? 'viewer' : 'member'],
                'payload' => json_encode(['vote' => $i]),
            ]);
        }
        $table->insert(['form_key' => 'feedback'] + $submission + ['user_id' => $this->users['viewer'], 'payload' => '{}']);

        [$status, $results] = $this->callBridge('results', 'viewer', 'GET', ['form_key' => 'poll']);
        $this->assertSame(200, $status);
        $this->assertSame(502, $results['count']);
        $this->assertCount(500, $results['rows']);
        $this->assertSame(range(501, 2), array_map(static fn ($row) => $row->data->vote, $results['rows']));
        $this->assertTrue($results['mine']);
    }

    public function testOutsiderAndWrongMethodsCannotSubmit(): void
    {
        $this->assertSame(404, $this->submit('outsider')[0]);
        $this->assertSame(405, $this->callBridge('submit', 'viewer', 'GET')[0]);
        $this->assertSame(405, $this->callBridge('results', 'viewer', 'POST')[0]);
        $this->assertSame(0, $this->container['db']->table('broadside_submissions')->count());
    }

    public static function invalidSubmissions(): iterable
    {
        yield 'bad token' => [['csrf_token' => 'bad'], 403, 'forbidden'];
        yield 'bad form key' => [['form_key' => "poll\n"], 422, 'invalid'];
        yield 'overlong form key' => [['form_key' => str_repeat('a', 65)], 422, 'invalid'];
        yield 'array payload' => [['payload' => '[]'], 422, 'invalid'];
        yield 'malformed JSON' => [['payload' => '{'], 422, 'invalid'];
        yield 'over size limit' => [['payload' => '{"x":"'.str_repeat('a', 65529).'"}'], 413, 'too_large'];
        yield 'unknown version' => [['version' => '9'], 404, 'not_found'];
    }

    /** @dataProvider invalidSubmissions */
    public function testRejectsInvalidSubmissions(array $post, int $expectedStatus, string $expectedError): void
    {
        [$status, $body] = $this->submit('viewer', $post);
        $this->assertSame($expectedStatus, $status);
        $this->assertSame($expectedError, $body['error']);
        $this->assertNotEmpty($body['message']);
        $this->assertSame(0, $this->container['db']->table('broadside_submissions')->count());
    }

    public function testAcceptsAnEmptyObjectAndExactlySixtyFourKibibytes(): void
    {
        $this->assertSame(201, $this->submit('viewer', ['payload' => '{}'])[0]);
        $this->assertSame(201, $this->submit('viewer', ['form_key' => 'large', 'payload' => '{"x":"'.str_repeat('a', 65528).'"}'])[0]);
        [, $results] = $this->callBridge('results', 'viewer', 'GET', ['form_key' => 'poll']);
        $this->assertSame('{}', json_encode($results['rows'][0]->data));
    }

    private function submit(string $user, array $post = []): array
    {
        return $this->callBridge('submit', $user, 'POST', [], $post + [
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'form_key' => 'poll', 'payload' => '{"choice":"yes"}', 'version' => '1',
        ]);
    }

    private function callBridge(string $action, string $user, string $method, array $query = [], array $post = []): array
    {
        $_SESSION['user'] = $this->container['userModel']->getById($this->users[$user]);
        unset($this->container['request'], $this->container['response']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => $method], $query, $post);
        $response = $this->getMockBuilder(Response::class)->setConstructorArgs([$this->container])
            ->onlyMethods(['send'])->getMock();
        $response->expects($this->once())->method('send');
        $this->container['response'] = $response;

        $route = $this->container['route']->findRoute("broadside/artifact/{$this->artifactId}/{$action}");
        $this->assertSame(['controller' => 'BridgeController', 'action' => $action, 'plugin' => 'Broadside'], $route);
        (new BridgeController($this->container))->$action();

        $status = (new ReflectionProperty(Response::class, 'httpStatusCode'))->getValue($response);
        $body = (array) json_decode((new ReflectionProperty(Response::class, 'httpBody'))->getValue($response));

        return [$status, $body];
    }
}
