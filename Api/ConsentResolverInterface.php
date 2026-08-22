<?php

declare(strict_types=1);

namespace Cwax\Banner\Api;

use Cwax\Banner\Api\Data\ConsentDecisionInterface;

interface ConsentResolverInterface
{
    /**
     * Must never throw. An implementation with no opinion on a signal must
     * leave it out of the decision (getSignal() returns null for it) rather
     * than guess true or false.
     */
    public function resolve(int $storeId): ConsentDecisionInterface;
}
