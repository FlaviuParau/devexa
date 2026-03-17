<?php

declare(strict_types=1);

namespace Devexa\SmartSearch\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ProductColumns implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '2', 'label' => __('2 per row')],
            ['value' => '3', 'label' => __('3 per row')],
            ['value' => '4', 'label' => __('4 per row')],
            ['value' => '5', 'label' => __('5 per row')],
        ];
    }
}
