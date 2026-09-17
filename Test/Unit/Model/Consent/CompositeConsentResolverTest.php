<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Consent;

use Cwax\Banner\Api\ConsentResolverInterface;
use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Model\Consent\CompositeConsentResolver;
use Cwax\Banner\Model\Consent\ConsentDecision;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CompositeConsentResolver's veto/aggregation logic.
 */
class CompositeConsentResolverTest extends TestCase
{
    private const STORE_ID = 1;

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @param array<string, bool> $signals
     */
    private function resolverReturning(array $signals, array $reasons = []): ConsentResolverInterface
    {
        $resolver = $this->createMock(ConsentResolverInterface::class);
        $resolver->method('resolve')->willReturn(new ConsentDecision($signals, $reasons));

        return $resolver;
    }

    /**
     * With no resolvers configured at all, nothing ever affirmatively grants
     * either signal, so the composite must fail closed: both signals false,
     * no denial reasons (nobody actually denied anything either).
     */
    public function testEmptyResolverListFailsClosed(): void
    {
        $composite = new CompositeConsentResolver([], $this->logger);

        $decision = $composite->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * All resolvers abstaining (empty decisions) must behave identically to
     * an empty resolver list: fail closed, no reasons.
     */
    public function testAllResolversAbstainFailsClosed(): void
    {
        $composite = new CompositeConsentResolver(
            [$this->resolverReturning([]), $this->resolverReturning([])],
            $this->logger
        );

        $decision = $composite->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * A single resolver granting both signals, with nothing else vetoing,
     * results in both signals granted.
     */
    public function testSingleGrantingResolverGrantsBoth(): void
    {
        $composite = new CompositeConsentResolver(
            [$this->resolverReturning([
                ConsentDecisionInterface::SIGNAL_STORAGE => true,
                ConsentDecisionInterface::SIGNAL_MEASUREMENT => true,
            ])],
            $this->logger
        );

        $decision = $composite->resolve(self::STORE_ID);

        $this->assertTrue($decision->isStorageGranted());
        $this->assertTrue($decision->isMeasurementGranted());
    }

    /**
     * Core veto rule: one resolver grants, a second denies -> denial wins
     * for that signal regardless of ordering.
     */
    public function testAnyDenialVetoesAGrant(): void
    {
        $granter = $this->resolverReturning([
            ConsentDecisionInterface::SIGNAL_STORAGE => true,
            ConsentDecisionInterface::SIGNAL_MEASUREMENT => true,
        ]);
        $denier = $this->resolverReturning([
            ConsentDecisionInterface::SIGNAL_STORAGE => false,
            ConsentDecisionInterface::SIGNAL_MEASUREMENT => false,
        ], ['veto_reason']);

        $composite = new CompositeConsentResolver([$granter, $denier], $this->logger);
        $decision = $composite->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertSame(['veto_reason'], $decision->getDenialReasons());
    }

    /**
     * Veto order independence: denial arriving before the grant in the
     * resolver list must still win.
     */
    public function testDenialBeforeGrantStillVetoes(): void
    {
        $denier = $this->resolverReturning([
            ConsentDecisionInterface::SIGNAL_STORAGE => false,
        ], ['veto_reason']);
        $granter = $this->resolverReturning([
            ConsentDecisionInterface::SIGNAL_STORAGE => true,
        ]);

        $composite = new CompositeConsentResolver([$denier, $granter], $this->logger);
        $decision = $composite->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
    }

    /**
     * The two signals are vetoed independently: a resolver denying only
     * storage must not affect a measurement grant from another resolver.
     */
    public function testSignalsAreVetoedIndependently(): void
    {
        $granter = $this->resolverReturning([
            ConsentDecisionInterface::SIGNAL_STORAGE => true,
            ConsentDecisionInterface::SIGNAL_MEASUREMENT => true,
        ]);
        $partialDenier = $this->resolverReturning([
            ConsentDecisionInterface::SIGNAL_STORAGE => false,
        ], ['storage_only_veto']);

        $composite = new CompositeConsentResolver([$granter, $partialDenier], $this->logger);
        $decision = $composite->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertTrue($decision->isMeasurementGranted());
    }

    /**
     * Denial reasons from multiple resolvers are aggregated and deduplicated.
     */
    public function testDenialReasonsAreAggregatedAndDeduplicated(): void
    {
        $denierA = $this->resolverReturning([
            ConsentDecisionInterface::SIGNAL_STORAGE => false,
        ], ['reason_a']);
        $denierB = $this->resolverReturning([
            ConsentDecisionInterface::SIGNAL_MEASUREMENT => false,
        ], ['reason_a', 'reason_b']);

        $composite = new CompositeConsentResolver([$denierA, $denierB], $this->logger);
        $decision = $composite->resolve(self::STORE_ID);

        $this->assertSame(['reason_a', 'reason_b'], array_values($decision->getDenialReasons()));
    }

    /**
     * A resolver that throws must not take down consent resolution for the
     * rest of the composite: the exception is caught, logged as a warning,
     * and the remaining resolvers still run.
     */
    public function testThrowingResolverIsCaughtLoggedAndSkipped(): void
    {
        $throwing = $this->createMock(ConsentResolverInterface::class);
        $throwing->method('resolve')->willThrowException(new \RuntimeException('boom'));

        $granter = $this->resolverReturning([
            ConsentDecisionInterface::SIGNAL_STORAGE => true,
            ConsentDecisionInterface::SIGNAL_MEASUREMENT => true,
        ]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('consent resolver failed'),
                $this->callback(static function (array $context): bool {
                    return isset($context['resolver'], $context['exception'])
                        && $context['exception'] instanceof \Throwable;
                })
            );

        $composite = new CompositeConsentResolver([$throwing, $granter], $this->logger);
        $decision = $composite->resolve(self::STORE_ID);

        $this->assertTrue($decision->isStorageGranted());
        $this->assertTrue($decision->isMeasurementGranted());
    }

    /**
     * A signal nobody ever expresses an opinion on stays false (fail
     * closed) even when other signals are granted.
     */
    public function testUngrantedSignalDefaultsFalseWhileOtherIsGranted(): void
    {
        $composite = new CompositeConsentResolver(
            [$this->resolverReturning([ConsentDecisionInterface::SIGNAL_STORAGE => true])],
            $this->logger
        );

        $decision = $composite->resolve(self::STORE_ID);

        $this->assertTrue($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
    }

    /**
     * The store id passed to CompositeConsentResolver::resolve() must be
     * forwarded verbatim to every wrapped resolver.
     */
    public function testStoreIdIsForwardedToEachResolver(): void
    {
        $storeId = 42;
        $resolver = $this->createMock(ConsentResolverInterface::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with($storeId)
            ->willReturn(new ConsentDecision([]));

        $composite = new CompositeConsentResolver([$resolver], $this->logger);
        $composite->resolve($storeId);
    }
}
