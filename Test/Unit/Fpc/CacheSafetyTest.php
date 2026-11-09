<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Fpc;

use PHPUnit\Framework\TestCase;

/**
 * Full-page cache stores HTML for all visitors. Anything that renders into HTML (templates, layout,
 * blocks, view models) must not read visitor cookies or claims. Only server commerce events read them.
 */
class CacheSafetyTest extends TestCase
{
    private const FORBIDDEN = [
        '_cwid', '_cwms', '_cws', '_cwvs', 'getCookie', 'CookieManager', 'VisitorContext',
        'VisitorCookies', 'VisitorSyncService', 'CollectorClient', 'getSummary', 'pendingPurchase',
        'REMOTE_ADDR', 'getRemoteAddress', 'isUserNotAllowSaveCookie', 'isUserAllowSaveCookie',
        'ConsentResolverInterface',
    ];

    /** @return string[] */
    private function htmlProducingFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        foreach (['view/frontend/templates', 'view/frontend/layout', 'Block', 'ViewModel'] as $dir) {
            if (!is_dir("$root/$dir")) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir"));
            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match('/\.(php|phtml|xml)$/', $file->getFilename()) === 1) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    public function testHtmlProducingCodeNeverReferencesVisitorCookiesOrClaims(): void
    {
        $files = $this->htmlProducingFiles();
        $this->assertNotEmpty($files);

        $violations = [];
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            foreach (self::FORBIDDEN as $token) {
                if (str_contains($content, $token)) {
                    $violations[] = basename($file) . ' contains ' . $token;
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function testTheSnippetTemplateIsStaticAndNeverCallsMagento(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/view/frontend/templates/lastmile.phtml');

        // Only the cookie notice's own allow cookie is read, in the browser.
        $this->assertStringContainsString('user_allowed_save_cookie', $template);
        foreach (['_cwid', '_cws', '_cwvs', '_cwms', '$_COOKIE', 'customer/section/load', 'cwax-visitor',
            'fetch(', 'XMLHttpRequest', 'sendBeacon', '$.ajax'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $template);
        }
    }

    public function testNoPrivateContentSectionIsRegistered(): void
    {
        $root = dirname(__DIR__, 3);

        $this->assertFileDoesNotExist($root . '/etc/frontend/sections.xml');
        $this->assertFileDoesNotExist($root . '/CustomerData/Visitor.php');
        foreach (glob($root . '/etc{,/*}/*.xml', GLOB_BRACE) ?: [] as $file) {
            $this->assertStringNotContainsString('SectionPoolInterface', (string) file_get_contents($file), $file);
            $this->assertStringNotContainsString('cwax-visitor', (string) file_get_contents($file), $file);
        }
    }
}
