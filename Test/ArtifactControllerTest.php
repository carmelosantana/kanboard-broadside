<?php

use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Plugin\Broadside\Controller\ArtifactController;
use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class ArtifactControllerTest extends Base
{
    use BroadsideHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['route']->enable();
        $this->setUpBroadside();
    }

    public function testViewerSeesArtifactMetadataNewestFirstAndCanSearchTitles(): void
    {
        $this->publish('Earlier report', 'html', 'manager');
        $id = $this->publish('Current <plan>', 'markdown', 'member');
        $this->publish('Current <plan>', 'markdown', 'member', $id);

        [$status, $html] = $this->visit('viewer', "broadside/project/{$this->project}", 'index');
        $this->assertSame(200, $status);
        $this->assertMatchesRegularExpression('/Current &lt;plan&gt;.*Markdown.*member.*2.*Earlier report.*HTML.*manager.*1/s', $html);
        $this->assertStringContainsString('href="/broadside/artifact/'.$id.'"', $html);
        $this->assertStringContainsString('Assets/css/viewer.css', $html);
        $this->assertStringNotContainsString('Assets/js/viewer.js', $html);

        [, $filtered] = $this->visit('viewer', "broadside/project/{$this->project}", 'index', ['search' => 'cUrReNt']);
        $this->assertStringContainsString('Current &lt;plan&gt;', $filtered);
        $this->assertStringNotContainsString('Earlier report', $filtered);
    }

    public function testEmptyProjectExplainsAgentPublishing(): void
    {
        [$status, $html] = $this->visit('viewer', "broadside/project/{$this->project}", 'index');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('No artifacts yet', $html);
        $this->assertStringContainsString('artifactPublish', $html);
        $this->assertStringContainsString('artifactGetContract', $html);
    }

    public function testViewerUsesSandboxRawRouteVersionHistoryAndReusableToken(): void
    {
        $_SESSION['user'] = $this->container['userModel']->getById($this->users['manager']);
        $this->assertTrue($this->container['configModel']->save(['harbor_preset' => 'shadcn']));
        $this->container['memoryCache']->flush();
        $id = $this->publish('Report', 'html', 'member');
        $this->publish('Report', 'html', 'member', $id);
        $this->publish('Report', 'html', 'member', $id);

        [$status, $html] = $this->visit('viewer', "broadside/artifact/$id", 'show', ['version' => '2']);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Assets/js/viewer.js', $html);
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR);
        $xpath = new DOMXPath($document);
        $iframe = $xpath->query('//iframe')->item(0);
        $this->assertInstanceOf(DOMElement::class, $iframe);
        $this->assertSame('allow-scripts allow-popups allow-forms allow-modals', $iframe->getAttribute('sandbox'));
        $this->assertSame("/broadside/raw/$id/2?theme=auto&preset=shadcn", $iframe->getAttribute('src'));
        $versions = $xpath->query('//details//a[contains(@href, "?version=")]');
        $this->assertSame(
            ["/broadside/artifact/$id?version=3", "/broadside/artifact/$id?version=2", "/broadside/artifact/$id?version=1"],
            array_map(static fn ($node) => $node->getAttribute('href'), iterator_to_array($versions))
        );
        $this->assertSame('page', $versions->item(1)->getAttribute('aria-current'));
        $token = $xpath->query('//*[@data-csrf]')->item(0)?->getAttribute('data-csrf');
        $this->assertTrue($this->container['token']->validateReusableCSRFToken($token));
        $this->assertFalse($this->container['token']->validateCSRFToken($token));
        $this->assertTrue($this->container['token']->validateReusableCSRFToken($token));

        [, $latest] = $this->visit('viewer', "broadside/artifact/$id", 'show');
        $this->assertStringContainsString("/broadside/raw/$id/3?theme=auto&amp;preset=shadcn", $latest);
    }

    public function testOutsiderRemovedArtifactAndUnknownVersionAreNotFound(): void
    {
        $id = $this->publish('Private report', 'html', 'member');
        $path = "broadside/artifact/$id";
        $this->assertSame([404, ''], $this->visit('outsider', $path, 'show'));
        $this->assertSame([404, ''], $this->visit('viewer', $path, 'show', ['version' => '9']));
        $this->container['broadsideArtifactModel']->remove($id);
        $this->assertSame([404, ''], $this->visit('viewer', $path, 'show'));
    }

    private function publish(string $title, string $type, string $user, int $id = 0): int
    {
        return $this->container['broadsideArtifactModel']->publish($this->project, $this->users[$user], [
            'artifact_id' => $id,
            'title' => $title,
            'type' => $type,
            'body' => 'Artifact body',
        ])['artifact_id'];
    }

    private function visit(string $user, string $path, string $action, array $query = []): array
    {
        $_SESSION['user'] = $this->container['userModel']->getById($this->users[$user]);
        $_SESSION['user']['theme'] = 'auto';
        $this->container['request'] = new Request($this->container, [
            'PHP_SELF' => '/index.php',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/'.$path,
        ], $query);
        $response = $this->getMockBuilder(Response::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['send'])
            ->getMock();
        $response->expects($this->once())->method('send');
        $this->container['response'] = $response;

        $this->container['router']->dispatch();
        $this->assertSame('Broadside', $this->container['router']->getPlugin());
        $this->assertSame('ArtifactController', $this->container['router']->getController());
        $this->assertSame($action, $this->container['router']->getAction());
        (new ArtifactController($this->container))->$action();

        return array_map(
            static fn ($name) => (new ReflectionProperty(Response::class, $name))->getValue($response),
            ['httpStatusCode', 'httpBody']
        );
    }
}
