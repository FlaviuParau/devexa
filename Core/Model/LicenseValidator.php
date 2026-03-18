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

    /**
     * Grace period: how long services keep working after the platform becomes unreachable.
     * After this, services are disabled until the platform responds again.
     */
    private const NETWORK_GRACE_HOURS = 24;

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

        $cacheKey = self::CACHE_KEY_PREFIX . md5($apiKey);

        // Check persistent cache first
        $cached = $this->cache->load($cacheKey);
        if ($cached) {
            try {
                $cachedData = $this->json->unserialize($cached);

                // Check if cached license has expired
                if ($this->isLicenseExpired($cachedData)) {
                    // License expired — must re-validate, don't use stale cache
                    $this->cache->remove($cacheKey);
                } else {
                    $this->cachedResult = $cachedData;
                    return $this->cachedResult;
                }
            } catch (\Exception $e) {
                // Cache corrupted, re-validate
            }
        }

        // Call the platform to validate
        $result = $this->callPlatform($apiKey);

        // On network error: use last known good result within grace period
        if (isset($result['error']) && $result['error'] === 'network') {
            $lastGood = $this->getLastKnownGood($apiKey);
            if ($lastGood) {
                // Use last known good, but mark it as degraded
                $lastGood['degraded'] = true;
                $lastGood['network_error'] = true;
                $this->cachedResult = $lastGood;

                // Re-cache with shorter TTL (1 hour) so we retry sooner
                $this->cache->save(
                    $this->json->serialize($this->cachedResult),
                    $cacheKey,
                    [CacheType::CACHE_TAG],
                    3600
                );
                return $this->cachedResult;
            }

            // No last known good — license is invalid
            $this->cachedResult = $result;
            return $this->cachedResult;
        }

        $this->cachedResult = $result;

        // Cache the result + store as last known good if valid
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

        // Store as "last known good" for grace period fallback
        if ($result['valid'] ?? false) {
            $this->cache->save(
                $this->json->serialize($this->cachedResult),
                $cacheKey . '_last_good',
                [CacheType::CACHE_TAG],
                self::NETWORK_GRACE_HOURS * 3600
            );
        }

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

    /**
     * Check if a license result indicates the subscription has expired.
     */
    private function isLicenseExpired(array $data): bool
    {
        if (!($data['valid'] ?? false)) {
            return true;
        }

        $expires = $data['expires'] ?? null;
        if ($expires && strtotime($expires) < time()) {
            return true;
        }

        return false;
    }

    /**
     * Get the last known good license result within grace period.
     * Returns null if no valid result exists or grace period has elapsed.
     */
    private function getLastKnownGood(string $apiKey): ?array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . md5($apiKey) . '_last_good';
        $cached = $this->cache->load($cacheKey);
        if (!$cached) {
            return null;
        }

        try {
            $data = $this->json->unserialize($cached);

            // Must be a valid license
            if (!($data['valid'] ?? false)) {
                return null;
            }

            // Must not be expired
            if ($this->isLicenseExpired($data)) {
                $this->cache->remove($cacheKey);
                return null;
            }

            // Check grace period from last successful check
            $checkedAt = $data['checked_at'] ?? null;
            if ($checkedAt) {
                $hoursSinceCheck = (time() - strtotime($checkedAt)) / 3600;
                if ($hoursSinceCheck > self::NETWORK_GRACE_HOURS) {
                    $this->cache->remove($cacheKey);
                    return null;
                }
            }

            return $data;
        } catch (\Exception $e) {
            return null;
        }
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
            // Network error — don't grant access, let caller use grace period fallback
            return ['valid' => false, 'services' => [], 'error' => 'network', 'checked_at' => date('Y-m-d H:i:s')];
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
