<?php

declare(strict_types=1);

namespace Devexa\SmartSearch\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const PREFIX = 'devexa_smart_search/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'general/enabled', ScopeInterface::SCOPE_STORE);
    }

    public function getMinChars(): int
    {
        return (int) ($this->scopeConfig->getValue(self::PREFIX . 'general/min_chars', ScopeInterface::SCOPE_STORE) ?: 3);
    }

    public function getDebounceMs(): int
    {
        return (int) ($this->scopeConfig->getValue(self::PREFIX . 'general/debounce_ms', ScopeInterface::SCOPE_STORE) ?: 300);
    }

    public function isSectionEnabled(string $section): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'sections/' . $section . '_enabled', ScopeInterface::SCOPE_STORE);
    }

    public function getSectionLimit(string $section): int
    {
        return (int) ($this->scopeConfig->getValue(self::PREFIX . 'sections/' . $section . '_limit', ScopeInterface::SCOPE_STORE) ?: 5);
    }

    public function isAiEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'sections/ai_enabled', ScopeInterface::SCOPE_STORE);
    }

    public function getApiKey(): string
    {
        $value = (string) $this->scopeConfig->getValue(self::PREFIX . 'ai/api_key', ScopeInterface::SCOPE_STORE);
        if (!empty($value) && !str_starts_with($value, 'dxk_')) {
            return $this->encryptor->decrypt($value);
        }
        return $value;
    }

    public function getApiEndpoint(): string
    {
        $env = (string) $this->scopeConfig->getValue(self::PREFIX . 'ai/environment', ScopeInterface::SCOPE_STORE) ?: 'production';
        if ($env === 'development') {
            $dev = (string) $this->scopeConfig->getValue(self::PREFIX . 'ai/api_endpoint_dev', ScopeInterface::SCOPE_STORE);
            if (!empty($dev)) {
                return $dev;
            }
        }
        return (string) $this->scopeConfig->getValue(self::PREFIX . 'ai/api_endpoint', ScopeInterface::SCOPE_STORE);
    }

    public function isDevEnvironment(): bool
    {
        $endpoint = $this->getApiEndpoint();
        return str_contains($endpoint, 'docker.internal') || str_contains($endpoint, 'localhost');
    }

    public function getProductDisplay(): string
    {
        return (string) ($this->scopeConfig->getValue(self::PREFIX . 'layout/product_display', ScopeInterface::SCOPE_STORE) ?: 'list');
    }

    public function getProductColumns(): int
    {
        return (int) ($this->scopeConfig->getValue(self::PREFIX . 'layout/product_columns', ScopeInterface::SCOPE_STORE) ?: 4);
    }

    public function getSidebarPosition(): string
    {
        return (string) ($this->scopeConfig->getValue(self::PREFIX . 'layout/sidebar_position', ScopeInterface::SCOPE_STORE) ?: 'left');
    }

    public function getPopupMaxWidth(): int
    {
        return (int) ($this->scopeConfig->getValue(self::PREFIX . 'layout/popup_max_width', ScopeInterface::SCOPE_STORE) ?: 0);
    }

    public function getHighlightColor(): string
    {
        return (string) ($this->scopeConfig->getValue(self::PREFIX . 'appearance/highlight_color', ScopeInterface::SCOPE_STORE) ?: '#7c3aed');
    }

    public function showProductImage(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'appearance/show_product_image', ScopeInterface::SCOPE_STORE);
    }

    public function showProductPrice(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'appearance/show_product_price', ScopeInterface::SCOPE_STORE);
    }

    public function showProductSku(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'appearance/show_product_sku', ScopeInterface::SCOPE_STORE);
    }

    public function showProductDescription(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'appearance/show_product_description', ScopeInterface::SCOPE_STORE);
    }

    // ---- Advanced Filters ----

    public function getExcludeWords(): array
    {
        $value = (string) $this->scopeConfig->getValue(self::PREFIX . 'filters/exclude_words', ScopeInterface::SCOPE_STORE);
        if (empty($value)) {
            return [];
        }
        return array_map('trim', array_map('mb_strtolower', explode(',', $value)));
    }

    public function isFilterInStockOnly(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'filters/in_stock_only', ScopeInterface::SCOPE_STORE);
    }

    public function getFilterMinPrice(): float
    {
        return (float) $this->scopeConfig->getValue(self::PREFIX . 'filters/min_price', ScopeInterface::SCOPE_STORE);
    }

    public function getFilterMaxPrice(): float
    {
        return (float) $this->scopeConfig->getValue(self::PREFIX . 'filters/max_price', ScopeInterface::SCOPE_STORE);
    }

    public function getExcludeCategories(): array
    {
        $value = (string) $this->scopeConfig->getValue(self::PREFIX . 'filters/exclude_categories', ScopeInterface::SCOPE_STORE);
        if (empty($value)) {
            return [];
        }
        return array_map('intval', array_filter(explode(',', $value)));
    }

    public function getExcludeSkus(): array
    {
        $value = (string) $this->scopeConfig->getValue(self::PREFIX . 'filters/exclude_skus', ScopeInterface::SCOPE_STORE);
        if (empty($value)) {
            return [];
        }
        return array_map('trim', explode(',', $value));
    }
}
