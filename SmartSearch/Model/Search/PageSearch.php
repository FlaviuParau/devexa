<?php

declare(strict_types=1);

namespace Devexa\SmartSearch\Model\Search;

use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

class PageSearch
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function search(string $query, int $limit = 3): array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('is_active', 1)
            ->addStoreFilter($storeId)
            ->addFieldToFilter('title', ['like' => '%' . $query . '%'])
            // Exclude system pages
            ->addFieldToFilter('identifier', ['nin' => ['no-route', 'home', 'enable-cookies', 'privacy-policy-cookie-restriction-mode']])
            ->setPageSize($limit)
            ->setCurPage(1);

        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $results = [];
        foreach ($collection as $page) {
            $results[] = [
                'id' => $page->getId(),
                'title' => $page->getTitle(),
                'url' => $baseUrl . $page->getIdentifier(),
            ];
        }

        return $results;
    }
}
