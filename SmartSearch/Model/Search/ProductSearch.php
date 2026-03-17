<?php

declare(strict_types=1);

namespace Devexa\SmartSearch\Model\Search;

use Devexa\SmartSearch\Model\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\Search\SearchCriteriaInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Search\Api\SearchInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ProductSearch
{
    public function __construct(
        private readonly SearchInterface $searchEngine,
        private readonly SearchCriteriaInterfaceFactory $searchCriteriaFactory,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $productSearchCriteriaBuilder,
        private readonly ImageHelper $imageHelper,
        private readonly PricingHelper $pricingHelper,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function search(string $query, int $limit = 5): array
    {
        $cleanQuery = $this->cleanQuery($query);
        if (empty($cleanQuery)) {
            return [];
        }

        try {
            // Use Magento's SearchInterface which routes to OpenSearch/Elasticsearch
            return $this->elasticSearch($cleanQuery, $limit);
        } catch (\Exception $e) {
            $this->logger->warning('SmartSearch Elasticsearch failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Search via Elasticsearch/OpenSearch through Magento's native search API.
     *
     * This uses the same search engine as the catalog search page:
     * - Relevance ranking (TF-IDF scoring)
     * - Fuzzy matching (typo tolerance)
     * - Stemming (searching/searched → search)
     * - All searchable attributes (name, description, SKU, etc.)
     * - Synonyms (if configured in Magento)
     */
    private function elasticSearch(string $query, int $limit): array
    {
        // Build search criteria using Magento's search container
        $searchCriteria = $this->searchCriteriaFactory->create();

        $filter = $this->filterBuilder
            ->setField('search_term')
            ->setValue($query)
            ->setConditionType('like')
            ->create();

        $filterGroup = $this->filterGroupBuilder->addFilter($filter)->create();
        $searchCriteria->setFilterGroups([$filterGroup]);
        $searchCriteria->setRequestName('quick_search_container');
        $searchCriteria->setPageSize($limit * 2); // Fetch extra to allow post-filtering

        // Execute search via OpenSearch/Elasticsearch
        $searchResult = $this->searchEngine->search($searchCriteria);

        if ($searchResult->getTotalCount() === 0) {
            return [];
        }

        // Get product IDs from search results (already ranked by relevance)
        $productIds = [];
        foreach ($searchResult->getItems() as $item) {
            $productIds[] = (int) $item->getId();
        }

        if (empty($productIds)) {
            return [];
        }

        // Load full product data
        $excludeSkus = $this->config->getExcludeSkus();
        $criteria = $this->productSearchCriteriaBuilder
            ->addFilter('entity_id', $productIds, 'in')
            ->addFilter('status', 1)
            ->addFilter('visibility', [2, 3, 4], 'in')
            ->setPageSize($limit)
            ->create();

        $products = $this->productRepository->getList($criteria)->getItems();

        // Sort by Elasticsearch relevance order
        $idOrder = array_flip($productIds);
        usort($products, function ($a, $b) use ($idOrder) {
            return ($idOrder[$a->getId()] ?? 999) - ($idOrder[$b->getId()] ?? 999);
        });

        return $this->formatResults($products, $query, $excludeSkus, $limit);
    }

    /**
     * Remove excluded/stop words from the query.
     */
    /**
     * Get popular/recommended products for the initial state (on focus before typing).
     */
    public function getPopularProducts(int $limit = 5): array
    {
        try {
            // Get newest visible products via repository
            $searchCriteria = $this->productSearchCriteriaBuilder
                ->addFilter('status', 1)
                ->addFilter('visibility', [2, 3, 4], 'in')
                ->setPageSize($limit)
                ->create();

            $searchCriteria->setSortOrders([
                new \Magento\Framework\Api\SortOrder([
                    'field' => 'entity_id',
                    'direction' => \Magento\Framework\Api\SortOrder::SORT_DESC
                ])
            ]);

            $products = $this->productRepository->getList($searchCriteria)->getItems();
            return $this->formatResults($products, '', [], $limit);
        } catch (\Exception $e) {
            $this->logger->warning('PopularProducts error: ' . $e->getMessage());
            return [];
        }
    }

    private function cleanQuery(string $query): string
    {
        $excludeWords = $this->config->getExcludeWords();
        if (empty($excludeWords)) {
            return trim($query);
        }

        $words = preg_split('/\s+/', trim($query));
        $filtered = array_filter($words, function ($word) use ($excludeWords) {
            return !in_array(mb_strtolower($word), $excludeWords, true);
        });

        $result = trim(implode(' ', $filtered));
        return !empty($result) ? $result : trim($query); // Don't return empty if all words excluded
    }

    /**
     * Format product objects into search result array.
     */
    private function formatResults(array $products, string $query, array $excludeSkus, int $limit): array
    {
        $results = [];
        $showDescription = $this->config->showProductDescription();
        $minPrice = $this->config->getFilterMinPrice();
        $maxPrice = $this->config->getFilterMaxPrice();
        $inStockOnly = $this->config->isFilterInStockOnly();

        foreach ($products as $product) {
            if (count($results) >= $limit) break;

            // Post-filter: exclude SKUs
            if (!empty($excludeSkus) && in_array($product->getSku(), $excludeSkus, true)) {
                continue;
            }

            // Post-filter: price range
            $price = (float) $product->getFinalPrice();
            if ($minPrice > 0 && $price < $minPrice) continue;
            if ($maxPrice > 0 && $price > $maxPrice) continue;

            $thumbUrl = '';
            $gridUrl = '';
            try {
                // Small thumbnail for list mode (75x75)
                $thumbUrl = $this->imageHelper->init($product, 'product_thumbnail_image')
                    ->setImageFile($product->getSmallImage())
                    ->getUrl();
                // Larger image for grid mode (category_page_grid ~240x300)
                $gridUrl = $this->imageHelper->init($product, 'category_page_grid')
                    ->setImageFile($product->getSmallImage())
                    ->getUrl();
            } catch (\Exception $e) {
                // placeholder
            }

            $item = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'sku' => $product->getSku(),
                'url' => $product->getProductUrl(),
                'image' => $gridUrl ?: $thumbUrl,
                'image_thumb' => $thumbUrl,
                'image_grid' => $gridUrl ?: $thumbUrl,
                'price' => $this->pricingHelper->currency($price, true, false),
                'price_raw' => $price,
            ];

            if ($showDescription && $product->getShortDescription()) {
                $desc = strip_tags((string) $product->getShortDescription());
                $item['description'] = mb_strlen($desc) > 80 ? mb_substr($desc, 0, 80) . '...' : $desc;
            }

            $results[] = $item;
        }

        return $results;
    }
}
