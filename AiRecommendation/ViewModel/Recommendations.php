<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\ViewModel;

use Devexa\AiRecommendation\Api\RecommendationEngineInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;

class Recommendations implements ArgumentInterface
{
    private const CONFIG_PATH_PREFIX = 'devexa_ai_recommendation/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly RecommendationEngineInterface $engine,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_PREFIX . 'general/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isEnabledFor(string $page): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $configMap = [
            'product' => 'widgets/show_on_product_page',
            'cart' => 'widgets/show_on_cart_page',
            'homepage' => 'widgets/show_on_homepage',
            'category' => 'widgets/show_on_category_page',
        ];

        if (!isset($configMap[$page])) {
            return false;
        }

        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_PREFIX . $configMap[$page],
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getTitle(string $page): string
    {
        $configMap = [
            'product' => 'widgets/product_page_title',
            'cart' => 'widgets/cart_page_title',
            'homepage' => 'widgets/homepage_title',
            'category' => 'widgets/category_page_title',
        ];

        return (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . ($configMap[$page] ?? 'widgets/product_page_title'),
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getMaxProducts(): int
    {
        return (int) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'widgets/max_products',
            ScopeInterface::SCOPE_STORE
        ) ?: 8;
    }

    public function getRecommendedProducts(string $context, ?int $productId = null, ?string $visitorId = null): array
    {
        $productIds = $this->engine->getRecommendations($context, $productId, $visitorId, $this->getMaxProducts());

        if (empty($productIds)) {
            return [];
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', $productIds, 'in')
            ->addFilter('status', 1)
            ->addFilter('visibility', [2, 3, 4], 'in')
            ->setPageSize($this->getMaxProducts())
            ->create();

        return $this->productRepository->getList($searchCriteria)->getItems();
    }
}
