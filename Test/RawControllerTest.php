<?php

use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Plugin\Broadside\Controller\RawController;
use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class RawControllerTest extends Base
{
    use BroadsideHarness;

    private int $artifactId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['route']->enable();
        $this->setUpBroadside();
        $published = $this->container['broadsideArtifactModel']->publish($this->project, $this->users['member'], [
            'title' => 'Report',
            'type' => 'html',
            'body' => '<h1>First version</h1>',
        ]);
        $this->artifactId = $published['artifact_id'];
    }

    public function testViewerGetsRequestedVersionWithSandboxPolicy(): void
    {
        $this->container['broadsideArtifactModel']->publish($this->project, $this->users['member'], [
            'artifact_id' => $this->artifactId,
            'title' => 'Updated report',
            'type' => 'html',
            'body' => '<h1>Second version</h1>',
        ]);

        [$status, $headers, $body] = $this->requestRaw('viewer', 1, ['theme' => 'light', 'preset' => 'shadcn']);
        $this->assertSame(200, $status);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('text/html; charset=utf-8', $headers['Content-Type']);
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertStringContainsString('no-store', $headers['Cache-Control']);
        $this->assertStringContainsString('<h1>First version</h1>', $body);
        $this->assertStringContainsString('theme-light preset-shadcn', $body);

        $directives = [];
        foreach (explode(';', trim($headers['Content-Security-Policy'], "; \t")) as $directive) {
            [$name, $value] = explode(' ', trim($directive), 2);
            $directives[$name] = $value;
        }
        foreach ([
            'sandbox' => 'allow-scripts allow-popups',
            'default-src' => "'none'",
            'connect-src' => "'none'",
            'form-action' => "'none'",
            'frame-ancestors' => "'self'",
            'img-src' => 'data: blob:',
            'font-src' => 'https://fonts.gstatic.com',
        ] as $directive => $value) {
            $this->assertSame($value, $directives[$directive] ?? null, $directive);
        }
        $cdn = [
            'https://cdnjs.cloudflare.com',
            'https://cdn.jsdelivr.net',
            'https://unpkg.com',
            'https://cdn.tailwindcss.com',
            'https://code.jquery.com',
        ];
        $this->assertEqualsCanonicalizing(
            ["'unsafe-inline'", ...$cdn],
            explode(' ', $directives['script-src'])
        );
        $this->assertEqualsCanonicalizing(
            ["'unsafe-inline'", ...$cdn, 'https://fonts.googleapis.com'],
            explode(' ', $directives['style-src'])
        );
    }

    public function testOutsiderAndUnknownVersionHaveIdenticalNotFoundResponses(): void
    {
        $outsider = $this->requestRaw('outsider');
        $missing = $this->requestRaw('viewer', 99);

        $this->assertSame(404, $outsider[0]);
        $this->assertSame($outsider, $missing);
        $this->assertStringNotContainsString('First version', $outsider[2]);
    }

    public function testRemovedArtifactIsNotFound(): void
    {
        $this->container['broadsideArtifactModel']->remove($this->artifactId);
        [$status] = $this->requestRaw('viewer');
        $this->assertSame(404, $status);
    }

    public function testRequestCannotChooseTokenFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'broadside-');
        try {
            file_put_contents($file, ':root { --request-file-sentinel: 1; }');
            [$status, , $body] = $this->requestRaw('viewer', 1, [
                'tokens' => $file,
                'tokens_path' => $file,
                'plugins_root' => $file,
            ]);
            $this->assertSame(200, $status);
            $this->assertStringNotContainsString('--request-file-sentinel', $body);
        } finally {
            unlink($file);
        }
    }

    private function requestRaw(string $user, int $version = 1, array $query = []): array
    {
        $_SESSION['user'] = $this->container['userModel']->getById($this->users[$user]);
        $this->container['request'] = new Request($this->container, [], $query);
        $response = $this->getMockBuilder(Response::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['send'])
            ->getMock();
        $response->expects($this->once())->method('send');
        $response->withContentSecurityPolicy(['script-src' => "'self'"])->withXframe();
        $this->container['response'] = $response;

        $route = $this->container['route']->findRoute("broadside/raw/{$this->artifactId}/{$version}");
        $this->assertSame(['controller' => 'RawController', 'action' => 'show', 'plugin' => 'Broadside'], $route);
        (new RawController($this->container))->show();

        return array_map(
            static fn ($name) => (new ReflectionProperty(Response::class, $name))->getValue($response),
            ['httpStatusCode', 'httpHeaders', 'httpBody']
        );
    }
}
