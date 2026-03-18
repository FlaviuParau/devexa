<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Model;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Local DB cache for AI recommendations.
 * Stores recommended product IDs per product/type/store to avoid API calls on every page load.
 */
class RecCache
{
    private const TABLE_NAME = 'devexa_rec_cache';

    /**
     * Cache TTL in seconds (24 hours)
     */
    private const CACHE_TTL = 86400;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get cached recommendation product IDs.
     *
     * @param int $productId Source product ID
     * @param string $type Recommendation type (frequently_bought, upsell, crosssell, similar)
     * @param int $storeId Store ID
     * @param bool $includeStale If true, returns cache even if expired
     * @return int[]|null Cached product IDs, or null if not cached/expired
     */
    public function get(int $productId, string $type, int $storeId, bool $includeStale = false): ?array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::TABLE_NAME);

            if (!$connection->isTableExists($table)) {
                return null;
            }

            $select = $connection->select()
                ->from($table, ['recommended_ids', 'updated_at'])
                ->where('product_id = ?', $productId)
                ->where('type = ?', $type)
                ->where('store_id = ?', $storeId);

            $row = $connection->fetchRow($select);

            if (!$row || empty($row['recommended_ids'])) {
                return null;
            }

            // Check freshness unless stale is acceptable
            if (!$includeStale) {
                $updatedAt = strtotime($row['updated_at']);
                if ($updatedAt && (time() - $updatedAt) > self::CACHE_TTL) {
                    return null;
                }
            }

            $ids = array_filter(array_map('intval', explode(',', $row['recommended_ids'])));
            return !empty($ids) ? $ids : null;
        } catch (\Exception $e) {
            $this->logger->warning('AiRecommendation RecCache::get error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Store recommendation cache using INSERT ON DUPLICATE KEY UPDATE.
     *
     * @param int $productId Source product ID
     * @param string $type Recommendation type
     * @param int $storeId Store ID
     * @param int[] $productIds Recommended product IDs
     */
    public function set(int $productId, string $type, int $storeId, array $productIds): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::TABLE_NAME);

            if (!$connection->isTableExists($table)) {
                return;
            }

            $recommendedIds = implode(',', array_map('intval', $productIds));

            $connection->insertOnDuplicate(
                $table,
                [
                    'product_id' => $productId,
                    'store_id' => $storeId,
                    'type' => $type,
                    'recommended_ids' => $recommendedIds,
                    'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                ],
                ['recommended_ids', 'updated_at']
            );
        } catch (\Exception $e) {
            $this->logger->error('AiRecommendation RecCache::set error: ' . $e->getMessage());
        }
    }

    /**
     * Invalidate (clear) cached recommendations.
     *
     * @param int|null $productId If null, clears ALL cache entries
     */
    public function invalidate(?int $productId = null): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::TABLE_NAME);

            if (!$connection->isTableExists($table)) {
                return;
            }

            if ($productId !== null) {
                $connection->delete($table, ['product_id = ?' => $productId]);
            } else {
                $connection->truncateTable($table);
            }
        } catch (\Exception $e) {
            $this->logger->error('AiRecommendation RecCache::invalidate error: ' . $e->getMessage());
        }
    }
}
