<?php

declare(strict_types=1);

namespace Devexa\Core\Block\Adminhtml\System\Config;

use Devexa\Core\Model\LicenseValidator;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class LicenseStatus extends Field
{
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        private readonly LicenseValidator $licenseValidator,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $license = $this->licenseValidator->validate();

        if (!$license || !$license['valid']) {
            $error = $license['error'] ?? 'unknown';
            if ($error === 'no_key') {
                return '<span style="color:#f59e0b;font-weight:600;">No license key configured</span>';
            }
            return '<span style="color:#dc2626;font-weight:600;">Invalid License</span>'
                . '<br><small style="color:#999;">Error: ' . htmlspecialchars($error) . '</small>';
        }

        $services = $license['services'] ?? [];
        $plan = $license['plan'] ?? 'free';
        $checkedAt = $license['checked_at'] ?? '';
        $settings = $license['settings'] ?? [];

        $serviceLabels = [
            'recommendations' => 'AI Recommendations',
            'form_protection' => 'SmartShield',
            'smart_search' => 'Smart Search',
        ];

        $html = '<div style="margin-bottom:12px;">';
        $html .= '<span style="color:#16a34a;font-weight:600;">&#10003; Valid</span>';
        $html .= ' &mdash; <span style="text-transform:capitalize;font-weight:500;">' . htmlspecialchars($plan) . '</span> plan';
        $html .= '</div>';

        if (!empty($services)) {
            $html .= '<div style="margin-bottom:8px;"><small style="color:#666;">Active services: ';
            $labels = [];
            foreach ($services as $s) {
                $labels[] = '<strong>' . ($serviceLabels[$s] ?? $s) . '</strong>';
            }
            $html .= implode(', ', $labels);
            $html .= '</small></div>';
        }

        // Show synced settings
        if (!empty($settings)) {
            $html .= '<div style="margin-bottom:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:8px 12px;">';
            $html .= '<small style="color:#666;display:block;margin-bottom:4px;font-weight:600;">Platform Settings (synced):</small>';
            if (!empty($settings['recommendations'])) {
                $rec = $settings['recommendations'];
                if (!empty($rec['algorithm'])) {
                    $html .= '<small style="color:#888;">Algorithm: <strong>' . htmlspecialchars($rec['algorithm']) . '</strong></small><br>';
                }
                if (!empty($rec['max_products'])) {
                    $html .= '<small style="color:#888;">Max products: <strong>' . (int)$rec['max_products'] . '</strong></small><br>';
                }
            }
            if (!empty($settings['form_protection'])) {
                $fp = $settings['form_protection'];
                $html .= '<small style="color:#888;">Block threshold: <strong>' . (int)($fp['block_threshold'] ?? 80) . '</strong></small><br>';
                $html .= '<small style="color:#888;">Challenge threshold: <strong>' . (int)($fp['challenge_threshold'] ?? 50) . '</strong></small>';
            }
            $html .= '</div>';
        }

        if ($checkedAt) {
            $html .= '<small style="color:#999;">Last synced: ' . htmlspecialchars($checkedAt) . '</small><br>';
        }

        // Sync button
        $syncUrl = $this->getUrl('devexa_core/license/sync');
        $html .= '<div style="margin-top:10px;">';
        $html .= '<button type="button" onclick="devexaSyncLicense(this)" '
            . 'style="background:#7c3aed;color:#fff;border:none;padding:6px 16px;border-radius:6px;font-size:13px;font-weight:500;cursor:pointer;">'
            . 'Sync Settings Now</button>';
        $html .= '<span id="devexa-sync-status" style="margin-left:10px;font-size:12px;color:#999;"></span>';
        $html .= '</div>';

        $html .= '<script>
            function devexaSyncLicense(btn) {
                btn.disabled = true;
                btn.style.opacity = "0.6";
                btn.textContent = "Syncing...";
                document.getElementById("devexa-sync-status").textContent = "";
                fetch("' . $syncUrl . '", { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" } })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        btn.disabled = false;
                        btn.style.opacity = "1";
                        btn.textContent = "Sync Settings Now";
                        if (data.success) {
                            document.getElementById("devexa-sync-status").innerHTML = "<span style=\"color:#16a34a;\">&#10003; Synced! " + (data.services || []).length + " services active. Reloading...</span>";
                            setTimeout(function() { location.reload(); }, 1500);
                        } else {
                            document.getElementById("devexa-sync-status").innerHTML = "<span style=\"color:#dc2626;\">&#10007; " + (data.error || "Sync failed") + "</span>";
                        }
                    })
                    .catch(function() {
                        btn.disabled = false;
                        btn.style.opacity = "1";
                        btn.textContent = "Sync Settings Now";
                        document.getElementById("devexa-sync-status").innerHTML = "<span style=\"color:#dc2626;\">&#10007; Network error</span>";
                    });
            }
        </script>';

        return $html;
    }
}
