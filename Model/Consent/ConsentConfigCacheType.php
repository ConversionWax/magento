<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Consent;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

class ConsentConfigCacheType extends TagScope
{
    public const TYPE_IDENTIFIER = 'cwax_banner_consent_config';
    public const CACHE_TAG = 'CWAX_BANNER_CONSENT_CONFIG';

    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
