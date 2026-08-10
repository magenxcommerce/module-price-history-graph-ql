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
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

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
     */
    public function __construct(
        private readonly Config $config,
        private readonly PriceHistoryProvider $provider,
        private readonly CustomerContext $customerContext,
        private readonly TimezoneInterface $timezone
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

        $store = $context->getExtensionAttributes()->getStore();
        $storeId = (int) $store->getId();
        $enabled = $this->config->isEnabled($storeId);
        $windowDays = $this->config->getWindowDays($storeId);
        $cutoff = $this->timezone->date()->modify('-' . $windowDays . ' days')->format('Y-m-d');
        [$currencyCode, $currencyRate] = $this->currency($store);

        // Gather every product id in this batch (one grid / query branch).
        $ids = [];
        foreach ($requests as $request) {
            $product = $this->productOf($request);
            if ($product !== null) {
                $ids[] = (int) $product->getId();
            }
        }

        $lowest = ($enabled && $ids)
            ? $this->provider->getLowest(
                (int) $store->getWebsiteId(),
                $this->customerContext->getGroupId($context),
                $cutoff,
                $ids
            )
            : [];

        foreach ($requests as $request) {
            $product = $this->productOf($request);
            $productId = $product !== null ? (int) $product->getId() : 0;

            if (!$enabled || !isset($lowest[$productId])) {
                // Disabled or no captured history for this product => null.
                $response->addResponse($request, null);
                continue;
            }

            $response->addResponse($request, [
                'lowest_price' => round($lowest[$productId] * $currencyRate, 2),
                'currency' => $currencyCode,
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

    /**
     * The store's display currency code and the base->display rate.
     *
     * Snapshots are stored in the website base currency; multiplying by the rate
     * makes the prior price match the figure the shopper sees. Falls back to
     * (base code, 1.0) if the currency framework is unavailable.
     *
     * @param \Magento\Store\Api\Data\StoreInterface $store
     * @return array{0: string|null, 1: float}
     */
    private function currency($store): array
    {
        try {
            $rate = (float) $store->getCurrentCurrencyRate();
            return [$store->getCurrentCurrencyCode(), $rate > 0 ? $rate : 1.0];
        } catch (\Exception $e) {
            try {
                return [$store->getBaseCurrencyCode(), 1.0];
            } catch (\Exception $inner) {
                return [null, 1.0];
            }
        }
    }
}
