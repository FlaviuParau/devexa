<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;

class Tracker implements ArgumentInterface
{
    private const CONFIG_PATH_PREFIX = 'devexa_ai_recommendation/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_PREFIX . 'general/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getTrackingConfig(): array
    {
        return [
            'trackProductViews' => $this->scopeConfig->isSetFlag(self::CONFIG_PATH_PREFIX . 'tracking/track_product_views', ScopeInterface::SCOPE_STORE),
            'trackAddToCart' => $this->scopeConfig->isSetFlag(self::CONFIG_PATH_PREFIX . 'tracking/track_add_to_cart', ScopeInterface::SCOPE_STORE),
            'trackPurchases' => $this->scopeConfig->isSetFlag(self::CONFIG_PATH_PREFIX . 'tracking/track_purchases', ScopeInterface::SCOPE_STORE),
            'trackSearch' => $this->scopeConfig->isSetFlag(self::CONFIG_PATH_PREFIX . 'tracking/track_search', ScopeInterface::SCOPE_STORE),
            'trackCategoryViews' => $this->scopeConfig->isSetFlag(self::CONFIG_PATH_PREFIX . 'tracking/track_category_views', ScopeInterface::SCOPE_STORE),
        ];
    }
}
