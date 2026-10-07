<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\PriceHistoryGraphQl\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the lowest captured price per product over a look-back window from the
 * magenx_price_history snapshot table.
 *
 * Built for the batch resolver: the whole grid's product ids are looked up with
 * ONE indexed `MIN(price) ... GROUP BY product_id` query, and results are
 * memoized per (website, group, cutoff) for the request. So a 24-card grid pays
 * a single query, not one per card — unlike a per-product EAV/price fan-out.
 *
 * Every read is bounded to the ids actually being rendered: the history table is
 * unbounded (products x groups x retained days), so no part of it is ever
 * preloaded wholesale. Misses are cached alongside hits, so a page that resolves
 * several product branches (grid + related + upsell) never re-queries the same
 * id twice.
 */
class PriceHistoryProvider implements ResetAfterRequestInterface
{
    /** @var array<string, array<int, float|null>> "websiteId:groupId:cutoff" => productId => lowest|null */
    private array $cache = [];

    /**
     * @param ResourceConnection $resource
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->cache = [];
    }

    /**
     * The lowest captured price per product over the window [cutoff, today].
     *
     * @param int $websiteId
     * @param int $groupId
     * @param string $cutoff ISO date (Y-m-d); rows on/after this day are counted.
     * @param int[] $productIds
     * @return array<int, float> productId => lowest price (only ids that have history)
     */
    public function getLowest(int $websiteId, int $groupId, string $cutoff, array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (!$productIds) {
            return [];
        }

        $key = $websiteId . ':' . $groupId . ':' . $cutoff;
        $this->cache[$key] ??= [];

        $missing = array_values(array_diff($productIds, array_keys($this->cache[$key])));
        if ($missing) {
            // Negative-cache up front; the query below fills in the hits. Without
            // this an id with no history would fall into $missing on every call
            // and re-issue the same query for the rest of the request.
            foreach ($missing as $productId) {
                $this->cache[$key][$productId] = null;
            }

            try {
                $connection = $this->resource->getConnection();
                $select = $connection->select()
                    ->from(
                        ['h' => $this->resource->getTableName('magenx_price_history')],
                        ['product_id' => 'h.product_id', 'lowest' => 'MIN(h.price)']
                    )
                    ->where('h.website_id = ?', $websiteId)
                    ->where('h.customer_group_id = ?', $groupId)
                    ->where('h.captured_on >= ?', $cutoff)
                    // Ignore non-positive snapshots so a legacy €0.00 row (e.g.
                    // configurable parents captured before the min_price fix, or a
                    // free product) can't drag MIN() down to €0.00. A product with
                    // only such rows resolves to no history (null) until real
                    // prices accumulate — the correct fail-soft.
                    ->where('h.price > ?', 0)
                    ->where('h.product_id IN (?)', $missing)
                    ->group('h.product_id');

                foreach ($connection->fetchAll($select) as $row) {
                    $this->cache[$key][(int) $row['product_id']] = (float) $row['lowest'];
                }
            } catch (\Exception $e) {
                // Table absent / cron never ran — return no history rather than
                // letting a product query error.
                $this->logDegraded($e);
            }
        }

        return array_filter(
            array_intersect_key($this->cache[$key], array_flip($productIds)),
            static fn (?float $lowest): bool => $lowest !== null
        );
    }

    /**
     * Record why the lookup degraded to "no history". Silence here used to hide a
     * missing schema upgrade behind a permanently empty prior-price disclosure.
     *
     * @param \Exception $e
     * @return void
     */
    private function logDegraded(\Exception $e): void
    {
        $this->logger->warning(
            'Magenx_PriceHistoryGraphQl: price history unavailable, resolving to no history. ' . $e->getMessage()
        );
    }
}
