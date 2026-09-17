<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Consent;

use Cwax\Banner\Api\ConsentResolverInterface;
use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Psr\Log\LoggerInterface;

class CompositeConsentResolver implements ConsentResolverInterface
{
    private const SIGNALS = [
        ConsentDecisionInterface::SIGNAL_STORAGE,
        ConsentDecisionInterface::SIGNAL_MEASUREMENT,
    ];

    /** @param ConsentResolverInterface[] $resolvers injected via di.xml array argument "resolvers" */
    public function __construct(
        private readonly array $resolvers,
        private readonly LoggerInterface $logger
    ) {
    }

    public function resolve(int $storeId): ConsentDecisionInterface
    {
        $granted = array_fill_keys(self::SIGNALS, false);
        $denied = array_fill_keys(self::SIGNALS, false);
        $reasons = [];

        foreach ($this->resolvers as $resolver) {
            try {
                $decision = $resolver->resolve($storeId);
            } catch (\Throwable $exception) {
                // One misbehaving resolver (eg a third-party addition) must
                // never take down consent resolution for every visitor.
                $this->logger->warning('ConversionWax Last Mile consent resolver failed.', [
                    'resolver' => $resolver::class,
                    'exception' => $exception,
                ]);
                continue;
            }

            foreach (self::SIGNALS as $signal) {
                $value = $decision->getSignal($signal);
                if ($value === true) {
                    $granted[$signal] = true;
                } elseif ($value === false) {
                    $denied[$signal] = true;
                }
            }
            $reasons = [...$reasons, ...$decision->getDenialReasons()];
        }

        $final = [];
        foreach (self::SIGNALS as $signal) {
            $final[$signal] = $granted[$signal] && !$denied[$signal];
        }

        return new ConsentDecision($final, array_values(array_unique($reasons)));
    }
}
