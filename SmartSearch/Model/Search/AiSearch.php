<?php

declare(strict_types=1);

namespace Devexa\SmartSearch\Model\Search;

use Devexa\Core\Model\PlatformConfig;
use Devexa\SmartSearch\Model\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class AiSearch
{
    public function __construct(
        private readonly Config $config,
        private readonly PlatformConfig $platformConfig,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ImageHelper $imageHelper,
        private readonly PricingHelper $pricingHelper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function search(string $query, int $limit = 4): array
    {
        if (!$this->config->isAiEnabled()) {
            return [];
        }

        $apiKey = $this->platformConfig->getApiKey();
        $endpoint = $this->platformConfig->getServiceEndpoint('v1/search');

        if (empty($apiKey)) {
            return [];
        }

        try {
            $skipSsl = $this->platformConfig->shouldSkipSslVerify();

            $curl = $this->curlFactory->create();
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, !$skipSsl);
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, $skipSsl ? 0 : 2);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Authorization', 'Bearer ' . $apiKey);
            $curl->setTimeout(2);
            $curl->post(rtrim($endpoint, '/') . '/suggest', $this->json->serialize([
                'query' => $query,
                'limit' => $limit,
            ]));

            if ($curl->getStatus() >= 200 && $curl->getStatus() < 300) {
                $response = $this->json->unserialize($curl->getBody());
                $productIds = $response['product_ids'] ?? [];

                if (empty($productIds)) {
                    return [];
                }

                return $this->loadProducts(array_map('intval', $productIds), $limit);
            }
        } catch (\Exception $e) {
            $this->logger->error('SmartSearch AI error: ' . $e->getMessage());
        }

        return [];
    }

    private function loadProducts(array $productIds, int $limit): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', $productIds, 'in')
            ->addFilter('status', 1)
            ->setPageSize($limit)
            ->create();

        $products = $this->productRepository->getList($searchCriteria)->getItems();
        $results = [];

        foreach ($products as $product) {
            $thumbUrl = '';
            $gridUrl = '';
            try {
                $thumbUrl = $this->imageHelper->init($product, 'product_thumbnail_image')
                    ->setImageFile($product->getSmallImage())
                    ->getUrl();
                $gridUrl = $this->imageHelper->init($product, 'category_page_grid')
                    ->setImageFile($product->getSmallImage())
                    ->getUrl();
            } catch (\Exception $e) {
                // placeholder
            }

            $results[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'sku' => $product->getSku(),
                'url' => $product->getProductUrl(),
                'image' => $gridUrl ?: $thumbUrl,
                'image_thumb' => $thumbUrl,
                'image_grid' => $gridUrl ?: $thumbUrl,
                'price' => $this->pricingHelper->currency($product->getFinalPrice(), true, false),
                'price_raw' => (float) $product->getFinalPrice(),
                'ai' => true,
            ];
        }

        return $results;
    }
}
