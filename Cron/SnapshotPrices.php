<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\PriceHistoryGraphQl\Cron;

use Magenx\PriceHistoryGraphQl\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily snapshot of every product's current final price into
 * magenx_price_history, then a prune of rows beyond the retention window.
 *
 * The snapshot copies the whole native price index (catalog_product_index_price)
 * in one INSERT...SELECT, covering every (website, customer group) so the prior
 * price matches the price each customer group is shown.
 *
 * For a SIMPLE product `final_price` is the authoritative single-unit selling
 * price after special price + catalog rules, and `min_price` would wrongly fold
 * in tier/quantity-break prices — so simples capture `final_price`. But a
 * COMPOSITE product (configurable / bundle / grouped) has `final_price = 0` in
 * the index: its parent row carries no own price, and the figure the storefront
 * shows ("from €X") comes from `min_price` (the cheapest child's final price).
 * Snapshotting `final_price` for those parents captured €0.00, so the Omnibus
 * "lowest price in the last 30 days" rendered as €0.00. We therefore capture
 * `IF(final_price > 0, final_price, min_price)`: `final_price` for simples,
 * `min_price` (the displayed "from" price) for composite parents. Children are
 * simple products with their own index rows, so a selected variant still gets
 * its own precise history.
 *
 * Re-runs the same day keep the LOWEST value seen that day (LEAST), so an
 * intraday markdown is not lost. Schedule it after the product price reindex so
 * it captures the day's real prices.
 */
class SnapshotPrices
{
    /**
     * Extra days kept beyond the configured window so the MIN() at the edge of
     * the window never touches partially-pruned data.
     */
    private const RETENTION_BUFFER_DAYS = 10;

    /** Rows deleted per prune iteration, to avoid one long-held lock. */
    private const PRUNE_BATCH = 50000;

    /**
     * @param ResourceConnection $resource
     * @param Config $config
     * @param TimezoneInterface $timezone
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Snapshot today's prices, then prune old rows.
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $today = $this->timezone->date()->format('Y-m-d');
            $inserted = $this->snapshot($today);
            $pruned = $this->prune();
            $this->logger->info(sprintf(
                'Magenx_PriceHistoryGraphQl: snapshotted prices for %s (%d rows affected), pruned %d old rows.',
                $today,
                $inserted,
                $pruned
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Magenx_PriceHistoryGraphQl: price snapshot failed. ' . $e->getMessage());
        }
    }

    /**
     * Upsert the day's lowest final price per (product, website, group).
     *
     * @param string $today Y-m-d
     * @return int affected rows
     */
    private function snapshot(string $today): int
    {
        $connection = $this->resource->getConnection();
        $historyTable = $this->resource->getTableName('magenx_price_history');
        $indexTable = $this->resource->getTableName('catalog_product_index_price');

        // ON DUPLICATE KEY UPDATE price = LEAST(existing, new): a same-day re-run
        // (or a run after an intraday markdown) keeps the lowest price seen.
        //
        // IF(final_price > 0, final_price, min_price): capture the simple
        // product's own final price, or the composite parent's displayed "from"
        // price (min_price) when it has no own final_price (see class docblock).
        // The WHERE guarantees the captured value is a real positive price, so a
        // configurable parent (final_price = 0) is stored with min_price and a
        // genuinely free product (both 0) is skipped rather than polluting the
        // MIN() with a 0.
        $sql = sprintf(
            'INSERT INTO %s (product_id, website_id, customer_group_id, price, captured_on) '
            . 'SELECT entity_id, website_id, customer_group_id, '
            . 'IF(final_price > 0, final_price, min_price), %s '
            . 'FROM %s WHERE (final_price > 0 OR min_price > 0) '
            . 'ON DUPLICATE KEY UPDATE price = LEAST(%s.price, VALUES(price))',
            $connection->quoteIdentifier($historyTable),
            $connection->quote($today),
            $connection->quoteIdentifier($indexTable),
            $connection->quoteIdentifier($historyTable)
        );

        return (int) $connection->query($sql)->rowCount();
    }

    /**
     * Delete rows older than the retention window (batched).
     *
     * @return int total rows deleted
     */
    private function prune(): int
    {
        $connection = $this->resource->getConnection();
        $historyTable = $this->resource->getTableName('magenx_price_history');

        $retentionDays = $this->config->getWindowDays() + self::RETENTION_BUFFER_DAYS;
        $cutoff = $this->timezone->date()->modify('-' . $retentionDays . ' days')->format('Y-m-d');

        $sql = sprintf(
            'DELETE FROM %s WHERE captured_on < %s LIMIT %d',
            $connection->quoteIdentifier($historyTable),
            $connection->quote($cutoff),
            self::PRUNE_BATCH
        );

        $total = 0;
        do {
            $deleted = (int) $connection->query($sql)->rowCount();
            $total += $deleted;
        } while ($deleted >= self::PRUNE_BATCH);

        return $total;
    }
}
