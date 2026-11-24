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

    public function __construct(
        private readonly Container $container,
        private readonly string $pluginsRoot = PLUGINS_DIR,
    ) {
    }

    public function render(string $body, string $type, mixed $theme = 'dark', mixed $preset = 'newburgh'): string
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
        $bridge->setAttribute('data-nonce', bin2hex(random_bytes(16)));
        $bridge->textContent = file_get_contents(__DIR__.'/../Assets/js/frame-bridge.js');

        // The HTML parser supplies a head even for fragments and headless documents.
        $document->documentElement->firstElementChild->prepend($tokens, $base, $bridge);
        $doctype = $document->doctype === null ? '<!doctype html>' : '';
        return $doctype.$document->saveHtml();
    }

    private function tokenSheet(): string
    {
        $harbor = $this->pluginsRoot.'/Harbor/Assets/css/tokens.css';
        if (is_file($harbor) && is_readable($harbor)) {
            return file_get_contents($harbor);
        }

        return file_get_contents(__DIR__.'/../Assets/css/tokens-fallback.css');
    }
}
