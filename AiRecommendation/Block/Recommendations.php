<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Block;

use Devexa\AiRecommendation\Api\RecommendationEngineInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Devexa\Core\Model\LicenseValidator;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Store\Model\ScopeInterface;

class Recommendations extends Template
{
    private const CONFIG_PATH_PREFIX = 'devexa_ai_recommendation/';

    private ?array $loadedItems = null;

    public function __construct(
        Template\Context $context,
        private readonly RecommendationEngineInterface $engine,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Registry $registry,
        private readonly HttpContext $httpContext,
        private readonly LicenseValidator $licenseValidator,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get recommended products as full Magento product objects.
     *
     * @return Product[]
     */
    public function getItems(): array
    {
        if ($this->loadedItems !== null) {
            return $this->loadedItems;
        }

        $this->loadedItems = [];

        if (!$this->isEnabled()) {
            return $this->loadedItems;
        }

        $context = $this->getRecommendationContext();
        $productId = $this->getCurrentProductId();
        $visitorId = null; // Server-side rendering — no visitor ID available
        $limit = $this->getMaxProducts();

        $productIds = $this->engine->getRecommendations($context, $productId, $visitorId, $limit);

        if (empty($productIds)) {
            return $this->loadedItems;
        }

        // Convert to int and exclude current product
        $intProductIds = array_map('intval', $productIds);
        if ($productId) {
            $intProductIds = array_filter($intProductIds, fn($id) => $id !== $productId);
        }

        if (empty($intProductIds)) {
            return $this->loadedItems;
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', $intProductIds, 'in')
            ->addFilter('status', 1)
            ->setPageSize($limit)
            ->create();

        $this->loadedItems = $this->productRepository->getList($searchCriteria)->getItems();

        return $this->loadedItems;
    }

    public function getRecommendationContext(): string
    {
        return (string) ($this->getData('recommendation_context') ?: 'product');
    }

    public function getCurrentProductId(): ?int
    {
        $product = $this->registry->registry('current_product');
        return $product ? (int) $product->getId() : null;
    }

    public function getTitle(): string
    {
        $context = $this->getRecommendationContext();
        $configMap = [
            'product' => 'widgets/product_page_title',
            'cart' => 'widgets/cart_page_title',
            'homepage' => 'widgets/homepage_title',
            'category' => 'widgets/category_page_title',
        ];

        return (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . ($configMap[$context] ?? 'widgets/product_page_title'),
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isEnabled(): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::CONFIG_PATH_PREFIX . 'general/enabled', ScopeInterface::SCOPE_STORE)) {
            return false;
        }

        // License check — module won't render without a valid license
        if (!$this->licenseValidator->isServiceActive('recommendations')) {
            return false;
        }

        $context = $this->getRecommendationContext();
        $configMap = [
            'product' => 'widgets/show_on_product_page',
            'cart' => 'widgets/show_on_cart_page',
            'homepage' => 'widgets/show_on_homepage',
            'category' => 'widgets/show_on_category_page',
        ];

        if (isset($configMap[$context])) {
            return $this->scopeConfig->isSetFlag(
                self::CONFIG_PATH_PREFIX . $configMap[$context],
                ScopeInterface::SCOPE_STORE
            );
        }

        return true;
    }

    public function getMaxProducts(): int
    {
        // Platform settings take priority
        $platformMax = $this->licenseValidator->getSetting('recommendations.max_products');
        if ($platformMax) {
            return (int) $platformMax;
        }

        return (int) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'widgets/max_products',
            ScopeInterface::SCOPE_STORE
        ) ?: 8;
    }

    public function getDisplayMode(): string
    {
        return (string) ($this->getData('display_mode') ?: 'carousel');
    }
}
