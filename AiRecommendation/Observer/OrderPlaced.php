<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Devexa\AiRecommendation\Api\EventTrackerInterface;
use Psr\Log\LoggerInterface;

class OrderPlaced implements ObserverInterface
{
    public function __construct(
        private readonly EventTrackerInterface $eventTracker,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            /** @var \Magento\Sales\Model\Order $order */
            $order = $observer->getEvent()->getOrder();
            if (!$order) {
                return;
            }

            $productIds = [];
            foreach ($order->getAllVisibleItems() as $item) {
                $productIds[] = (string) $item->getProductId();
            }

            if (empty($productIds)) {
                return;
            }

            $this->eventTracker->track('purchase', [
                'product_ids' => $productIds,
                'order_id' => $order->getIncrementId(),
                'total' => (float) $order->getGrandTotal(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Devexa AiRecommendation OrderPlaced observer error: ' . $e->getMessage());
        }
    }
}
