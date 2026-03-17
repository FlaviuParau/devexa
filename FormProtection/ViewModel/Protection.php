<?php

declare(strict_types=1);

namespace Devexa\FormProtection\ViewModel;

use Devexa\Core\Model\LicenseValidator;
use Devexa\Core\Model\PlatformConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;

class Protection implements ArgumentInterface
{
    private const CONFIG_PATH_PREFIX = 'devexa_form_protection/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LicenseValidator $licenseValidator,
        private readonly PlatformConfig $platformConfig
    ) {
    }

    public function isEnabled(): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::CONFIG_PATH_PREFIX . 'general/enabled', ScopeInterface::SCOPE_STORE)) {
            return false;
        }

        return $this->licenseValidator->isServiceActive('form_protection');
    }

    public function getProtectedFormSelectors(): array
    {
        $selectors = [];

        $formMap = [
            'protect_login' => 'form.form-login, #login-form',
            'protect_register' => 'form.form-create-account, #form-validate',
            'protect_contact' => '#contact-form',
            'protect_checkout' => '#co-shipping-form, #co-payment-form',
            'protect_newsletter' => '#newsletter-validate-detail',
            'protect_review' => '#review-form',
        ];

        foreach ($formMap as $configKey => $selector) {
            if ($this->scopeConfig->isSetFlag(
                self::CONFIG_PATH_PREFIX . 'forms/' . $configKey,
                ScopeInterface::SCOPE_STORE
            )) {
                $selectors[] = $selector;
            }
        }

        // Custom form selectors
        $custom = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'forms/custom_form_selectors',
            ScopeInterface::SCOPE_STORE
        );

        if (!empty($custom)) {
            $selectors = array_merge($selectors, array_map('trim', explode(',', $custom)));
        }

        return $selectors;
    }

    public function getApiKey(): string
    {
        return $this->platformConfig->getApiKey();
    }

    public function getMode(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'general/mode',
            ScopeInterface::SCOPE_STORE
        ) ?: 'analyze';
    }

    public function getScriptUrl(): string
    {
        return $this->platformConfig->getBrowserBaseUrl() . '/js/smartshield.js';
    }

    public function getJsConfig(): array
    {
        return [
            'validateUrl' => '', // Set in template
            'formSelectors' => $this->getProtectedFormSelectors(),
            'typingThreshold' => (int) $this->scopeConfig->getValue(
                self::CONFIG_PATH_PREFIX . 'rules/typing_speed_threshold',
                ScopeInterface::SCOPE_STORE
            ) ?: 20,
            'minTimeOnPage' => (int) $this->scopeConfig->getValue(
                self::CONFIG_PATH_PREFIX . 'rules/min_time_on_page',
                ScopeInterface::SCOPE_STORE
            ) ?: 2,
            'showBadge' => $this->scopeConfig->isSetFlag(
                self::CONFIG_PATH_PREFIX . 'appearance/show_badge',
                ScopeInterface::SCOPE_STORE
            ),
            'badgeText' => (string) __($this->scopeConfig->getValue(
                self::CONFIG_PATH_PREFIX . 'appearance/badge_text',
                ScopeInterface::SCOPE_STORE
            ) ?: 'Protected by SmartShield'),
            'blockedCountries' => array_filter(array_map('trim', explode(',', (string) $this->scopeConfig->getValue(
                self::CONFIG_PATH_PREFIX . 'rules/blocked_countries',
                ScopeInterface::SCOPE_STORE
            ) ?: ''))),
            'excludeSelectors' => $this->getExcludeFormSelectors(),
        ];
    }

    public function getExcludeFormSelectors(): array
    {
        $value = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'forms/exclude_form_selectors',
            ScopeInterface::SCOPE_STORE
        );

        if (empty($value)) {
            return [];
        }

        return array_filter(array_map('trim', explode(',', $value)));
    }
}
