<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Magento\Store\Model\StoreManagerInterface;

/**
 * VisitorContext keeps the entry as a path plus attribution parameters. The collector needs an
 * absolute URL to classify it: a path alone fails new URL() and the session is labeled direct.
 */
class StoreUrl
{
    private const MAX_LENGTH = 2048;

    public function __construct(private readonly StoreManagerInterface $storeManager)
    {
    }

    public function absolute(int $storeId, string $url): string
    {
        if (!str_starts_with($url, '/')) {
            return $url;
        }

        $base = parse_url((string) $this->storeManager->getStore($storeId)->getBaseUrl());
        if ($base === false || !isset($base['scheme'], $base['host'])) {
            return $url;
        }
        $port = isset($base['port']) ? ':' . $base['port'] : '';

        return substr($base['scheme'] . '://' . $base['host'] . $port . $url, 0, self::MAX_LENGTH);
    }
}
