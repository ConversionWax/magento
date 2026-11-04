<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * ConversionWax collector environments. The merchant picks one; the host is fixed in code.
 */
class Environment implements OptionSourceInterface
{
    public const PRODUCTION = 'production';
    public const STAGING = 'staging';

    /**
     * Collector base URL for each environment. Nothing else is ever contacted.
     */
    public const HOSTS = [
        self::PRODUCTION => 'https://collect.conversionwax.com',
        self::STAGING => 'https://dev.collect.conversionwax.com',
    ];

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::PRODUCTION, 'label' => __('Production')],
            ['value' => self::STAGING, 'label' => __('Staging')],
        ];
    }
}
