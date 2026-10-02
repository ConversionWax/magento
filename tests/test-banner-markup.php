<?php
/**
 * Plain PHP test for the widget mode option (no Magento needed).
 *
 * Run: php tests/test-banner-markup.php
 */

require __DIR__ . '/../Model/BannerMarkup.php';

use Cwax\Banner\Model\BannerMarkup;

$failures = 0;

function check($label, $actual, $expected)
{
    global $failures;
    if ($actual === $expected) {
        echo "ok   - $label\n";
        return;
    }
    $failures++;
    echo "FAIL - $label\n  expected: $expected\n  actual:   $actual\n";
}

$div = '<div data-cwax-id="abc-1"></div>';

check('default mode returns the pasted div unchanged', BannerMarkup::build($div, '', 'original'), $div);
check('unknown mode returns the pasted div unchanged', BannerMarkup::build($div, 'bogus', 'original'), $div);
check(
    'replace mode wraps the original html',
    BannerMarkup::build($div, 'replace', '<p>Original</p>'),
    '<div data-cwax-id="abc-1" data-cwax-mode="replace"><p>Original</p></div>'
);
check(
    'replace mode keeps other attributes and works with other elements',
    BannerMarkup::build('<h2 class="t" data-cwax-id="abc-1"></h2>', 'replace', 'Hello'),
    '<h2 class="t" data-cwax-id="abc-1" data-cwax-mode="replace">Hello</h2>'
);
check(
    'replace mode with no original html leaves the div unchanged',
    BannerMarkup::build($div, 'replace', "  \n"),
    $div
);
check(
    'replace mode keeps surrounding markup such as the stylesheet link',
    BannerMarkup::build($div . "\n<link rel=\"stylesheet\" href=\"https://cfg.conversionwax.com/abc-1.css\">", 'replace', 'x'),
    '<div data-cwax-id="abc-1" data-cwax-mode="replace">x</div>' . "\n<link rel=\"stylesheet\" href=\"https://cfg.conversionwax.com/abc-1.css\">"
);
check(
    'a div that already has a mode is not changed twice',
    BannerMarkup::build('<div data-cwax-id="a" data-cwax-mode="replace">kept</div>', 'replace', 'x'),
    '<div data-cwax-id="a" data-cwax-mode="replace">kept</div>'
);
check(
    'html without a ConversionWax placeholder is returned unchanged',
    BannerMarkup::build('<div class="other"></div>', 'replace', 'x'),
    '<div class="other"></div>'
);
check(
    'a placeholder that already has content is not changed',
    BannerMarkup::build('<div data-cwax-id="a">already</div>', 'replace', 'x'),
    '<div data-cwax-id="a">already</div>'
);

check(
    'dollar signs and backslashes in the original html are kept literally',
    BannerMarkup::build($div, 'replace', 'Only $5 \\1 today'),
    '<div data-cwax-id="abc-1" data-cwax-mode="replace">Only $5 \\1 today</div>'
);

exit($failures ? 1 : 0);
