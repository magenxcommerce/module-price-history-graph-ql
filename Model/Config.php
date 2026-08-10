<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\PriceHistoryGraphQl\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed access to the magenx_price_history/* store configuration.
 */
class Config
{
    private const XML_PATH_ENABLED = 'magenx_price_history/general/enabled';
    private const XML_PATH_WINDOW_DAYS = 'magenx_price_history/general/window_days';

    private const DEFAULT_WINDOW_DAYS = 30;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether prior-price tracking is enabled.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The look-back window in days (the prior price is the lowest over this
     * many days). Falls back to 30 — the EU Omnibus minimum — for any missing
     * or non-positive value.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getWindowDays(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_WINDOW_DAYS, ScopeInterface::SCOPE_STORE, $storeId);

        return $value > 0 ? $value : self::DEFAULT_WINDOW_DAYS;
    }
}
