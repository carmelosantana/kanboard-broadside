<?php

use Kanboard\Plugin\Broadside\Api\BroadsideProcedure;
use Kanboard\Plugin\Broadside\Model\Lint;
use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class LintTest extends Base
{
    use BroadsideHarness;

    public function testReportsHardCodedColoursFontsAndExternalHosts(): void
    {
        $html = <<<'HTML'
            <style>
                .sample { color: #f08; background: linear-gradient(RGB(1 2 3 / 50%), hsl(10, 20%, 30%)); }
                .sample { font-family: "Example", serif; }
            </style>
            <p style="border-color: #12345678">Sample</p>
            <script src="https://scripts.example.test/code.js"></script>
            <link rel="stylesheet" href="https://styles.example.test/site.css">
            HTML;

        $warnings = Lint::check($html);
        $this->assertCount(7, $warnings);
        foreach (['#f08', 'RGB(1 2 3 / 50%)', 'hsl(10, 20%, 30%)', '#12345678', 'font-family',
            'scripts.example.test', 'styles.example.test'] as $value) {
            $this->assertStringContainsString($value, implode("\n", $warnings));
        }
    }

    public function testTokensFallbacksAndAllowedUrlsProduceNoWarnings(): void
    {
        $this->assertSame([], Lint::check(<<<'HTML'
            <style>
                /* color: #fff; font-family: Arial; */
                #fade { color: var(--primary); font-family: inherit; }
                .sample {
                    background: linear-gradient(var(--accent, var(--primary, rgb(1, 2, 3))), var(--muted, #fff));
                    font-family: var(--font-body, "Example", sans-serif);
                    content: "A colour looks like #fff";
                }
            </style>
            <p style="color: var(--foreground, hsl(10 20% 30%))">Use #fff for white.</p>
            <script>const text = "font-family: Arial; color: #fff";</script>
            <script SRC="https://CDN.JSDELIVR.NET/npm/alpinejs"></script>
            <link rel="stylesheet" href="//fonts.googleapis.com/css?family=Roboto">
            HTML));
    }

    public function testAllowsGoogleFontsCssAndFlagsScriptOrLookalikeCdn(): void
    {
        $warnings = Lint::check(<<<'HTML'
            <link href="https://fonts.googleapis.com/css?family=Roboto">
            <script src="https://fonts.googleapis.com/code.js"></script>
            <script src="//cdn.jsdelivr.net.example.test/code.js"></script>
            <script src="http://cdn.jsdelivr.net/code.js"></script>
            HTML);

        $this->assertCount(3, $warnings);
    }

    public function testStillChecksValuesAfterAVarFallback(): void
    {
        $warnings = Lint::check(<<<'HTML'
            <p style="background: linear-gradient(var(--accent, #000), #fff);
                      font-family: var(--font-body, sans-serif), Arial;
                      border-color: var(--accent, red) #f00">Sample</p>
            HTML);
        $this->assertCount(3, $warnings);
        $this->assertStringContainsString('#fff', implode("\n", $warnings));
        $this->assertStringContainsString('#f00', implode("\n", $warnings));
    }

    public function testPublishReturnsLintWarningsForHtmlAndSkipsMarkdown(): void
    {
        $this->setUpBroadside();
        $_SESSION['user'] = $this->container['userModel']->getById($this->users['member']);
        $procedure = new BroadsideProcedure($this->container);
        $body = '<p style="color: #fff">Sample</p>';
        $warnings = Lint::check($body);
        $this->assertNotEmpty($warnings);
        $this->assertSame($warnings, $procedure->artifactPublish($this->project, 'Page', $body)['warnings']);
        $this->assertSame([], $procedure->artifactPublish($this->project, 'Page', $body, 'markdown')['warnings']);
    }
}
