<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\PhpEnvironment\Request as PhpRequest;
use Magento\Store\Model\StoreManagerInterface;

/**
 * VisitorContext keeps the entry as a path plus attribution parameters. The collector needs an
 * absolute URL to classify it: a path alone fails new URL() and the session is labeled direct.
 */
class StoreUrl
{
    private const MAX_LENGTH = 2048;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request
    ) {
    }

    /** Path of the current store's base URL without a trailing slash: "/ocmlabs", or "" at the root. */
    public function basePath(): string
    {
        $path = parse_url((string) $this->storeManager->getStore()->getBaseUrl(), PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') : '';
    }

    /** Path of the Referer (no query string) when it is on the store host, else null. */
    public function samePageReferer(): ?string
    {
        $referer = $this->request instanceof PhpRequest ? $this->request->getServer('HTTP_REFERER') : null;
        if (!is_string($referer) || $referer === '') {
            return null;
        }
        $parts = parse_url(substr($referer, 0, self::MAX_LENGTH));
        $base = parse_url((string) $this->storeManager->getStore()->getBaseUrl());
        if ($parts === false || $base === false || !isset($parts['host'], $base['host'])
            || strtolower($parts['host']) !== strtolower($base['host'])
        ) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');

        return $path === '' ? '/' : $path;
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
