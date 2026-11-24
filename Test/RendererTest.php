<?php

use Dom\HTMLDocument;
use Kanboard\Plugin\Broadside\Model\Renderer;
use KanboardTests\units\Base;

class RendererTest extends Base
{
    public function testHtmlInjectsAssetsBeforeAuthoredHeadContent(): void
    {
        $renderer = new Renderer($this->container);
        $html = $renderer->render(
            '<!doctype html><html class="authored theme-dark preset-newburgh"><head><title>Résumé</title></head><body><p>Hello</p></body></html>',
            'html',
            'light',
            'shadcn'
        );
        $document = HTMLDocument::createFromString($html);
        $this->assertSame('authored theme-light preset-shadcn', $document->documentElement->getAttribute('class'));
        $this->assertSame(
            ['style#broadside-tokens', 'style#broadside-base', 'script#broadside-bridge', 'title#'],
            array_map(
                static fn ($element) => $element->localName.'#'.$element->id,
                iterator_to_array($document->querySelectorAll('head > *'))
            )
        );
        $this->assertSame('Résumé', $document->querySelector('title')->textContent);
        $this->assertSame('Hello', $document->querySelector('body p')->textContent);
    }

    public function testFragmentGetsHeadDefaultClassesAndFreshBridgeNonce(): void
    {
        $renderer = new Renderer($this->container);
        $html = $renderer->render('<p>Hello</p>', 'html', 'unknown', 'unknown');
        $document = HTMLDocument::createFromString($html);
        $this->assertSame('theme-dark preset-newburgh', $document->documentElement->getAttribute('class'));
        $bridge = $document->querySelector('head script#broadside-bridge');
        $this->assertNotNull($bridge);
        $nonce = $bridge->getAttribute('data-nonce');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $nonce);
        $this->assertStringNotContainsString($nonce, $renderer->render('<p>Hello</p>', 'html'));
        $this->assertSame('Hello', $document->querySelector('body p')->textContent);
    }

    public function testMarkdownUsesKanboardParserWithSafeMarkupAndLinks(): void
    {
        $html = (new Renderer($this->container))->render(
            "## Notes\n\nSee #123.\n\n<script>alert(1)</script>\n\n[Link](javascript:alert(1))",
            'markdown'
        );
        $document = HTMLDocument::createFromString($html);
        $main = $document->querySelector('body > main.bs-doc');
        $this->assertNotNull($main);
        $this->assertSame('Notes', $main->querySelector('h2')->textContent);
        $this->assertSame('#123', $main->querySelector('a[href*="task_id=123"]')->textContent);
        $this->assertNull($main->querySelector('script'));
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $link = $main->querySelectorAll('a')->item(1);
        $this->assertSame('Link', $link->textContent);
        $this->assertStringNotContainsString('javascript:', $link->getAttribute('href') ?? '');
    }

    public function testTokensComeFromFixedHarborLocationOrBundledFallback(): void
    {
        $root = sys_get_temp_dir().'/broadside-renderer-'.bin2hex(random_bytes(8));
        $directory = $root.'/Harbor/Assets/css';
        mkdir($directory, 0700, true);

        try {
            $renderer = new Renderer($this->container, $root);
            $document = HTMLDocument::createFromString($renderer->render('', 'html'));
            $this->assertSame(
                file_get_contents(__DIR__.'/../Assets/css/tokens-fallback.css'),
                $document->querySelector('style#broadside-tokens')->textContent
            );
            $tokens = <<<'CSS'
                /* Harbor's tokens and application styles share one file. */
                :root { --harbor-fixture: 1; color-scheme: dark; background: red; }
                html { --fixture-html: 2; color: red; }
                :root.preset-newburgh { --fixture-newburgh: 3; }
                html.theme-light, html.preset-shadcn.theme-light { color-scheme: light; --fixture-light: 4; }
                html:root { --fixture-shared: var(--harbor-fixture); --fixture-image: url("data:image/svg+xml;charset=utf-8,fixture"); }
                html:root:not(.theme-light) { --fixture-dark: rgb(0 0 0 / 0.25); }
                header{background:red}
                a{text-decoration:none}
                :root .sidebar { --fixture-chrome: red; }
                @media (prefers-reduced-motion: reduce) {
                    :root { --fixture-nested: red; }
                }
                html.theme-dark, .sidebar { --fixture-mixed: 5; }
                CSS;
            file_put_contents($directory.'/tokens.css', $tokens);
            $document = HTMLDocument::createFromString($renderer->render('', 'html'));
            $injected = $document->querySelector('style#broadside-tokens')->textContent;
            foreach (['header', 'text-decoration', 'background:', 'color: red', '.sidebar', '--fixture-chrome', '--fixture-nested'] as $chrome) {
                $this->assertStringNotContainsString($chrome, $injected);
            }
            foreach ([
                ':root { --harbor-fixture: 1; color-scheme: dark; }',
                'html { --fixture-html: 2; }',
                ':root.preset-newburgh { --fixture-newburgh: 3; }',
                'html.theme-light, html.preset-shadcn.theme-light { color-scheme: light; --fixture-light: 4; }',
                'html:root { --fixture-shared: var(--harbor-fixture); --fixture-image: url("data:image/svg+xml;charset=utf-8,fixture"); }',
                'html:root:not(.theme-light) { --fixture-dark: rgb(0 0 0 / 0.25); }',
                'html.theme-dark { --fixture-mixed: 5; }',
            ] as $rule) {
                $this->assertStringContainsString($rule, $injected);
            }
        } finally {
            if (is_file($directory.'/tokens.css')) {
                unlink($directory.'/tokens.css');
            }
            foreach ([$directory, dirname($directory), $root.'/Harbor', $root] as $path) {
                rmdir($path);
            }
        }
    }
}
