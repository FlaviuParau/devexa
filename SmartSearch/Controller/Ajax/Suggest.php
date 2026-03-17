<?php

declare(strict_types=1);

namespace Devexa\SmartSearch\Controller\Ajax;

use Devexa\SmartSearch\Model\Config;
use Devexa\SmartSearch\Model\Search\AiSearch;
use Devexa\SmartSearch\Model\Search\CategorySearch;
use Devexa\SmartSearch\Model\Search\PageSearch;
use Devexa\SmartSearch\Model\Search\ProductSearch;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Suggest implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly ProductSearch $productSearch,
        private readonly CategorySearch $categorySearch,
        private readonly PageSearch $pageSearch,
        private readonly AiSearch $aiSearch
    ) {
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isEnabled()) {
            return $result->setData(['sections' => []]);
        }

        $query = trim((string) $this->request->getParam('q', ''));
        $isInitial = (bool) $this->request->getParam('initial', false);

        // Initial state on focus: return popular categories + recommended products
        if ($isInitial || mb_strlen($query) < $this->config->getMinChars()) {
            if ($isInitial) {
                $initialSections = [];

                // Popular categories
                if ($this->config->isSectionEnabled('categories')) {
                    $topCategories = $this->categorySearch->getTopCategories(8);
                    if (!empty($topCategories)) {
                        $initialSections[] = [
                            'type' => 'browse_categories',
                            'title' => __('Browse Categories')->render(),
                            'items' => $topCategories,
                            'count' => count($topCategories),
                        ];
                    }
                }

                // Recommended products (AI or top selling)
                if ($this->config->isSectionEnabled('products')) {
                    $recProducts = $this->productSearch->getPopularProducts($this->config->getSectionLimit('products'));
                    if (!empty($recProducts)) {
                        $initialSections[] = [
                            'type' => 'recommended_products',
                            'title' => __('Recommended Products')->render(),
                            'items' => $recProducts,
                            'count' => count($recProducts),
                        ];
                    }
                }

                return $result->setData([
                    'sections' => $initialSections,
                    'total' => array_sum(array_column($initialSections, 'count')),
                    'initial' => true,
                ]);
            }
            return $result->setData(['sections' => [], 'initial' => true]);
        }

        $sections = [];

        // Products
        if ($this->config->isSectionEnabled('products')) {
            $products = $this->productSearch->search($query, $this->config->getSectionLimit('products'));
            if (!empty($products)) {
                $sections[] = [
                    'type' => 'products',
                    'title' => __('Products')->render(),
                    'items' => $products,
                    'count' => count($products),
                ];
            }
        }

        // Categories
        if ($this->config->isSectionEnabled('categories')) {
            $categories = $this->categorySearch->search($query, $this->config->getSectionLimit('categories'));
            if (!empty($categories)) {
                $sections[] = [
                    'type' => 'categories',
                    'title' => __('Categories')->render(),
                    'items' => $categories,
                    'count' => count($categories),
                ];
            }
        }

        // CMS Pages
        if ($this->config->isSectionEnabled('pages')) {
            $pages = $this->pageSearch->search($query, $this->config->getSectionLimit('pages'));
            if (!empty($pages)) {
                $sections[] = [
                    'type' => 'pages',
                    'title' => __('Pages')->render(),
                    'items' => $pages,
                    'count' => count($pages),
                ];
            }
        }

        // AI Recommendations
        if ($this->config->isAiEnabled()) {
            $aiProducts = $this->aiSearch->search($query, $this->config->getSectionLimit('ai'));
            if (!empty($aiProducts)) {
                $sections[] = [
                    'type' => 'ai_recommendations',
                    'title' => __('Recommended for You')->render(),
                    'items' => $aiProducts,
                    'count' => count($aiProducts),
                ];
            }
        }

        // No results — show browse categories as fallback
        if (empty($sections)) {
            $fallbackCategories = $this->categorySearch->getTopCategories(6);
            if (!empty($fallbackCategories)) {
                $sections[] = [
                    'type' => 'browse_categories',
                    'title' => __('Need help? Browse categories')->render(),
                    'items' => $fallbackCategories,
                    'count' => count($fallbackCategories),
                ];
            }
        }

        return $result->setData([
            'query' => $query,
            'sections' => $sections,
            'total' => array_sum(array_column($sections, 'count')),
            'no_results' => empty(array_filter($sections, fn($s) => $s['type'] !== 'browse_categories')),
        ]);
    }
}
