<?php

declare(strict_types=1);

namespace Devexa\FormProtection\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ChallengeType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'delay', 'label' => __('Delay Submit (3 seconds)')],
            ['value' => 'checkbox', 'label' => __('Confirm Checkbox')],
            ['value' => 'math', 'label' => __('Simple Math Question')],
        ];
    }
}
