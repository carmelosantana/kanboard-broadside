<?php

use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Response\ResponseBuilder;
use JsonRPC\Server;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Broadside\Api\BroadsideProcedure;
use Kanboard\Plugin\Broadside\Controller\ArtifactController;
use Kanboard\Plugin\Broadside\Controller\RawController;
use Kanboard\Plugin\Broadside\Controller\ShareController;
use Kanboard\Plugin\Broadside\Model\Access;
use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class ShareTest extends Base
{
    use BroadsideHarness;

    private int $artifactId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['api'] = new Server();
        $this->container['route']->enable();
        $this->setUpBroadside();
        $this->container['configModel']->save(['application_url' => 'https://board.example.test/']);
        $this->container['memoryCache']->flush();
        $this->artifactId = $this->container['broadsideArtifactModel']->publish(
            $this->project, $this->users['member'], [
                'title' => 'Report',
                'type' => 'markdown',
                'body' => '# Shared report',
            ]
        )['artifact_id'];
    }

    public function testManagerSharesAndRevokesThroughRegisteredProcedure(): void
    {
        $this->loginAs('manager');
        $handler = $this->container['api']->getProcedureHandler();
        $params = ['artifact_id' => $this->artifactId];
        $shared = $handler->executeProcedure('artifactShare', $params + ['enabled' => true]);
        $url = $shared['url'];
        $this->assertMatchesRegularExpression('~^https://board\.example\.test/broadside/s/[A-Za-z0-9_-]{43}$~D', $url);
        $token = basename($url);
        $this->assertSame(32, strlen(base64_decode(strtr($token, '-_', '+/'), true)));
        $this->assertSame($token, $this->storedToken());
        $this->assertSame($shared, $handler->executeProcedure('artifactShare', $params + ['enabled' => true]));

        $this->assertSame(200, $this->visit(parse_url($url, PHP_URL_PATH))[0]);
        $this->loginAs('manager');
        $this->assertNull($handler->executeProcedure('artifactShare', $params + ['enabled' => false])['url']);
        $this->assertNull($this->storedToken());
        $this->assertSame(404, $this->visit(parse_url($url, PHP_URL_PATH))[0]);

        $this->loginAs('manager');
        $reshared = $handler->executeProcedure('artifactShare', $params + ['enabled' => true]);
        $this->assertNotSame($url, $reshared['url']);
        $this->assertSame(404, $this->visit(parse_url($url, PHP_URL_PATH))[0]);
    }

    public function testMemberAndApplicationCredentialGet403(): void
    {
        $procedure = new BroadsideProcedure($this->container);
        $this->loginAs('member');
        $this->assertRpcForbidden(fn () => $procedure->artifactShare($this->artifactId, true));
        $this->loginAs('manager');
        $this->container['api'] = new Server('', ['PHP_AUTH_USER' => 'jsonrpc']);
        $this->assertRpcForbidden(fn () => $procedure->artifactShare($this->artifactId, true));
        $this->assertNull($this->storedToken());
    }

    public function testAnonymousShareUsesTheRawSandboxAndAReadOnlyBridge(): void
    {
        $this->loginAs('manager');
        $shared = (new BroadsideProcedure($this->container))->artifactShare($this->artifactId, true);
        [, $privateHeaders] = $this->visit("/broadside/raw/{$this->artifactId}/1", 'viewer');
        [$status, $headers, $html] = $this->visit(parse_url($shared['url'], PHP_URL_PATH));
        $this->assertSame(200, $status);
        $this->assertSame($privateHeaders['Content-Security-Policy'], $headers['Content-Security-Policy']);
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertSame('no-referrer', $headers['Referrer-Policy']);
        $this->assertStringContainsString('no-store', $headers['Cache-Control']);
        $this->assertStringContainsString('<h1>Shared report</h1>', $html);
        $this->assertStringContainsString('id="broadside-tokens"', $html);
        $this->assertStringNotContainsString('broadside-comments', $html);
        $this->assertStringNotContainsString('broadside:submit', $html);
        $this->assertStringNotContainsString('broadside:results', $html);
        $this->assertMatchesRegularExpression('/\breadOnly\s*:\s*true\b/', $html);
    }

    public function testUnknownPartialAndRemovedSharesReturn404(): void
    {
        $this->loginAs('manager');
        $shared = (new BroadsideProcedure($this->container))->artifactShare($this->artifactId, true);
        $path = parse_url($shared['url'], PHP_URL_PATH);
        $last = substr($path, -1) === 'A' ? 'B' : 'A';
        foreach (['/broadside/s/'.str_repeat('a', 43), substr($path, 0, -1), substr($path, 0, -1).$last] as $invalid) {
            $this->assertSame(404, $this->visit($invalid)[0]);
        }
        $this->container['broadsideArtifactModel']->remove($this->artifactId);
        $this->assertSame(404, $this->visit($path)[0]);
    }

    public function testShareAuthorizationComparesTheEntireStoredToken(): void
    {
        $token = str_repeat('a', 42).'B';
        $artifact = ['share_token' => $token];
        $access = new Access($this->container);
        $this->assertTrue($access->canViewShare($artifact, $token));
        $this->assertFalse($access->canViewShare($artifact, substr($token, 0, -1)));
        $this->assertFalse($access->canViewShare($artifact, strtolower($token)));
    }

    public function testManagerCanToggleShareFromViewer(): void
    {
        $path = "/broadside/artifact/{$this->artifactId}";
        [, , $memberHtml] = $this->visit($path, 'member');
        $this->assertStringNotContainsString('Create public link', $memberHtml);
        [, , $managerHtml] = $this->visit($path, 'manager');
        $this->assertStringContainsString('Create public link', $managerHtml);
        [$action, $fields] = $this->shareForm($managerHtml);
        $this->assertSame('1', $fields['enabled']);
        $this->assertSame(303, $this->visit($action, 'manager', $fields)[0]);

        [, , $managerHtml] = $this->visit($path, 'manager');
        $this->assertStringContainsString('/broadside/s/'.$this->storedToken(), $managerHtml);
        $this->assertStringContainsString('Revoke public link', $managerHtml);
        [, , $memberHtml] = $this->visit($path, 'member');
        $this->assertStringNotContainsString($this->storedToken(), $memberHtml);
        [$action, $fields] = $this->shareForm($managerHtml);
        $this->assertSame('0', $fields['enabled']);
        $this->assertSame(303, $this->visit($action, 'manager', $fields)[0]);
        $this->assertNull($this->storedToken());

        $this->expectException(AccessForbiddenException::class);
        $this->visit($action, 'member', ['csrf_token' => $this->container['token']->getCSRFToken(), 'enabled' => '1']);
    }

    private function shareForm(string $html): array
    {
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR);
        $xpath = new DOMXPath($document);
        $form = $xpath->query('//form[@method="post"][button[@name="enabled"]]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);

        return [$form->getAttribute('action'), [
            'csrf_token' => $xpath->evaluate('string(input[@name="csrf_token"]/@value)', $form),
            'enabled' => $xpath->evaluate('string(button[@name="enabled"]/@value)', $form),
        ]];
    }

    private function visit(string $uri, ?string $user = null, ?array $post = null): array
    {
        unset($_SESSION['user']);
        if ($user !== null) {
            $this->loginAs($user);
        }
        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);
        $this->container['request'] = new Request($this->container, [
            'PHP_SELF' => '/index.php',
            'REQUEST_METHOD' => $post === null ? 'GET' : 'POST',
            'REQUEST_URI' => $uri,
        ], $query, $post ?? []);
        $response = $this->getMockBuilder(Response::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['send'])
            ->getMock();
        $this->container['response'] = $response;
        $this->container['router']->dispatch();
        $controller = $this->container['router']->getController();
        $action = $this->container['router']->getAction();
        $this->assertSame('Broadside', $this->container['router']->getPlugin());
        if ($user === null) {
            $this->assertTrue($this->container['applicationAuthorization']->isAllowed($controller, $action, Role::APP_PUBLIC));
        }
        $class = match ($controller) {
            'ArtifactController' => ArtifactController::class,
            'RawController' => RawController::class,
            'ShareController' => ShareController::class,
        };
        (new $class($this->container))->$action();

        return array_map(
            static fn ($name) => (new ReflectionProperty(Response::class, $name))->getValue($response),
            ['httpStatusCode', 'httpHeaders', 'httpBody']
        );
    }

    private function storedToken(): ?string
    {
        return $this->container['db']->table('broadside_artifacts')->eq('id', $this->artifactId)
            ->findOne()['share_token'];
    }

    private function loginAs(string $user): void
    {
        $_SESSION['user'] = $this->container['userModel']->getById($this->users[$user]);
    }

    private function assertRpcForbidden(Closure $call): void
    {
        try {
            $call();
            $this->fail('Expected JSON-RPC 403 Forbidden');
        } catch (AccessDeniedException $e) {
            $response = json_decode(ResponseBuilder::create()->withException($e)->build(), true);
            $this->assertSame(403, $response['error']['code']);
        }
    }
}
