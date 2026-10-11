<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Lastmile;

use Cwax\Banner\Model\Lastmile\VisitorCookies;
use Magento\Framework\Stdlib\Cookie\CookieMetadata;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Cwax\Banner\Api\ConsentResolverInterface;
use Cwax\Banner\Model\Consent\ConsentDecision;
use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VisitorCookiesTest extends TestCase
{
    /** @var array<string, string> */
    private array $jar = [];
    /** @var array<string, PublicCookieMetadata> */
    private array $written = [];
    /** @var string[] */
    private array $deleted = [];

    private function cookies(bool $storage = true): VisitorCookies
    {
        $manager = $this->createMock(CookieManagerInterface::class);
        $manager->method('getCookie')->willReturnCallback(
            fn (string $name) => $this->jar[$name] ?? null
        );
        $manager->method('setPublicCookie')->willReturnCallback(
            function (string $name, string $value, PublicCookieMetadata $meta): void {
                $this->jar[$name] = $value;
                $this->written[$name] = $meta;
            }
        );
        $manager->method('deleteCookie')->willReturnCallback(
            function (string $name): void {
                $this->deleted[] = $name;
                unset($this->jar[$name]);
            }
        );
        $factory = $this->createMock(CookieMetadataFactory::class);
        $factory->method('createPublicCookieMetadata')->willReturnCallback(
            static fn (): PublicCookieMetadata => new PublicCookieMetadata()
        );
        $factory->method('createCookieMetadata')->willReturnCallback(
            static fn (): CookieMetadata => new CookieMetadata()
        );
        $consent = $this->createMock(ConsentResolverInterface::class);
        $consent->method('resolve')->willReturn(
            new ConsentDecision([ConsentDecisionInterface::SIGNAL_STORAGE => $storage])
        );

        return new VisitorCookies(
            $manager,
            $factory,
            $consent,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testGetSummaryAcceptsASignedBlobShape(): void
    {
        $this->jar['_cwvs'] = 'eyJ2IjoxfQ.c2lnLW5hdHVyZQ';

        $this->assertSame('eyJ2IjoxfQ.c2lnLW5hdHVyZQ', $this->cookies()->getSummary());
    }

    #[DataProvider('badSummaries')]
    public function testGetSummaryRejectsMalformedValues(string $value): void
    {
        $this->jar['_cwvs'] = $value;

        $this->assertNull($this->cookies()->getSummary());
    }

    /** @return array<string, array{string}> */
    public static function badSummaries(): array
    {
        return [
            'no dot' => ['abcdef'],
            'two dots' => ['a.b.c'],
            'script' => ['<script>.x'],
            'too long' => [str_repeat('a', 2200) . '.sig'],
            'trailing newline' => ["abc.def\n"],
            'empty' => [''],
        ];
    }

    public function testGetSessionIdReadsSidFromDecodedAndEncodedJson(): void
    {
        $json = '{"sid":"11111111-1111-4111-8111-111111111111","st":1,"la":1,"pv":1,"src":"direct","med":"direct"}';
        $this->jar['_cws'] = $json;
        $this->assertSame('11111111-1111-4111-8111-111111111111', $this->cookies()->getSessionId());

        $this->jar['_cws'] = rawurlencode($json);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $this->cookies()->getSessionId());

        $this->jar['_cws'] = 'nope';
        $this->assertNull($this->cookies()->getSessionId());
    }

    public function testWriteSummaryIsPersistentLaxAndReadableByScript(): void
    {
        $this->cookies()->writeSummary('abc.def', 1);

        $meta = $this->written['_cwvs'];
        $this->assertSame('abc.def', $this->jar['_cwvs']);
        $this->assertSame(34560000, $meta->getDuration());
        $this->assertSame('/', $meta->getPath());
        $this->assertSame('Lax', $meta->getSameSite());
        $this->assertTrue($meta->getSecure());
        $this->assertFalse($meta->getHttpOnly());
    }

    public function testWriteSessionIsASessionCookieWithNoMaxAge(): void
    {
        $session = [
            'sid' => '11111111-1111-4111-8111-111111111111',
            'st' => 1700000000000,
            'la' => 1700000000000,
            'pv' => 1,
            'src' => 'google',
            'med' => 'organic',
            'geo' => 'US,CA',
        ];

        $this->cookies()->writeSession($session);

        $meta = $this->written['_cws'];
        $this->assertNull($meta->getDuration());
        $this->assertSame('/', $meta->getPath());
        $this->assertSame('Lax', $meta->getSameSite());
        $this->assertFalse($meta->getHttpOnly());
        $this->assertSame($session, json_decode($this->jar['_cws'], true));
    }

    public function testCookiesAreAlwaysSecure(): void
    {
        // Contract: Secure regardless of the request scheme (eg TLS terminated at a proxy).
        $cookies = $this->cookies();
        $cookies->writeSummary('abc.def', 1);
        $cookies->writeSession(
            ['sid' => 'abcdefgh1234', 'st' => 1, 'la' => 1, 'pv' => 1, 'src' => 'direct', 'med' => 'direct']
        );

        $this->assertTrue($this->written['_cwvs']->getSecure());
        $this->assertTrue($this->written['_cws']->getSecure());
    }

    public function testClearAllDeletesOnlyCookiesThatExist(): void
    {
        $this->jar = ['_cwid' => 'x', '_cwvs' => 'a.b'];

        $this->cookies()->clearAll();

        $this->assertEqualsCanonicalizing(['_cwid', '_cwvs'], $this->deleted);
    }

    public function testWriteSummaryIsSkippedAndStaleCookieClearedWithoutStorageConsent(): void
    {
        $this->jar['_cwvs'] = 'old.sig';

        $this->cookies(false)->writeSummary('abc.def', 1);

        $this->assertArrayNotHasKey('_cwvs', $this->written);
        $this->assertSame(['_cwvs'], $this->deleted);
        $this->assertArrayNotHasKey('_cwvs', $this->jar);
    }

    public function testWriteSummaryWithoutConsentAndNoCookieDeletesNothing(): void
    {
        $this->cookies(false)->writeSummary('abc.def', 1);

        $this->assertSame([], $this->written);
        $this->assertSame([], $this->deleted);
    }

    public function testWriteSessionWithInvalidUtf8WritesNothingAndDoesNotThrow(): void
    {
        $this->cookies()->writeSession([
            'sid' => '11111111-1111-4111-8111-111111111111',
            'st' => 1,
            'la' => 1,
            'pv' => 1,
            'src' => "bad\xB1utf8",
            'med' => 'organic',
        ]);

        $this->assertArrayNotHasKey('_cws', $this->written);
        $this->assertArrayNotHasKey('_cws', $this->jar);
    }

    public function testGetSessionIdRejectsMalformedSid(): void
    {
        $this->jar['_cws'] = '{"sid":"<script>alert(1)</script>","st":1}';
        $this->assertNull($this->cookies()->getSessionId());

        $this->jar['_cws'] = '{"sid":123,"st":1}';
        $this->assertNull($this->cookies()->getSessionId());
    }

    public function testClearAllRemovesTheSessionCookie(): void
    {
        $this->jar = ['_cws' => '{"sid":"11111111-1111-4111-8111-111111111111"}'];

        $this->cookies()->clearAll();

        $this->assertSame(['_cws'], $this->deleted);
    }
}
