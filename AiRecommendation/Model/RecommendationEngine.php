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

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly StoreManagerInterface $storeManager,
        private readonly PlatformConfig $platformConfig,
        private readonly \Devexa\Core\Model\LicenseValidator $licenseValidator
    ) {
    }

    public function getRecommendations(
        string $context,
        ?int $productId = null,
        ?string $visitorId = null,
        int $limit = 8
    ): array {
        if (!$this->isEnabled()) {
            return [];
        }

        try {
            $endpoint = $this->platformConfig->getServiceEndpoint('v1/recommendations/recommend');
            $apiKey = $this->platformConfig->getApiKey();
            $algorithm = $this->getAlgorithm();

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

    private function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_PREFIX . 'general/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    private function getAlgorithm(): string
    {
        // Platform settings take priority (single source of truth)
        $platformAlgo = $this->licenseValidator->getSetting('recommendations.algorithm');
        if ($platformAlgo) {
            return $platformAlgo;
        }

        // Fallback to Magento config
        return (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'widgets/algorithm',
            ScopeInterface::SCOPE_STORE
        ) ?: 'frequently_bought';
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
