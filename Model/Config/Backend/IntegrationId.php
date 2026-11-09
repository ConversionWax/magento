<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * The Last Mile Integration ID: empty, or the install's UUID. An empty value is allowed (the store then
 * loads no lm.js); anything else that is not a UUID is rejected at save time.
 */
class IntegrationId extends Value
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Trims the pasted value and rejects anything that is not a UUID.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        if ($value !== '' && preg_match(self::UUID_PATTERN, $value) !== 1) {
            throw new LocalizedException(__(
                'The Integration ID must be the 36-character ID from the Embed code tab of the install in '
                . 'ConversionWax, for example 0f8fad5b-d9cb-469f-a165-70867728950e.'
            ));
        }
        $this->setValue($value);

        return parent::beforeSave();
    }
}
