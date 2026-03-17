<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Api;

interface RecommendationEngineInterface
{
    /**
     * Get product recommendations for a visitor/customer
     *
     * @return int[] Product IDs
     */
    public function getRecommendations(string $context, ?int $productId = null, ?string $visitorId = null, int $limit = 8): array;
}
