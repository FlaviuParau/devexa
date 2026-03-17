<?php

declare(strict_types=1);

namespace Devexa\SmartSearch\ViewModel;

use Devexa\Core\Model\LicenseValidator;
use Devexa\SmartSearch\Model\Config;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class SearchConfig implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly LicenseValidator $licenseValidator
    ) {
    }

    public function isEnabled(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        return $this->licenseValidator->isServiceActive('smart_search');
    }

    public function getJsConfig(): array
    {
        return [
            'minChars' => $this->config->getMinChars(),
            'debounceMs' => $this->config->getDebounceMs(),
            'highlightColor' => $this->config->getHighlightColor(),
            'showImage' => $this->config->showProductImage(),
            'showPrice' => $this->config->showProductPrice(),
            'showSku' => $this->config->showProductSku(),
            'productDisplay' => $this->config->getProductDisplay(),
            'productColumns' => $this->config->getProductColumns(),
            'sidebarPosition' => $this->config->getSidebarPosition(),
            'popupMaxWidth' => $this->config->getPopupMaxWidth(),
        ];
    }
}
