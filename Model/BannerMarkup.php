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

    public static function build(string $bannerDiv, string $mode, string $originalHtml): string
    {
        $original = trim($originalHtml);
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
