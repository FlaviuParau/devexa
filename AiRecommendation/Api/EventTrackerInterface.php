<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Api;

interface EventTrackerInterface
{
    public const EVENT_PRODUCT_VIEW = 'product_view';
    public const EVENT_ADD_TO_CART = 'add_to_cart';
    public const EVENT_PURCHASE = 'purchase';
    public const EVENT_SEARCH = 'search_query';
    public const EVENT_CATEGORY_VIEW = 'category_view';

    /**
     * Track a user behavior event
     */
    public function track(string $eventType, array $data, ?string $visitorId = null): bool;
}
