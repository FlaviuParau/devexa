<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Cron;

use Devexa\AiRecommendation\Model\RecCache;
use Devexa\AiRecommendation\Model\RecommendationEngine;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cron job to pre-fetch recommendations for popular products.
 * Runs daily at 4 AM to ensure popular products always have instant recommendations.
 */
class PreFetch
{
    private const CONFIG_PATH_PREFIX = 'devexa_ai_recommendation/';

    private const RECOMMENDATION_TYPES = [
        'frequently_bought',
        'upsell',
        'crosssell',
        'similar',
    ];

    public function __construct(
        private readonly RecommendationEngine $engine,
        private readonly RecCache $recCache,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Execute the pre-fetch cron job.
     *
     * @param int $limit Maximum number of products to pre-fetch (default 200)
     * @param string|null $type If set, only pre-fetch this recommendation type
     */
    public function execute(int $limit = 200, ?string $type = null): void
    {
        if (!$this->scopeConfig->isSetFlag(self::CONFIG_PATH_PREFIX . 'general/enabled', ScopeInterface::SCOPE_STORE)) {
            return;
        }

        $types = $type ? [$type] : self::RECOMMENDATION_TYPES;
        $productIds = $this->getTopViewedProductIds($limit);

        if (empty($productIds)) {
            $this->logger->info('AiRecommendation PreFetch: no popular products found, skipping.');
            return;
        }

        $stores = $this->storeManager->getStores();
        $totalFetched = 0;

        foreach ($stores as $store) {
            $storeId = (int) $store->getId();

            foreach ($productIds as $productId) {
                foreach ($types as $recType) {
                    try {
                        $result = $this->engine->callApi('product', $productId, null, 20, $recType);

                        if (!empty($result)) {
                            $this->recCache->set($productId, $recType, $storeId, $result);
                            $totalFetched++;
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning(sprintf(
                            'AiRecommendation PreFetch failed for product %d, type %s, store %d: %s',
                            $productId,
                            $recType,
                            $storeId,
                            $e->getMessage()
                        ));
                    }
                }
            }
        }

        $this->logger->info(sprintf(
            'AiRecommendation PreFetch completed: %d products, %d types, %d cache entries written.',
            count($productIds),
            count($types),
            $totalFetched
        ));
    }

    /**
     * Get top viewed product IDs from Magento's report data.
     *
     * @return int[]
     */
    private function getTopViewedProductIds(int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();

        // Try aggregated report table first
        $table = $this->resourceConnection->getTableName('report_viewed_product_aggregated_daily');
        if ($connection->isTableExists($table)) {
            $select = $connection->select()
                ->from($table, ['product_id', 'total_views' => new \Zend_Db_Expr('SUM(views_num)')])
                ->group('product_id')
                ->order('total_views DESC')
                ->limit($limit);

            $result = $connection->fetchCol($select);
            if (!empty($result)) {
                return array_map('intval', $result);
            }
        }

        // Fallback: get recently updated/created products from catalog
        $catalogTable = $this->resourceConnection->getTableName('catalog_product_entity');
        if ($connection->isTableExists($catalogTable)) {
            $select = $connection->select()
                ->from($catalogTable, ['entity_id'])
                ->order('updated_at DESC')
                ->limit($limit);

            return array_map('intval', $connection->fetchCol($select));
        }

        return [];
    }
}
