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

    /**
     * Supported recommendation types and their default titles
     */
    private const TYPE_TITLES = [
        'frequently_bought' => 'Frequently Bought Together',
        'upsell'            => 'You May Also Like',
        'crosssell'         => 'Customers Also Bought',
        'similar'           => 'Similar Products',
    ];

    /**
     * Map recommendation type to admin config field for custom title
     */
    private const TYPE_TITLE_CONFIG = [
        'frequently_bought' => 'widgets/fbt_title',
        'upsell'            => 'widgets/upsell_title',
        'crosssell'         => 'widgets/crosssell_title',
        'similar'           => 'widgets/similar_title',
    ];

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
        $type = $this->getRecommendationType();
        $productId = $this->getCurrentProductId();
        $visitorId = null; // Server-side rendering — no visitor ID available
        $limit = $this->getMaxProducts();

        $productIds = $this->engine->getRecommendations($context, $productId, $visitorId, $limit, $type);

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

    /**
     * Get the recommendation type (frequently_bought, upsell, crosssell, similar).
     * Falls back to 'upsell' if not set.
     */
    public function getRecommendationType(): string
    {
        $type = (string) ($this->getData('recommendation_type') ?: '');
        if ($type && isset(self::TYPE_TITLES[$type])) {
            return $type;
        }

        // Legacy fallback: map context to a default type
        $contextDefaults = [
            'product'  => 'upsell',
            'cart'     => 'crosssell',
            'homepage' => 'upsell',
            'category' => 'upsell',
        ];

        return $contextDefaults[$this->getRecommendationContext()] ?? 'upsell';
    }

    public function getCurrentProductId(): ?int
    {
        $product = $this->registry->registry('current_product');
        return $product ? (int) $product->getId() : null;
    }

    /**
     * Get the widget title. Checks type-specific config first, then falls back to
     * context-based config (legacy), then to the hardcoded default for the type.
     */
    public function getTitle(): string
    {
        $type = $this->getRecommendationType();

        // 1. Check type-specific config
        if (isset(self::TYPE_TITLE_CONFIG[$type])) {
            $title = (string) $this->scopeConfig->getValue(
                self::CONFIG_PATH_PREFIX . self::TYPE_TITLE_CONFIG[$type],
                ScopeInterface::SCOPE_STORE
            );
            if ($title) {
                return $title;
            }
        }

        // 2. Fallback to legacy context-based config
        $context = $this->getRecommendationContext();
        $contextConfigMap = [
            'product'  => 'widgets/product_page_title',
            'cart'     => 'widgets/cart_page_title',
            'homepage' => 'widgets/homepage_title',
            'category' => 'widgets/category_page_title',
        ];

        if (isset($contextConfigMap[$context])) {
            $title = (string) $this->scopeConfig->getValue(
                self::CONFIG_PATH_PREFIX . $contextConfigMap[$context],
                ScopeInterface::SCOPE_STORE
            );
            if ($title) {
                return $title;
            }
        }

        // 3. Hardcoded default for the type
        return self::TYPE_TITLES[$type] ?? 'Recommended for You';
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
