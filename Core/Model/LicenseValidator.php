<?php

declare(strict_types=1);

namespace Devexa\Core\Model;

use Magento\Framework\App\Cache\Type\Config as CacheType;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class LicenseValidator
{
    private const CACHE_KEY_PREFIX = 'devexa_license_';
    private const CONFIG_PREFIX = 'devexa_core/license/';

    private ?array $cachedResult = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly CacheInterface $cache,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly PlatformConfig $platformConfig
    ) {
    }

    /**
     * Check if a specific service is licensed and active.
     * Results are cached for X hours to avoid hitting the API on every request.
     */
    public function isServiceActive(string $service): bool
    {
        $license = $this->validate();
        if (!$license || !$license['valid']) {
            return false;
        }

        $services = $license['services'] ?? [];
        return in_array($service, $services, true);
    }

    /**
     * Get a platform setting value.
     * These come from the tenant settings on the platform (single source of truth).
     * Example: getSetting('recommendations.algorithm') returns 'frequently_bought'
     */
    public function getSetting(string $path, $default = null)
    {
        $license = $this->validate();
        if (!$license || !$license['valid']) {
            return $default;
        }

        $settings = $license['settings'] ?? [];
        $keys = explode('.', $path);
        $value = $settings;
        foreach ($keys as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }

    /**
     * Get the full license validation result.
     */
    public function validate(): ?array
    {
        // In-memory cache for this request
        if ($this->cachedResult !== null) {
            return $this->cachedResult;
        }

        $apiKey = $this->getApiKey();
        if (empty($apiKey)) {
            $this->cachedResult = ['valid' => false, 'error' => 'no_key'];
            return $this->cachedResult;
        }

        // Check persistent cache first
        $cacheKey = self::CACHE_KEY_PREFIX . md5($apiKey);
        $cached = $this->cache->load($cacheKey);
        if ($cached) {
            try {
                $this->cachedResult = $this->json->unserialize($cached);
                return $this->cachedResult;
            } catch (\Exception $e) {
                // Cache corrupted, re-validate
            }
        }

        // Call the platform to validate
        $this->cachedResult = $this->callPlatform($apiKey);

        // Cache the result
        $cacheHours = (int) ($this->scopeConfig->getValue(
            self::CONFIG_PREFIX . 'cache_hours',
            ScopeInterface::SCOPE_STORE
        ) ?: 6);

        $this->cache->save(
            $this->json->serialize($this->cachedResult),
            $cacheKey,
            [CacheType::CACHE_TAG],
            $cacheHours * 3600
        );

        return $this->cachedResult;
    }

    /**
     * Force re-validation (clears cache).
     */
    public function revalidate(): ?array
    {
        $apiKey = $this->getApiKey();
        if (!empty($apiKey)) {
            $cacheKey = self::CACHE_KEY_PREFIX . md5($apiKey);
            $this->cache->remove($cacheKey);
        }
        $this->cachedResult = null;
        return $this->validate();
    }

    private function callPlatform(string $apiKey): array
    {
        try {
            $validationUrl = $this->platformConfig->getServiceEndpoint('v1/license/validate');

            $domain = $this->getStoreDomain();
            $fingerprint = hash('sha256', $domain . '|' . php_uname('n') . '|' . BP);

            $skipSsl = $this->platformConfig->shouldSkipSslVerify();
            $curl = $this->curlFactory->create();
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, !$skipSsl);
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, $skipSsl ? 0 : 2);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Authorization', 'Bearer ' . $apiKey);
            $curl->setTimeout(5);
            $curl->post($validationUrl, $this->json->serialize([
                'domain' => $domain,
                'fingerprint' => $fingerprint,
                'modules' => $this->getInstalledModules(),
            ]));

            if ($curl->getStatus() >= 200 && $curl->getStatus() < 300) {
                $response = $this->json->unserialize($curl->getBody());
                return [
                    'valid' => $response['valid'] ?? false,
                    'services' => $response['services'] ?? [],
                    'settings' => $response['settings'] ?? [],
                    'plan' => $response['plan'] ?? 'free',
                    'domain' => $domain,
                    'expires' => $response['expires'] ?? null,
                    'checked_at' => date('Y-m-d H:i:s'),
                ];
            }

            return ['valid' => false, 'error' => 'api_error', 'status' => $curl->getStatus()];
        } catch (\Exception $e) {
            $this->logger->error('Devexa license validation error: ' . $e->getMessage());
            // On network error, be generous — allow cached/last-known state
            return ['valid' => true, 'services' => [], 'error' => 'network', 'checked_at' => date('Y-m-d H:i:s')];
        }
    }

    private function getApiKey(): string
    {
        return $this->platformConfig->getApiKey();
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

    private function getInstalledModules(): array
    {
        $modules = [];
        $devexaModules = [
            'Devexa_AiRecommendation',
            'Devexa_FormProtection',
            'Devexa_SmartSearch',
        ];
        foreach ($devexaModules as $module) {
            if ($this->scopeConfig->getValue('modules/' . $module)) {
                $modules[] = $module;
            }
        }
        return $modules;
    }
}
