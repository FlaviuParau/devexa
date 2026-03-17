<?php

declare(strict_types=1);

namespace Devexa\Core\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Central platform configuration.
 * Single source of truth for API key and platform URL.
 * All Devexa modules use this instead of their own API key fields.
 */
class PlatformConfig
{
    private const PRODUCTION_URL = 'https://ai.devexa.ro';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Get the decrypted API key (license key).
     */
    public function getApiKey(): string
    {
        $value = (string) $this->scopeConfig->getValue(
            'devexa_core/license/key',
            ScopeInterface::SCOPE_STORE
        );

        if (empty($value)) {
            return '';
        }

        if (!str_starts_with($value, 'dxk_')) {
            return $this->encryptor->decrypt($value);
        }

        return $value;
    }

    /**
     * Get the platform base URL.
     * Dev mode: uses the configured local URL.
     * Production: https://ai.devexa.ro
     */
    public function getPlatformBaseUrl(): string
    {
        if ($this->isDevMode()) {
            $devUrl = (string) $this->scopeConfig->getValue(
                'devexa_core/development/platform_url',
                ScopeInterface::SCOPE_STORE
            );
            if (!empty($devUrl)) {
                return rtrim($devUrl, '/');
            }
        }

        return self::PRODUCTION_URL;
    }

    /**
     * Get the platform URL for browser-facing resources (JS, CSS).
     * In dev mode: replaces host.docker.internal with localhost (browser can't reach Docker internal hostnames).
     * In production: same as getPlatformBaseUrl().
     */
    public function getBrowserBaseUrl(): string
    {
        $url = $this->getPlatformBaseUrl();

        // Browser can't reach host.docker.internal — replace with localhost
        $url = str_replace('host.docker.internal', 'localhost', $url);

        return $url;
    }

    /**
     * Get the full API endpoint for a specific service path.
     * Example: getServiceEndpoint('v1/recommendations/recommend')
     */
    public function getServiceEndpoint(string $path): string
    {
        return $this->getPlatformBaseUrl() . '/' . ltrim($path, '/');
    }

    /**
     * Check if development mode is enabled.
     */
    public function isDevMode(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'devexa_core/development/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if SSL verification should be skipped (dev environments).
     */
    public function shouldSkipSslVerify(): bool
    {
        if (!$this->isDevMode()) {
            return false;
        }
        $baseUrl = $this->getPlatformBaseUrl();
        return str_contains($baseUrl, 'docker.internal') || str_contains($baseUrl, 'localhost');
    }
}
