<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Controller\Track;

use Devexa\AiRecommendation\Api\EventTrackerInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;

class Event implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly EventTrackerInterface $eventTracker,
        private readonly Json $json
    ) {
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $body = $this->json->unserialize($this->request->getContent());
            $eventType = $body['event'] ?? '';
            $data = $body['data'] ?? [];
            $visitorId = $body['visitor_id'] ?? null;

            if (empty($eventType)) {
                return $result->setData(['success' => false, 'message' => 'Missing event type']);
            }

            $tracked = $this->eventTracker->track($eventType, $data, $visitorId);

            return $result->setData(['success' => $tracked]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => 'Invalid request']);
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
