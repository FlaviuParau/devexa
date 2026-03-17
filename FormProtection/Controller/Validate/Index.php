<?php

declare(strict_types=1);

namespace Devexa\FormProtection\Controller\Validate;

use Devexa\FormProtection\Api\RiskScorerInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly RiskScorerInterface $riskScorer,
        private readonly RemoteAddress $remoteAddress,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $json
    ) {
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->scopeConfig->isSetFlag(
            'devexa_form_protection/general/enabled',
            ScopeInterface::SCOPE_STORE
        )) {
            return $result->setData(['action' => 'allow', 'score' => 0]);
        }

        try {
            $body = $this->json->unserialize($this->request->getContent());
            $behaviorData = $body['behavior'] ?? [];
            $country = $body['country'] ?? null;
            $ip = $this->remoteAddress->getRemoteAddress() ?: '0.0.0.0';

            $evaluation = $this->riskScorer->evaluate($behaviorData, $ip, $country);

            return $result->setData([
                'action' => $evaluation['action'],
                'score' => $evaluation['score'],
                'challenge_type' => $this->scopeConfig->getValue(
                    'devexa_form_protection/appearance/challenge_type',
                    ScopeInterface::SCOPE_STORE
                ),
                'block_message' => $evaluation['action'] === 'block'
                    ? (string) $this->scopeConfig->getValue(
                        'devexa_form_protection/appearance/block_message',
                        ScopeInterface::SCOPE_STORE
                    )
                    : null,
            ]);
        } catch (\Exception $e) {
            return $result->setData(['action' => 'allow', 'score' => 0]);
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
