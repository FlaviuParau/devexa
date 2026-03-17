<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Model;

use Devexa\AiRecommendation\Api\EventTrackerInterface;
use Devexa\Core\Model\PlatformConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class EventTracker implements EventTrackerInterface
{
    private const CONFIG_PATH_PREFIX = 'devexa_ai_recommendation/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly StoreManagerInterface $storeManager,
        private readonly PlatformConfig $platformConfig
    ) {
    }

    public function track(string $eventType, array $data, ?string $visitorId = null): bool
    {
        if (!$this->isEnabled() || !$this->isEventTypeEnabled($eventType)) {
            return false;
        }

        $payload = [
            'event' => $eventType,
            'visitor_id' => $visitorId,
            'data' => $data,
            'timestamp' => time(),
        ];

        return $this->sendToApi('/track', $payload);
    }

    private function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_PREFIX . 'general/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    private function isEventTypeEnabled(string $eventType): bool
    {
        $configMap = [
            self::EVENT_PRODUCT_VIEW => 'tracking/track_product_views',
            self::EVENT_ADD_TO_CART => 'tracking/track_add_to_cart',
            self::EVENT_PURCHASE => 'tracking/track_purchases',
            self::EVENT_SEARCH => 'tracking/track_search',
            self::EVENT_CATEGORY_VIEW => 'tracking/track_category_views',
        ];

        if (!isset($configMap[$eventType])) {
            return true;
        }

        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_PREFIX . $configMap[$eventType],
            ScopeInterface::SCOPE_STORE
        );
    }

    private function sendToApi(string $path, array $payload): bool
    {
        try {
            $endpoint = $this->platformConfig->getServiceEndpoint('v1/recommendations' . $path);
            $apiKey = $this->platformConfig->getApiKey();
            $skipSsl = $this->platformConfig->shouldSkipSslVerify();

            $storeDomain = '';
            try {
                $storeDomain = (string) parse_url($this->storeManager->getStore()->getBaseUrl(), PHP_URL_HOST);
            } catch (\Exception $e) {}

            $curl = $this->curlFactory->create();
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, !$skipSsl);
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, $skipSsl ? 0 : 2);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Authorization', 'Bearer ' . $apiKey);
            $curl->addHeader('X-Store-Domain', $storeDomain);
            $curl->setTimeout(5);
            $curl->post($endpoint, $this->json->serialize($payload));

            return $curl->getStatus() >= 200 && $curl->getStatus() < 300;
        } catch (\Exception $e) {
            $this->logger->error('AiRecommendation tracker error: ' . $e->getMessage());
            return false;
        }
    }

}
