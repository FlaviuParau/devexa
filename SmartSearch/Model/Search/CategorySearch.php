<?php

declare(strict_types=1);

namespace Devexa\SmartSearch\Model\Search;

use Devexa\SmartSearch\Model\Config;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

class CategorySearch
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    public function search(string $query, int $limit = 3): array
    {
        // Clean query — remove stop words
        $excludeWords = $this->config->getExcludeWords();
        $words = preg_split('/\s+/', trim($query));
        $filtered = array_filter($words, fn($w) => !in_array(mb_strtolower($w), $excludeWords, true));
        $cleanQuery = implode(' ', $filtered);
        if (empty($cleanQuery)) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['name', 'url_key', 'url_path', 'image', 'product_count'])
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('name', ['like' => '%' . $cleanQuery . '%'])
            ->addAttributeToFilter('level', ['gt' => 1])
            ->setPageSize($limit)
            ->setCurPage(1)
            ->setStoreId($this->storeManager->getStore()->getId());

        // Exclude specific categories
        $excludeIds = $this->config->getExcludeCategories();
        if (!empty($excludeIds)) {
            $collection->addAttributeToFilter('entity_id', ['nin' => $excludeIds]);
        }

        $results = [];
        foreach ($collection as $category) {
            $results[] = [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'url' => $category->getUrl(),
                'product_count' => (int) $category->getProductCount(),
            ];
        }

        return $results;
    }

    /**
     * Get top-level categories for browsing (initial state / no results fallback).
     */
    public function getTopCategories(int $limit = 8): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['name', 'url_key', 'url_path', 'image'])
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('level', 2) // Direct children of root
            ->addAttributeToFilter('include_in_menu', 1)
            ->setPageSize($limit)
            ->setCurPage(1)
            ->setStoreId($this->storeManager->getStore()->getId());

        $excludeIds = $this->config->getExcludeCategories();
        if (!empty($excludeIds)) {
            $collection->addAttributeToFilter('entity_id', ['nin' => $excludeIds]);
        }

        $results = [];
        foreach ($collection as $category) {
            $imageUrl = '';
            if ($category->getImage()) {
                try {
                    $imageUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA)
                        . 'catalog/category/' . $category->getImage();
                } catch (\Exception $e) {}
            }

            $results[] = [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'url' => $category->getUrl(),
                'image' => $imageUrl,
            ];
        }

        return $results;
    }
}
