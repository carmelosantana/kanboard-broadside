<?php

namespace Kanboard\Plugin\Broadside\Model;

use Dom\HTMLDocument;

final class Lint
{
    // Match CSS strings and balanced functions so delimiters in their values stay intact.
    private const CSS_GROUPS = <<<'REGEX'
        (?(DEFINE)
            (?<quoted> "(?:[^"\\]++|\\.)*" | '(?:[^'\\]++|\\.)*' )
            (?<parens> \((?:(?&quoted)|(?&parens)|[^()"']++)*\) )
        )
        REGEX;

    private const FONT_FAMILY = <<<'REGEX'
        ~
        ^ (?: inherit | var\(--font\) | (?&generic) (?: \s*,\s* (?&generic) )* )
        \s* (?: !\s*important\s* )? $
        (?(DEFINE)
            (?<generic> monospace | ui-monospace | sans-serif | serif | system-ui | ui-sans-serif
                | ui-serif | cursive | fantasy | math | emoji )
        )
        ~ixD
        REGEX;

    public static function check(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $warnings = [];
        foreach ($document->querySelectorAll('style, [style], script[src], link[href]') as $element) {
            if ($element->localName === 'style') {
                self::checkCss($element->textContent, $warnings);
            }
            if ($element->hasAttribute('style')) {
                self::checkCss($element->getAttribute('style'), $warnings);
            }

            $attribute = match ($element->localName) {
                'script' => 'src',
                'link' => 'href',
                default => null,
            };
            if ($attribute !== null && $element->hasAttribute($attribute)) {
                $url = trim($element->getAttribute($attribute));
                $directive = $attribute === 'src' ? 'script-src' : 'style-src';
                if (! self::isAllowedUrl($url, $directive)) {
                    $warnings[] = "External {$element->localName} URL is outside the allowlist: ".$url;
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    private static function checkCss(string $css, array &$warnings): void
    {
        $css = preg_replace('~(?&quoted)(*SKIP)(*F)|/\*.*?\*/'.self::CSS_GROUPS.'~sx', ' ', $css) ?? $css;
        $declaration = <<<'REGEX'
            (?: ^ | [;{] ) \s* ([\w-]+) \s* : \s*
            ((?:(?&quoted)|(?&parens)|[^"'();{}]++)*)
            (?= [;}] | $ )
            REGEX;
        $maskedParts = <<<'REGEX'
            (?&quoted) | \burl(?&parens) | \bvar(?=\(\s*--)(?&parens)
            REGEX;
        preg_match_all('~'.$declaration.self::CSS_GROUPS.'~sx', $css, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $property, $value]) {
            $masked = preg_replace_callback('~'.$maskedParts.self::CSS_GROUPS.'~isx', static function (array $part): string {
                return match (true) {
                    preg_match('/^var\(\s*--font[\w-]*\s*[,)]/i', $part[0]) === 1 => 'var(--font)',
                    strncasecmp($part[0], 'var(', 4) === 0 => 'var(--token)',
                    default => '""',
                };
            }, $value) ?? $value;

            if (strcasecmp($property, 'font-family') === 0
                && ! preg_match(self::FONT_FAMILY, $masked)) {
                $warnings[] = 'Use inherit, a font token, or only generic families for font-family: '.trim($value);
            }

            $colour = <<<'REGEX'
                \#(?:[a-f0-9]{8}|[a-f0-9]{6}|[a-f0-9]{4}|[a-f0-9]{3})(?![\w-])
                | \b(?:rgba?|hsla?)(?&parens)
                REGEX;
            preg_match_all('~'.$colour.self::CSS_GROUPS.'~isx', $masked, $colours);
            foreach ($colours[0] as $literal) {
                $warnings[] = 'Use a colour token instead of '.$literal;
            }
        }
    }

    private static function isAllowedUrl(string $url, string $directive): bool
    {
        $parts = parse_url(str_starts_with($url, '//') ? 'https:'.$url : $url);
        $origin = strtolower(($parts['scheme'] ?? '').'://'.($parts['host'] ?? ''));

        return ($parts['port'] ?? 443) === 443 && in_array($origin, explode(' ', Renderer::CSP[$directive]), true);
    }
}
