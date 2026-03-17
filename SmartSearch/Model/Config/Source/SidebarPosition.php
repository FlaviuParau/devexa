<?php

declare(strict_types=1);

namespace Devexa\SmartSearch\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SidebarPosition implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'left', 'label' => __('Left sidebar')],
            ['value' => 'right', 'label' => __('Right sidebar')],
            ['value' => 'top', 'label' => __('Above products')],
        ];
    }
}
