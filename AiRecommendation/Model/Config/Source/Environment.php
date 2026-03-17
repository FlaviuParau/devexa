<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Environment implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'production', 'label' => __('Production')],
            ['value' => 'test', 'label' => __('Test / Staging')],
            ['value' => 'development', 'label' => __('Development (Local)')],
        ];
    }
}
