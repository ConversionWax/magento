<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Fpc;

use PHPUnit\Framework\TestCase;

/**
 * Full-page cache stores HTML for all visitors. Anything that renders into HTML (templates, layout,
 * blocks, view models) must not read visitor cookies or claims. Only the AJAX section may.
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

    public function testTheTemplateIsStaticAndTheShimOnlyDefinesTheConsentApi(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/view/frontend/templates/visitor-sync.phtml');

        $this->assertStringContainsString('window.cw.consentState', $template);
        $this->assertStringContainsString('user_allowed_save_cookie', $template);
        $this->assertStringContainsString('mage-cache-storage', $template);
        $this->assertStringNotContainsString("'pending'", $template);
        foreach (['_cwid', '_cws', '_cwvs', '_cwms'] as $name) {
            $this->assertStringNotContainsString($name, $template);
        }
        $this->assertStringNotContainsString('$_COOKIE', $template);
    }

    public function testTheSectionIsRegisteredAsPrivateContent(): void
    {
        $di = (string) file_get_contents(dirname(__DIR__, 3) . '/etc/frontend/di.xml');

        $this->assertStringContainsString('name="cwax-visitor"', $di);
        $this->assertStringContainsString('SectionPoolInterface', $di);
    }
}
