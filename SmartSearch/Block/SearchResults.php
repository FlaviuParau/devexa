<?php

declare(strict_types=1);

namespace Devexa\SmartSearch\Block;

use Devexa\SmartSearch\Model\Config;
use Devexa\SmartSearch\Model\Search\AiSearch;
use Devexa\SmartSearch\Model\Search\CategorySearch;
use Devexa\SmartSearch\Model\Search\PageSearch;
use Devexa\SmartSearch\Model\Search\ProductSearch;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Template;

class SearchResults extends Template
{
    private ?array $results = null;

    public function __construct(
        Template\Context $context,
        private readonly Config $config,
        private readonly ProductSearch $productSearch,
        private readonly CategorySearch $categorySearch,
        private readonly PageSearch $pageSearch,
        private readonly AiSearch $aiSearch,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly RequestInterface $request,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getQuery(): string
    {
        return trim((string) $this->request->getParam('q', ''));
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && mb_strlen($this->getQuery()) >= $this->config->getMinChars();
    }

    /**
     * Get matching categories as category objects with name and URL.
     */
    public function getCategories(): array
    {
        if (!$this->config->isSectionEnabled('categories')) {
            return [];
        }
        return $this->categorySearch->search($this->getQuery(), $this->config->getSectionLimit('categories'));
    }

    /**
     * Get matching CMS pages.
     */
    public function getPages(): array
    {
        if (!$this->config->isSectionEnabled('pages')) {
            return [];
        }
        return $this->pageSearch->search($this->getQuery(), $this->config->getSectionLimit('pages'));
    }

    /**
     * Get AI recommended products as full Magento product objects.
     * These can be rendered with ProductListItem::getItemHtml()
     *
     * @return \Magento\Catalog\Api\Data\ProductInterface[]
     */
    public function getAiProducts(): array
    {
        if (!$this->config->isAiEnabled()) {
            return [];
        }

        $aiResults = $this->aiSearch->search($this->getQuery(), $this->config->getSectionLimit('ai'));
        if (empty($aiResults)) {
            return [];
        }

        $productIds = array_map(fn($item) => (int) $item['id'], $aiResults);
        return $this->loadProductObjects($productIds);
    }

    /**
     * Get browse categories (fallback when no results).
     */
    public function getBrowseCategories(): array
    {
        return $this->categorySearch->getTopCategories(8);
    }

    /**
     * Load full Magento product objects by IDs.
     * These can be passed to ProductListItem::getItemHtml()
     *
     * @return \Magento\Catalog\Api\Data\ProductInterface[]
     */
    private function loadProductObjects(array $productIds, int $limit = 10): array
    {
        if (empty($productIds)) {
            return [];
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', $productIds, 'in')
            ->addFilter('status', 1)
            ->setPageSize($limit)
            ->create();

        return $this->productRepository->getList($searchCriteria)->getItems();
    }
}
