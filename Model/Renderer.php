<?php

namespace Kanboard\Plugin\Broadside\Model;

use Dom\HTMLDocument;
use Kanboard\Core\Markdown;
use Pimple\Container;

class Renderer
{
    public const CDN_SOURCES = 'https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://unpkg.com '
        .'https://cdn.tailwindcss.com https://code.jquery.com';

    public const CSP = [
        'sandbox' => 'allow-scripts allow-popups',
        'default-src' => "'none'",
        'script-src' => "'unsafe-inline' ".self::CDN_SOURCES,
        'style-src' => "'unsafe-inline' ".self::CDN_SOURCES.' https://fonts.googleapis.com',
        'font-src' => 'https://fonts.gstatic.com',
        'img-src' => 'data: blob:',
        'connect-src' => "'none'",
        'form-action' => "'none'",
        'frame-ancestors' => "'self'",
        'base-uri' => "'none'",
    ];

    // Keep delimiters inside CSS strings, functions and nested blocks together.
    private const CSS_GROUPS = <<<'REGEX'
        (?(DEFINE)
            (?<quoted> "(?:[^"\\]++|\\.)*" | '(?:[^'\\]++|\\.)*' )
            (?<parens> \((?:(?&quoted)|(?&parens)|\\.|[^()"'\\]++)*\) )
            (?<block> \{(?:(?&quoted)|(?&parens)|(?&block)|\\.|[^{}()"'\\]++)*\} )
        )
        REGEX;

    public function __construct(
        private readonly Container $container,
        private readonly string $pluginsRoot = PLUGINS_DIR,
    ) {
    }

    public function render(string $body, string $type, mixed $theme = 'dark', mixed $preset = 'newburgh', bool $readOnly = false): string
    {
        if ($type === 'markdown') {
            $parser = (new Markdown($this->container, false))->setSafeMode(true)->setMarkupEscaped(true);
            $body = '<main class="bs-doc">'.$parser->text($body).'</main>';
        }

        $document = HTMLDocument::createFromString($body === '' ? '<!doctype html>' : $body, LIBXML_NOERROR, 'UTF-8');
        $classes = $document->documentElement->classList;
        $classes->remove('theme-dark', 'theme-light', 'preset-newburgh', 'preset-shadcn');
        $classes->add($theme === 'light' ? 'theme-light' : 'theme-dark');
        $classes->add($preset === 'shadcn' ? 'preset-shadcn' : 'preset-newburgh');

        $tokens = $document->createElement('style');
        $tokens->id = 'broadside-tokens';
        $tokens->textContent = $this->tokenSheet();
        $base = $document->createElement('style');
        $base->id = 'broadside-base';
        $base->textContent = file_get_contents(__DIR__.'/../Assets/css/base.css');
        $bridge = $document->createElement('script');
        $bridge->id = 'broadside-bridge';
        if ($readOnly) {
            $bridge->textContent = <<<'JS'
                window.broadside = Object.freeze({
                    readOnly: true,
                    submit: () => Promise.reject(new Error('read-only link')),
                    results: () => Promise.reject(new Error('read-only link')),
                    onTheme() { return () => {}; },
                });
                JS;
        } else {
            $bridge->setAttribute('data-nonce', bin2hex(random_bytes(16)));
            $bridge->textContent = file_get_contents(__DIR__.'/../Assets/js/frame-bridge.js');
        }

        // The HTML parser supplies a head even for fragments and headless documents.
        $document->documentElement->firstElementChild->prepend($tokens, $base, $bridge);
        $doctype = $document->doctype === null ? '<!doctype html>' : '';
        return $doctype.$document->saveHtml();
    }

    private function tokenSheet(): string
    {
        $harbor = $this->pluginsRoot.'/Harbor/Assets/css/tokens.css';
        if (is_file($harbor) && is_readable($harbor)) {
            $sheet = file_get_contents($harbor);
            if ($sheet !== false) {
                return $this->harborTokens($sheet);
            }
        }

        return file_get_contents(__DIR__.'/../Assets/css/tokens-fallback.css');
    }

    private function harborTokens(string $css): string
    {
        $css = preg_replace('~(?&quoted)(*SKIP)(*F)|/\*.*?\*/'.self::CSS_GROUPS.'~sx', ' ', $css) ?? '';
        $rulePattern = <<<'REGEX'
            \G ((?:(?&quoted)|(?&parens)|\\.|[^;{}()"'\\]++)*) ((?&block)|;)
            REGEX;
        $declarationPattern = <<<'REGEX'
            \G ((?:(?&quoted)|(?&parens)|(?&block)|\\.|[^;{}()"'\\]++)*) (?:;|\z)
            REGEX;
        $rootSelector = '~\A(?:html(?::root)?|:root)(?:\.(?:theme|preset)-[\w-]+|:not\(\.(?:theme|preset)-[\w-]+\))*\z~';

        preg_match_all('~'.$rulePattern.self::CSS_GROUPS.'~sx', $css, $rules, PREG_SET_ORDER);
        $tokens = [];
        foreach ($rules as [, $selectorList, $block]) {
            $selectorList = trim($selectorList);
            if ($block === ';' || str_starts_with($selectorList, '@')) {
                continue;
            }

            $selectors = preg_split('~(?:(?&quoted)|(?&parens)|\\.)(*SKIP)(*F)|,'.self::CSS_GROUPS.'~sx', $selectorList);
            $selectors = preg_grep($rootSelector, array_map('trim', $selectors ?: []));
            if ($selectors === []) {
                continue;
            }

            preg_match_all('~'.$declarationPattern.self::CSS_GROUPS.'~sx', substr($block, 1, -1), $declarations);
            $declarations = preg_grep('/\A(?:--[\w-]+|(?i:color-scheme))\s*:/', array_map('trim', $declarations[1]));
            if ($declarations !== []) {
                $tokens[] = implode(', ', $selectors).' { '.implode('; ', $declarations).'; }';
            }
        }

        return implode("\n", $tokens);
    }
}
