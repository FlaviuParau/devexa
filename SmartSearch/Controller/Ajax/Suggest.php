<?php

declare(strict_types=1);

namespace Devexa\SmartSearch\Controller\Ajax;

use Devexa\Core\Model\PlatformConfig;
use Devexa\SmartSearch\Model\Config;
use Devexa\SmartSearch\Model\Search\AiSearch;
use Devexa\SmartSearch\Model\Search\CategorySearch;
use Devexa\SmartSearch\Model\Search\PageSearch;
use Devexa\SmartSearch\Model\Search\ProductSearch;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

class Suggest implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly ProductSearch $productSearch,
        private readonly CategorySearch $categorySearch,
        private readonly PageSearch $pageSearch,
        private readonly AiSearch $aiSearch,
        private readonly PlatformConfig $platformConfig,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json
    ) {
    }

    /**
     * Track search query to platform for analytics + AI training.
     * Fire and forget — does not block the search response.
     */
    private function trackSearchQuery(string $query, int $resultCount): void
    {
        try {
            $apiKey = $this->platformConfig->getApiKey();
            if (empty($apiKey)) {
                return;
            }

            $endpoint = $this->platformConfig->getServiceEndpoint('v1/recommendations/track');
            $skipSsl = $this->platformConfig->shouldSkipSslVerify();

            $curl = $this->curlFactory->create();
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, !$skipSsl);
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, $skipSsl ? 0 : 2);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Authorization', 'Bearer ' . $apiKey);
            $curl->setTimeout(2);
            $curl->post($endpoint, $this->json->serialize([
                'event' => 'search_query',
                'data' => [
                    'query' => $query,
                    'result_count' => $resultCount,
                ],
                'visitor_id' => $_COOKIE['devexa_visitor'] ?? null,
            ]));
        } catch (\Exception $e) {
            // Silent fail — tracking should never break search
        }
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

        // No results — get suggestions and show browse categories as fallback
        $suggestions = [];
        if (empty($sections)) {
            $suggestions = $this->productSearch->getSuggestions($query);

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

        $responseData = [
            'query' => $query,
            'sections' => $sections,
            'total' => array_sum(array_column($sections, 'count')),
            'no_results' => empty(array_filter($sections, fn($s) => $s['type'] !== 'browse_categories')),
        ];

        if (!empty($suggestions)) {
            $responseData['suggestions'] = $suggestions;
        }

        // Track search query to platform (fire and forget)
        $this->trackSearchQuery($query, $responseData['total']);

        return $result->setData($responseData);
    }
}
