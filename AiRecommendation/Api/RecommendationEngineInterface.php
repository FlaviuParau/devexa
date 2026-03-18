<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Api;

interface RecommendationEngineInterface
{
    /**
     * Get product recommendations for a visitor/customer
     *
     * @param string $context Page context (product, cart, homepage, category)
     * @param int|null $productId Current product ID
     * @param string|null $visitorId Visitor identifier
     * @param int $limit Maximum number of recommendations
     * @param string $type Recommendation type (frequently_bought, upsell, crosssell, similar)
     * @return int[] Product IDs
     */
    public function getRecommendations(
        string $context,
        ?int $productId = null,
        ?string $visitorId = null,
        int $limit = 8,
        string $type = 'upsell'
    ): array;
}
