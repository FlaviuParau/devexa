<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Model;

use Devexa\AiRecommendation\Api\RecommendationEngineInterface;
use Devexa\Core\Model\PlatformConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class RecommendationEngine implements RecommendationEngineInterface
{
    private const CONFIG_PATH_PREFIX = 'devexa_ai_recommendation/';

    /**
     * Map recommendation type to platform API algorithm parameter
     */
    private const TYPE_ALGORITHM_MAP = [
        'frequently_bought' => 'frequently_bought',
        'upsell'            => 'hybrid',
        'crosssell'         => 'collaborative',
        'similar'           => 'similarity',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly StoreManagerInterface $storeManager,
        private readonly PlatformConfig $platformConfig,
        private readonly \Devexa\Core\Model\LicenseValidator $licenseValidator,
        private readonly RecCache $recCache,
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
    }

    public function getRecommendations(
        string $context,
        ?int $productId = null,
        ?string $visitorId = null,
        int $limit = 8,
        string $type = 'upsell'
    ): array {
        if (!$this->isEnabled()) {
            return [];
        }

        $storeId = (int) $this->storeManager->getStore()->getId();

        // 1. Check DB cache first
        if ($productId) {
            $cached = $this->recCache->get($productId, $type, $storeId);
            if ($cached !== null) {
                return array_slice($cached, 0, $limit);
            }
        }

        // 2. Call the platform API
        $productIds = $this->callApi($context, $productId, $visitorId, $limit, $type);

        // 3. If API returned results, cache them
        if (!empty($productIds) && $productId) {
            try {
                $this->recCache->set($productId, $type, $storeId, $productIds);
            } catch (\Exception $e) {
                $this->logger->warning('AiRecommendation cache write failed: ' . $e->getMessage());
            }
        }

        // 4. If API failed, try stale cache (better than nothing)
        if (empty($productIds) && $productId) {
            $stale = $this->recCache->get($productId, $type, $storeId, true);
            if ($stale !== null) {
                return array_slice($stale, 0, $limit);
            }
        }

        // 5. Fallback to bestsellers if everything else failed
        if (empty($productIds)) {
            $productIds = $this->getBestsellers($limit);
        }

        return $productIds;
    }

    /**
     * Call the platform API for recommendations (extracted from original getRecommendations).
     *
     * @return int[]
     */
    public function callApi(
        string $context,
        ?int $productId,
        ?string $visitorId,
        int $limit,
        string $type = 'upsell'
    ): array {
        try {
            $endpoint = $this->platformConfig->getServiceEndpoint('v1/recommendations/recommend');
            $apiKey = $this->platformConfig->getApiKey();
            $algorithm = $this->getAlgorithmForType($type);

            $payload = [
                'context' => $context,
                'product_id' => $productId,
                'visitor_id' => $visitorId,
                'algorithm' => $algorithm,
                'limit' => $limit,
            ];

            $skipSsl = $this->platformConfig->shouldSkipSslVerify();
            $curl = $this->curlFactory->create();
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, !$skipSsl);
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, $skipSsl ? 0 : 2);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Authorization', 'Bearer ' . $apiKey);
            $curl->addHeader('X-Store-Domain', $this->getStoreDomain());
            $curl->setTimeout(3);
            $curl->post($endpoint, $this->json->serialize($payload));

            if ($curl->getStatus() >= 200 && $curl->getStatus() < 300) {
                $response = $this->json->unserialize($curl->getBody());
                return $response['product_ids'] ?? [];
            }
        } catch (\Exception $e) {
            $this->logger->error('AiRecommendation engine error: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Get the algorithm string for a given recommendation type.
     * Platform setting overrides local mapping.
     */
    private function getAlgorithmForType(string $type): string
    {
        // Platform settings take priority (single source of truth)
        $platformAlgo = $this->licenseValidator->getSetting('recommendations.algorithm');
        if ($platformAlgo) {
            return $platformAlgo;
        }

        // Use the type-to-algorithm mapping
        if (isset(self::TYPE_ALGORITHM_MAP[$type])) {
            return self::TYPE_ALGORITHM_MAP[$type];
        }

        // Fallback to Magento config
        return (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'widgets/algorithm',
            ScopeInterface::SCOPE_STORE
        ) ?: 'frequently_bought';
    }

    /**
     * Fallback: get bestselling product IDs from Magento sales data
     *
     * @return int[]
     */
    private function getBestsellers(int $limit): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('sales_bestsellers_aggregated_daily');

            // Check if table exists (some installs may not have report data)
            if (!$connection->isTableExists($table)) {
                return $this->getBestsellersFromOrderItems($limit);
            }

            $select = $connection->select()
                ->from($table, ['product_id'])
                ->where('store_id = ?', (int) $this->storeManager->getStore()->getId())
                ->order('qty_ordered DESC')
                ->group('product_id')
                ->limit($limit);

            $result = $connection->fetchCol($select);

            if (!empty($result)) {
                return array_map('intval', $result);
            }

            // Fallback to raw order items if aggregated table is empty
            return $this->getBestsellersFromOrderItems($limit);
        } catch (\Exception $e) {
            $this->logger->warning('AiRecommendation bestsellers fallback error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fallback bestsellers from sales_order_item table directly
     *
     * @return int[]
     */
    private function getBestsellersFromOrderItems(int $limit): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('sales_order_item');

            if (!$connection->isTableExists($table)) {
                return [];
            }

            $select = $connection->select()
                ->from($table, ['product_id', 'total_qty' => new \Zend_Db_Expr('SUM(qty_ordered)')])
                ->where('product_type = ?', 'simple')
                ->group('product_id')
                ->order('total_qty DESC')
                ->limit($limit);

            $result = $connection->fetchCol($select);
            return array_map('intval', $result);
        } catch (\Exception $e) {
            $this->logger->warning('AiRecommendation order items fallback error: ' . $e->getMessage());
            return [];
        }
    }

    private function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_PREFIX . 'general/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    private function getStoreDomain(): string
    {
        try {
            $baseUrl = $this->storeManager->getStore()->getBaseUrl();
            return (string) parse_url($baseUrl, PHP_URL_HOST);
        } catch (\Exception $e) {
            return '';
        }
    }
}
