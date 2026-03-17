<?php

declare(strict_types=1);

namespace Devexa\Core\Controller\Adminhtml\License;

use Devexa\Core\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class Sync extends Action
{
    public function __construct(
        Context $context,
        private readonly LicenseValidator $licenseValidator,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            // Force re-validation (clears cache and calls platform)
            $license = $this->licenseValidator->revalidate();

            if ($license && $license['valid']) {
                return $result->setData([
                    'success' => true,
                    'services' => $license['services'] ?? [],
                    'settings' => $license['settings'] ?? [],
                    'plan' => $license['plan'] ?? 'free',
                    'checked_at' => $license['checked_at'] ?? '',
                ]);
            }

            return $result->setData([
                'success' => false,
                'error' => $license['error'] ?? 'License validation failed',
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Magento_Config::config');
    }
}
