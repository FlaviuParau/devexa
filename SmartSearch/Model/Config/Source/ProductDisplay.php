<?php

declare(strict_types=1);

namespace Devexa\SmartSearch\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ProductDisplay implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'list', 'label' => __('List (vertical)')],
            ['value' => 'grid', 'label' => __('Grid (columns)')],
        ];
    }
}
