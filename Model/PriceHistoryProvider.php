<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\PriceHistoryGraphQl\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads the lowest captured price per product over a look-back window from the
 * magenx_price_history snapshot table.
 *
 * Built for the batch resolver: the whole grid's product ids are looked up with
 * ONE indexed `MIN(price) ... GROUP BY product_id` query, and results are
 * memoized per (website, group, cutoff) for the request. So a 24-card grid pays
 * a single query, not one per card — unlike a per-product EAV/price fan-out.
 * The history table is unbounded (products x groups x retained days), so this
 * deliberately does NOT preload the whole scope the way DealProvider does.
 */
class PriceHistoryProvider
{
    /** @var array<string, array<int, float>> "websiteId:groupId:cutoff" => productId => lowest */
    private array $cache = [];

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
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
        $known = $this->cache[$key] ?? [];

        $missing = array_values(array_diff($productIds, array_keys($known)));
        if ($missing) {
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
                    $known[(int) $row['product_id']] = (float) $row['lowest'];
                }
            } catch (\Exception $e) {
                // Table absent / cron never ran — return no history rather than
                // letting a product query error.
            }
            $this->cache[$key] = $known;
        }

        return array_intersect_key($known, array_flip($productIds));
    }
}
