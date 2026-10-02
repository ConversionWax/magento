<?php
namespace Cwax\Banner\Model;

/**
 * Builds the widget markup. In "replace" mode the empty ConversionWax placeholder from the pasted
 * embed code wraps the original page content, so the embed can hide it while the config loads and
 * swap it for the matching body text variant. The pasted embed code and the original html are both
 * entered by a store admin, the same trust level as the existing banner_div output.
 */
class BannerMarkup
{
    public const MODE_DEFAULT = '';
    public const MODE_REPLACE = 'replace';

    // An empty placeholder element carrying data-cwax-id and no data-cwax-mode yet.
    private const PLACEHOLDER = '#<(div|span|p|h[1-6])\b((?:(?!data-cwax-mode)[^>])*?\bdata-cwax-id=(["\'])[^"\']*\3(?:(?!data-cwax-mode)[^>])*)>\s*</\1>#i';

    private const ALLOWED_TAGS = ['p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'a', 'strong', 'em', 'br', 'blockquote'];
    private const DROP_TAGS = ['script', 'style', 'iframe', 'svg', 'math', 'object', 'embed', 'noscript', 'template',
        'textarea', 'select', 'option', 'form', 'input', 'button', 'title', 'head', 'audio', 'video'];

    /**
     * Strict allowlist filter for the original text (same tags as sanitize-rules.json, no images).
     * Only a[href] with http, https or mailto survives as an attribute; links get a forced rel.
     */
    public static function sanitizeOriginal(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $doc->getElementsByTagName('body')->item(0);

        return $body ? self::children($body) : '';
    }

    private static function children(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $out .= htmlspecialchars($child->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } elseif ($child instanceof \DOMElement) {
                $name = strtolower($child->nodeName);
                if (in_array($name, self::DROP_TAGS, true)) {
                    continue;
                }
                $inner = self::children($child);
                if (!in_array($name, self::ALLOWED_TAGS, true)) {
                    $out .= $inner;
                } elseif ($name === 'br') {
                    $out .= '<br>';
                } elseif ($name === 'a') {
                    $href = trim($child->getAttribute('href'));
                    $attr = preg_match('#^(https?:|mailto:)#i', $href)
                        ? ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' : '';
                    $out .= '<a' . $attr . ' rel="noopener noreferrer nofollow">' . $inner . '</a>';
                } else {
                    $out .= '<' . $name . '>' . $inner . '</' . $name . '>';
                }
            }
        }

        return $out;
    }

    public static function build(string $bannerDiv, string $mode, string $originalHtml): string
    {
        $original = trim(self::sanitizeOriginal($originalHtml));
        if ($mode !== self::MODE_REPLACE || $original === '') {
            return $bannerDiv;
        }

        return (string)preg_replace(
            self::PLACEHOLDER,
            '<$1$2 data-cwax-mode="replace">' . str_replace(['\\', '$'], ['\\\\', '\\$'], $original) . '</$1>',
            $bannerDiv,
            1
        );
    }
}
