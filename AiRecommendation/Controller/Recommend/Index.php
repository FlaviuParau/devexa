<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Controller\Recommend;

use Devexa\AiRecommendation\Api\RecommendationEngineInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Framework\Serialize\Serializer\Json;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly RecommendationEngineInterface $engine,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ImageHelper $imageHelper,
        private readonly PricingHelper $pricingHelper,
        private readonly Json $json
    ) {
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $body = $this->json->unserialize($this->request->getContent());
            $context = $body['context'] ?? 'product';
            $productId = isset($body['product_id']) ? (int) $body['product_id'] : null;
            $visitorId = $body['visitor_id'] ?? null;
            $limit = min((int) ($body['limit'] ?? 8), 50);

            // Get product IDs from the AI engine (platform API)
            $productIds = $this->engine->getRecommendations($context, $productId, $visitorId, $limit);

            if (empty($productIds)) {
                return $result->setData(['products' => []]);
            }

            // Convert string IDs to integers for Magento filter
            $intProductIds = array_map('intval', $productIds);

            // Load actual products from Magento catalog
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('entity_id', $intProductIds, 'in')
                ->addFilter('status', 1)
                ->setPageSize($limit)
                ->create();

            $productList = $this->productRepository->getList($searchCriteria)->getItems();

            $products = [];
            foreach ($productList as $product) {
                $products[] = [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'url' => $product->getProductUrl(),
                    'image' => $this->imageHelper->init($product, 'category_page_grid')
                        ->setImageFile($product->getSmallImage())
                        ->getUrl(),
                    'price' => $this->pricingHelper->currency($product->getFinalPrice(), true, false),
                ];
            }

            return $result->setData(['products' => $products]);
        } catch (\Exception $e) {
            return $result->setData(['products' => [], 'error' => $e->getMessage()]);
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
