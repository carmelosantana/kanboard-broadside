<?php

use Composer\Autoload\ClassLoader;
use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Server;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Plugin\Broadside\Api\BroadsideProcedure;
use Kanboard\Plugin\Broadside\Controller\ArtifactController;
use Kanboard\Plugin\Broadside\Model\AiConnectorRewriter;
use Kanboard\Plugin\Broadside\Model\AiRewriter;
use Kanboard\Plugin\Broadside\Model\Importer;
use Kanboard\Plugin\Broadside\Model\Lint;
use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class ImporterTest extends Base
{
    use BroadsideHarness;

    private const TOKEN_HTML = '<p style="color: var(--primary)">Report</p>';
    private const ORIGINAL_HTML = '<p style="color: #f08">Report</p>';
    private const SOURCE = 'https://source.example.test/report';

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['api'] = new Server();
        $this->setUpBroadside();
        $this->loginAs('member');
    }

    public function testConformingHtmlAndMarkdownSkipAi(): void
    {
        $rewriter = $this->createMock(AiRewriter::class);
        $rewriter->expects($this->never())->method('isReady');
        $rewriter->expects($this->never())->method('structured');
        $importer = new Importer($this->container, $rewriter);

        $html = $importer->import($this->project, $this->users['member'], 'Report', self::TOKEN_HTML, 'html', self::SOURCE);
        $this->assertFalse($html['rewritten']);
        $this->assertSame([], $html['warnings']);
        $stored = (new BroadsideProcedure($this->container))->artifactGet($html['artifact_id']);
        $this->assertSame(self::TOKEN_HTML, $stored['body']);
        $this->assertSame(self::SOURCE, $stored['source_url']);

        $markdown = $importer->import($this->project, $this->users['member'], 'Markdown', self::ORIGINAL_HTML, 'markdown');
        $this->assertFalse($markdown['rewritten']);
        $this->assertSame([], $markdown['warnings']);
        $stored = (new BroadsideProcedure($this->container))->artifactGet($markdown['artifact_id']);
        $this->assertSame('markdown', $stored['type']);
        $this->assertSame(self::ORIGINAL_HTML, $stored['body']);
    }

    public function testReadyProviderReceivesContractAndOriginalAndPublishesRewrite(): void
    {
        $rewriter = $this->createMock(AiRewriter::class);
        $rewriter->expects($this->once())->method('isReady')->willReturn(true);
        $rewriter->expects($this->once())->method('structured')->willReturnCallback(
            function (array $messages, string $schema): array {
                $this->assertStringContainsString(file_get_contents(__DIR__.'/../CONTRACT.md'), implode("\n", array_column($messages, 'content')));
                $this->assertSame(self::ORIGINAL_HTML, end($messages)['content']);
                $this->assertSame('string', json_decode($schema, true)['properties']['html']['type']);
                return ['html' => self::TOKEN_HTML];
            }
        );
        $result = (new Importer($this->container, $rewriter))->import(
            $this->project, $this->users['member'], 'Report', self::ORIGINAL_HTML, 'html', self::SOURCE
        );

        $this->assertTrue($result['rewritten']);
        $this->assertSame([], $result['warnings']);
        $stored = (new BroadsideProcedure($this->container))->artifactGet($result['artifact_id']);
        $this->assertSame(self::TOKEN_HTML, $stored['body']);
        $this->assertSame(self::SOURCE, $stored['source_url']);
    }

    public function testUnreadyProviderPreservesBodyAndWarnings(): void
    {
        $rewriter = $this->createMock(AiRewriter::class);
        $rewriter->expects($this->once())->method('isReady')->willReturn(false);
        $rewriter->expects($this->never())->method('structured');
        $result = (new Importer($this->container, $rewriter))->import(
            $this->project, $this->users['member'], 'Report', self::ORIGINAL_HTML
        );

        $this->assertFalse($result['rewritten']);
        $this->assertSame(Lint::check(self::ORIGINAL_HTML), $result['warnings']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertSame(self::ORIGINAL_HTML, (new BroadsideProcedure($this->container))->artifactGet($result['artifact_id'])['body']);
    }

    /** @dataProvider failedRewrites */
    public function testFailedRewritePublishesOriginalWithWarnings(callable $rewrite, string $message): void
    {
        $rewriter = $this->createMock(AiRewriter::class);
        $rewriter->expects($this->once())->method('isReady')->willReturn(true);
        $rewriter->expects($this->once())->method('structured')->willReturnCallback($rewrite);

        $result = (new Importer($this->container, $rewriter))->import(
            $this->project, $this->users['member'], 'Report', self::ORIGINAL_HTML, 'html', self::SOURCE
        );

        $this->assertFalse($result['rewritten']);
        $this->assertSame(Lint::check(self::ORIGINAL_HTML), $result['warnings']);
        $this->assertSame($message, $result['ai_error']);
        $stored = (new BroadsideProcedure($this->container))->artifactGet($result['artifact_id']);
        $this->assertSame(self::ORIGINAL_HTML, $stored['body']);
        $this->assertSame(self::SOURCE, $stored['source_url']);
    }

    public static function failedRewrites(): array
    {
        return [
            'provider throws' => [static fn () => throw new Error('Provider unavailable'), 'Provider unavailable'],
            'invalid rewrite' => [static fn () => [], 'AI rewrite must return HTML of at most 5 MiB.'],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testImportRpcPublishesWithoutAiConnectorAndCanCreateANewVersion(): void
    {
        foreach (spl_autoload_functions() as $autoload) {
            if (is_array($autoload) && $autoload[0] instanceof ClassLoader) {
                foreach (array_keys($autoload[0]->getPrefixesPsr4()) as $prefix) {
                    if (str_starts_with($prefix, 'Kanboard\\Plugin\\') && $prefix !== 'Kanboard\\Plugin\\Broadside\\') {
                        $autoload[0]->setPsr4($prefix, []);
                    }
                }
            }
        }
        $this->assertNull(AiConnectorRewriter::fromInstalledPlugin($this->container));

        $rpc = $this->container['api']->getProcedureHandler();
        $result = $rpc->executeProcedure('artifactImport', [
            'project_id' => $this->project, 'title' => 'Original', 'content' => self::ORIGINAL_HTML,
            'source_url' => self::SOURCE,
        ]);
        $id = $result['artifact_id'];
        $this->assertSame(1, $result['version']);
        $this->assertFalse($result['rewritten']);
        $this->assertSame(Lint::check(self::ORIGINAL_HTML), $result['warnings']);
        $this->assertNotEmpty($result['warnings']);

        $updated = $rpc->executeProcedure('artifactImport', [
            'project_id' => $this->project, 'title' => 'Updated', 'content' => self::TOKEN_HTML, 'artifact_id' => $id,
        ]);
        $this->assertSame($id, $updated['artifact_id']);
        $this->assertSame(2, $updated['version']);
        $procedure = new BroadsideProcedure($this->container);
        $this->assertSame($procedure->artifactGet($id)['url'], $updated['url']);
        $this->assertSame(self::ORIGINAL_HTML, $procedure->artifactGet($id, 1)['body']);
        $this->assertSame(self::SOURCE, $procedure->artifactGet($id)['source_url']);
    }

    public function testViewerCannotImport(): void
    {
        $this->loginAs('viewer');
        $this->expectException(AccessDeniedException::class);
        (new BroadsideProcedure($this->container))->artifactImport($this->project, 'Report', self::ORIGINAL_HTML);
    }

    public function testApplicationCredentialCannotImport(): void
    {
        $this->container['api'] = new Server('', ['PHP_AUTH_USER' => 'jsonrpc']);
        $this->expectException(AccessDeniedException::class);
        (new BroadsideProcedure($this->container))->artifactImport($this->project, 'Report', self::ORIGINAL_HTML);
    }

    public function testMemberCanDiscoverAndOpenUploadForm(): void
    {
        $this->assertStringContainsString('action=upload', $this->requestPage('index'));
        $form = $this->requestPage('upload');
        $this->assertStringContainsString('enctype="multipart/form-data"', $form);
        $this->assertStringContainsString('accept=".html,.md"', $form);
        $this->assertStringContainsString('name="csrf_token"', $form);
    }

    public function testViewerCannotDiscoverOrOpenUploadForm(): void
    {
        $this->loginAs('viewer');
        $this->assertStringNotContainsString('action=upload', $this->requestPage('index'));
        $this->expectException(AccessForbiddenException::class);
        $this->requestPage('upload');
    }

    public function testMemberCanUploadHtmlAndMarkdownButNotOtherFiles(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'broadside-upload-');
        try {
            file_put_contents($path, self::ORIGINAL_HTML);
            foreach (['Report.HTML', 'Notes.md', 'Rejected.txt'] as $filename) {
                $this->requestPage('upload', ['title' => $filename, 'csrf_token' => $this->container['token']->getCSRFToken()], [
                    'artifact' => ['name' => $filename, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)],
                ]);
            }
            $procedure = new BroadsideProcedure($this->container);
            $rows = $procedure->artifactList($this->project);
            $this->assertSame(['Notes.md', 'Report.HTML'], array_column($rows, 'title'));
            $this->assertSame(['markdown', 'html'], array_column($rows, 'type'));
            foreach ($rows as $row) {
                $this->assertSame(self::ORIGINAL_HTML, $procedure->artifactGet($row['artifact_id'])['body']);
            }
        } finally {
            unlink($path);
        }
    }

    private function loginAs(string $role): void
    {
        $_SESSION['user'] = $this->container['userModel']->getById($this->users[$role]);
    }

    private function requestPage(string $action, array $post = [], array $files = []): string
    {
        $this->container['request'] = new Request($this->container, [
            'PHP_SELF' => '/index.php',
            'REQUEST_METHOD' => $post === [] ? 'GET' : 'POST',
        ], ['project_id' => (string) $this->project], $post, $files);
        $response = $this->getMockBuilder(Response::class)->setConstructorArgs([$this->container])
            ->onlyMethods(['send'])->getMock();
        $this->container['response'] = $response;
        (new ArtifactController($this->container))->$action();

        return (new ReflectionProperty(Response::class, 'httpBody'))->getValue($response);
    }
}
