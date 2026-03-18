<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Block;

use Devexa\AiRecommendation\Api\RecommendationEngineInterface;
use Devexa\Core\Model\LicenseValidator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;

class OrderSuccessRecommendations extends Template
{
    private const CONFIG_PATH_PREFIX = 'devexa_ai_recommendation/';

    private ?array $loadedItems = null;
    private ?Order $lastOrder = null;

    public function __construct(
        Template\Context $context,
        private readonly CheckoutSession $checkoutSession,
        private readonly RecommendationEngineInterface $engine,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LicenseValidator $licenseValidator,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Check if the block should render
     */
    public function isEnabled(): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::CONFIG_PATH_PREFIX . 'general/enabled', ScopeInterface::SCOPE_STORE)) {
            return false;
        }

        if (!$this->licenseValidator->isServiceActive('recommendations')) {
            return false;
        }

        return true;
    }

    /**
     * Get the last placed order from checkout session
     */
    public function getLastOrder(): ?Order
    {
        if ($this->lastOrder === null) {
            $this->lastOrder = $this->checkoutSession->getLastRealOrder();
        }

        return $this->lastOrder && $this->lastOrder->getId() ? $this->lastOrder : null;
    }

    /**
     * Get product IDs from the last order
     *
     * @return int[]
     */
    public function getOrderProductIds(): array
    {
        $order = $this->getLastOrder();
        if (!$order) {
            return [];
        }

        $productIds = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $productIds[] = (int) $item->getProductId();
        }

        return $productIds;
    }

    /**
     * Get recommended products based on what was purchased
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

        $orderProductIds = $this->getOrderProductIds();
        if (empty($orderProductIds)) {
            return $this->loadedItems;
        }

        $limit = $this->getMaxProducts();

        // Use the first purchased product as the anchor for crosssell recommendations
        $anchorProductId = $orderProductIds[0];
        $recommendedIds = $this->engine->getRecommendations(
            'cart',
            $anchorProductId,
            null,
            $limit,
            'crosssell'
        );

        if (empty($recommendedIds)) {
            return $this->loadedItems;
        }

        // Exclude products that were just purchased
        $recommendedIds = array_map('intval', $recommendedIds);
        $recommendedIds = array_filter($recommendedIds, fn($id) => !in_array($id, $orderProductIds, true));

        if (empty($recommendedIds)) {
            return $this->loadedItems;
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', array_values($recommendedIds), 'in')
            ->addFilter('status', 1)
            ->setPageSize($limit)
            ->create();

        $this->loadedItems = $this->productRepository->getList($searchCriteria)->getItems();

        return $this->loadedItems;
    }

    public function getTitle(): string
    {
        $title = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'widgets/crosssell_title',
            ScopeInterface::SCOPE_STORE
        );

        return $title ?: (string) __('Customers who bought these items also bought');
    }

    public function getMaxProducts(): int
    {
        $platformMax = $this->licenseValidator->getSetting('recommendations.max_products');
        if ($platformMax) {
            return (int) $platformMax;
        }

        return (int) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'widgets/max_products',
            ScopeInterface::SCOPE_STORE
        ) ?: 8;
    }
}
