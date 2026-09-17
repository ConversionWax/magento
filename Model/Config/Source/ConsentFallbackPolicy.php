<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class ConsentFallbackPolicy implements ArrayInterface
{
    public const DENY = 'deny';
    public const GRANT = 'grant';
    public const MAGENTO_COOKIE = 'magento_cookie';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::DENY, 'label' => __('Deny (fail closed - recommended)')],
            ['value' => self::GRANT, 'label' => __('Grant (fail open - not recommended)')],
            ['value' => self::MAGENTO_COOKIE, 'label' => __('Defer to Magento Cookie Restriction Mode')],
        ];
    }
}
