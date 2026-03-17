<?php

declare(strict_types=1);

namespace Devexa\FormProtection\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Mode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'analyze', 'label' => __('Local Analysis Only')],
            ['value' => 'api', 'label' => __('API Validation (SaaS)')],
        ];
    }
}
