<?php

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Plugin\Broadside\Controller\ArtifactController;
use Kanboard\Plugin\Broadside\Model\CommentModel;
use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class CommentModelTest extends Base
{
    use BroadsideHarness;

    private int $artifact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBroadside();
        $model = $this->container['broadsideArtifactModel'];
        $data = ['title' => 'Report', 'type' => 'markdown', 'body' => 'Report body'];
        $this->artifact = $model->publish($this->project, $this->users['member'], $data)['artifact_id'];
        $model->publish($this->project, $this->users['member'], $data + ['artifact_id' => $this->artifact]);
    }

    public function testBadCsrfTokenIsRejected(): void
    {
        $this->assertPostForbidden('viewer', 'invalid');
    }

    public function testOutsiderWithValidCsrfTokenIsRejected(): void
    {
        $this->assertPostForbidden('outsider', $this->container['token']->getCSRFToken());
    }

    public function testRejectsBodiesOverTwentyThousandCharacters(): void
    {
        $model = new CommentModel($this->container);
        $this->expectException(InvalidArgumentException::class);
        $model->create($this->artifact, $this->users['viewer'], str_repeat('é', 20001));
    }

    public function testStoresTheMaximumLengthBodyAndTruncatesTheQuoteByCharacter(): void
    {
        $model = new CommentModel($this->container);
        $body = str_repeat('é', 20000);
        $quote = str_repeat('船', 1000);
        $model->create($this->artifact, $this->users['viewer'], $body, quote: $quote.'extra');

        [$comment] = $model->listByArtifact($this->project, $this->artifact);
        $this->assertSame($body, $comment['body']);
        $this->assertSame($quote, $comment['quote']);
    }

    public function testViewerCanCommentOnTheDisplayedVersionAndReadRenderedMarkdown(): void
    {
        $this->request('viewer', 'POST', [
            'csrf_token' => $this->container['token']->getCSRFToken(),
            'body' => '**Looks good**',
            'quote' => '<b>Literal selection</b>',
            'version' => '1',
        ]);
        $response = $this->getMockBuilder(Response::class)->setConstructorArgs([$this->container])
            ->onlyMethods(['redirect'])->getMock();
        $response->expects($this->once())->method('redirect')
            ->with($this->matchesRegularExpression('/[?&]version=1#comment-\d+$/'));
        $this->container['response'] = $response;

        (new ArtifactController($this->container))->comment();

        $comment = $this->container['db']->table('broadside_comments')->eq('artifact_id', $this->artifact)->findOne();
        $this->assertSame(1, (int) $comment['version']);
        $this->assertSame($this->users['viewer'], (int) $comment['user_id']);

        $this->request('viewer', 'GET');
        $response = $this->getMockBuilder(Response::class)->setConstructorArgs([$this->container])
            ->onlyMethods(['send'])->getMock();
        $response->expects($this->once())->method('send');
        $this->container['response'] = $response;
        (new ArtifactController($this->container))->show();

        $html = (new ReflectionProperty(Response::class, 'httpBody'))->getValue($response);
        $this->assertStringContainsString('<strong>Looks good</strong>', $html);
        $this->assertStringContainsString('&lt;b&gt;Literal selection&lt;/b&gt;', $html);
        $this->assertStringContainsString('id="comment-'.$comment['id'].'"', $html);
    }

    private function assertPostForbidden(string $user, string $token): void
    {
        $this->request($user, 'POST', ['csrf_token' => $token, 'body' => 'Comment', 'version' => '1']);
        try {
            (new ArtifactController($this->container))->comment();
            $this->fail('Expected forbidden comment POST');
        } catch (AccessForbiddenException) {
            $this->assertSame([], $this->container['db']->table('broadside_comments')->findAll());
        }
    }

    private function request(string $user, string $method, array $post = []): void
    {
        $_SESSION['user'] = $this->container['userModel']->getById($this->users[$user]);
        $this->container['request'] = new Request($this->container, [
            'PHP_SELF' => '/index.php',
            'REQUEST_METHOD' => $method,
        ], ['artifact_id' => (string) $this->artifact, 'version' => '1'], $post);
    }
}
