<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\PriceHistoryGraphQl\Model\Resolver\Product;

use Magenx\PriceHistoryGraphQl\Model\Config;
use Magenx\PriceHistoryGraphQl\Model\CustomerContext;
use Magenx\PriceHistoryGraphQl\Model\PriceHistoryProvider;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchRequestItemInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResolverInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Store;

/**
 * Resolves ProductInterface.price_history — the EU Omnibus prior price (the
 * lowest price applied over the configured look-back window).
 *
 * A BATCH resolver on purpose: Magento hands it every product request for a grid
 * branch in a single call, so it collects all product ids and asks
 * {@see PriceHistoryProvider} for their lowest prices with ONE query, then maps
 * the answers back. That is what makes this field safe on listing grids
 * (PRODUCT_CARD_FIELDS) — one query per grid, not one per card. A plain
 * ResolverInterface here would reintroduce the per-product fan-out the storefront
 * carefully avoids, so this MUST stay a BatchResolverInterface (do not also
 * register it as an ordinary resolver).
 *
 * Returns null for a product when the feature is disabled or no history has been
 * captured for it yet, so it never breaks a product query.
 */
class PriceHistory implements BatchResolverInterface
{
    /**
     * @param Config $config
     * @param PriceHistoryProvider $provider
     * @param CustomerContext $customerContext
     * @param TimezoneInterface $timezone
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(
        private readonly Config $config,
        private readonly PriceHistoryProvider $provider,
        private readonly CustomerContext $customerContext,
        private readonly TimezoneInterface $timezone,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * @param ContextInterface $context
     * @param Field $field
     * @param BatchRequestItemInterface[] $requests
     * @return BatchResponse
     */
    public function resolve(ContextInterface $context, Field $field, array $requests): BatchResponse
    {
        $response = new BatchResponse();

        $store = $context->getExtensionAttributes()?->getStore();
        $storeId = $store !== null ? (int) $store->getId() : null;

        // No store on the context, or the feature is off: every product resolves
        // to null rather than failing the product query.
        if ($store === null || !$this->config->isEnabled($storeId)) {
            foreach ($requests as $request) {
                $response->addResponse($request, null);
            }

            return $response;
        }

        $windowDays = $this->config->getWindowDays($storeId);
        // Anchored to the default scope, the same scope the snapshot cron runs
        // in, so `captured_on` and this cutoff mean the same calendar day even
        // when store views carry different timezones.
        $cutoff = $this->timezone->scopeDate(Store::DEFAULT_STORE_ID)
            ->modify('-' . $windowDays . ' days')
            ->format('Y-m-d');

        // Gather every product id in this batch (one grid / query branch),
        // keyed by request so the second pass never re-derives the model.
        $productIds = [];
        foreach ($requests as $key => $request) {
            $product = $this->productOf($request);
            $productIds[$key] = $product !== null ? (int) $product->getId() : 0;
        }

        $ids = array_filter($productIds);
        $lowest = $ids
            ? $this->provider->getLowest(
                (int) $store->getWebsiteId(),
                $this->customerContext->getGroupId($context),
                $cutoff,
                $ids
            )
            : [];

        try {
            $currency = $this->priceCurrency->getCurrency($storeId)->getCurrencyCode();
        } catch (\Exception $e) {
            // Currency framework unavailable: report the stored base-currency
            // figure unconverted rather than failing the whole product query.
            $currency = null;
        }

        foreach ($requests as $key => $request) {
            $productId = $productIds[$key];

            if (!isset($lowest[$productId])) {
                // No captured history for this product => null.
                $response->addResponse($request, null);
                continue;
            }

            $response->addResponse($request, [
                // Snapshots are stored in the website base currency;
                // convertAndRound applies the store's current rate and Magento's
                // standard price precision, so the prior price matches the figure
                // the shopper sees. A null currency means that framework was
                // unavailable above, so the base figure is reported as-is.
                'lowest_price' => $currency !== null
                    ? $this->priceCurrency->convertAndRound($lowest[$productId], $storeId)
                    : round($lowest[$productId], 2),
                'currency' => $currency,
                'days' => $windowDays,
                'since' => $cutoff,
            ]);
        }

        return $response;
    }

    /**
     * The product model carried on a batch request's parent value, or null.
     *
     * @param BatchRequestItemInterface $request
     * @return ProductInterface|null
     */
    private function productOf(BatchRequestItemInterface $request): ?ProductInterface
    {
        $value = $request->getValue();

        return isset($value['model']) && $value['model'] instanceof ProductInterface
            ? $value['model']
            : null;
    }
}
