<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Algorithm implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'collaborative', 'label' => __('Collaborative Filtering')],
            ['value' => 'similarity', 'label' => __('Product Similarity')],
            ['value' => 'frequently_bought', 'label' => __('Frequently Bought Together')],
            ['value' => 'hybrid', 'label' => __('Hybrid (All Combined)')],
        ];
    }
}
