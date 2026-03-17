<?php

declare(strict_types=1);

namespace Devexa\FormProtection\Model;

use Devexa\Core\Model\PlatformConfig;
use Devexa\FormProtection\Api\RiskScorerInterface;
use Devexa\FormProtection\Api\RuleEngineInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class RiskScorer implements RiskScorerInterface
{
    private const CONFIG_PATH_PREFIX = 'devexa_form_protection/';

    public function __construct(
        private readonly RuleEngineInterface $ruleEngine,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly PlatformConfig $platformConfig
    ) {
    }

    public function evaluate(array $behaviorData, string $ip, ?string $country = null): array
    {
        // Apply local rules first
        $ruleResult = $this->ruleEngine->applyRules($behaviorData, $ip, $country);
        $score = $ruleResult['score'];
        $reasons = $ruleResult['reasons'];

        // If API endpoint is configured, also validate remotely
        $mode = $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'general/mode',
            ScopeInterface::SCOPE_STORE
        );

        if ($mode === 'api') {
            $apiResult = $this->validateWithApi($behaviorData, $ip, $country, $score);
            if ($apiResult !== null) {
                $score = $apiResult['score'] ?? $score;
                $reasons = array_merge($reasons, $apiResult['reasons'] ?? []);
            }
        }

        // Determine action
        $blockThreshold = (int) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'rules/risk_score_block_threshold',
            ScopeInterface::SCOPE_STORE
        ) ?: 80;

        $challengeThreshold = (int) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'rules/risk_score_challenge_threshold',
            ScopeInterface::SCOPE_STORE
        ) ?: 50;

        if ($score >= $blockThreshold) {
            $action = self::ACTION_BLOCK;
        } elseif ($score >= $challengeThreshold) {
            $action = self::ACTION_CHALLENGE;
        } else {
            $action = self::ACTION_ALLOW;
        }

        return [
            'score' => $score,
            'action' => $action,
            'reasons' => array_unique($reasons),
        ];
    }

    private function validateWithApi(array $behaviorData, string $ip, ?string $country, int $localScore): ?array
    {
        try {
            $endpoint = $this->platformConfig->getServiceEndpoint('v1/form-protection/validate');
            $apiKey = $this->platformConfig->getApiKey();

            if (empty($apiKey)) {
                return null;
            }

            $skipSsl = $this->platformConfig->shouldSkipSslVerify();
            $storeDomain = '';
            try { $storeDomain = (string) parse_url($this->storeManager->getStore()->getBaseUrl(), PHP_URL_HOST); } catch (\Exception $e) {}

            $curl = $this->curlFactory->create();
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, !$skipSsl);
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, $skipSsl ? 0 : 2);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Authorization', 'Bearer ' . $apiKey);
            $curl->addHeader('X-Store-Domain', $storeDomain);
            $curl->setTimeout(3);
            $curl->post($endpoint, $this->json->serialize([
                'ip' => $ip,
                'country' => $country,
                'behavior' => $behaviorData,
                'local_score' => $localScore,
            ]));

            if ($curl->getStatus() >= 200 && $curl->getStatus() < 300) {
                return $this->json->unserialize($curl->getBody());
            }
        } catch (\Exception $e) {
            $this->logger->error('FormProtection API error: ' . $e->getMessage());
        }

        return null;
    }
}
