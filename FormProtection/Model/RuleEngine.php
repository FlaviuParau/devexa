<?php

declare(strict_types=1);

namespace Devexa\FormProtection\Model;

use Devexa\FormProtection\Api\RuleEngineInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class RuleEngine implements RuleEngineInterface
{
    private const CONFIG_PATH_PREFIX = 'devexa_form_protection/rules/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function applyRules(array $behaviorData, string $ip, ?string $country = null): array
    {
        $score = 0;
        $reasons = [];

        // Rule: Blocked countries
        if ($country !== null) {
            $blockedCountries = $this->getBlockedCountries();
            if (in_array(strtoupper($country), $blockedCountries, true)) {
                $score += 50;
                $reasons[] = 'country_blocked';
            }
        }

        // Rule: Blocked IPs
        $blockedIps = $this->getBlockedIps();
        if ($this->isIpBlocked($ip, $blockedIps)) {
            $score += 40;
            $reasons[] = 'ip_blocked';
        }

        // Rule: Typing speed too fast (bot-like)
        $typingThreshold = (int) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'typing_speed_threshold',
            ScopeInterface::SCOPE_STORE
        );
        if (isset($behaviorData['avg_typing_speed_ms']) && $behaviorData['avg_typing_speed_ms'] < $typingThreshold) {
            $score += 20;
            $reasons[] = 'typing_too_fast';
        }

        // Rule: Time on page too short
        $minTimeOnPage = (int) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'min_time_on_page',
            ScopeInterface::SCOPE_STORE
        );
        if (isset($behaviorData['time_on_page_seconds']) && $behaviorData['time_on_page_seconds'] < $minTimeOnPage) {
            $score += 15;
            $reasons[] = 'time_on_page_too_short';
        }

        // Rule: No mouse movement
        $requireMouseMovement = $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_PREFIX . 'require_mouse_movement',
            ScopeInterface::SCOPE_STORE
        );
        if ($requireMouseMovement && isset($behaviorData['mouse_movements']) && $behaviorData['mouse_movements'] < 3) {
            $score += 30;
            $reasons[] = 'no_mouse_movement';
        }

        // Rule: Honeypot triggered (definite bot — hidden fields were filled)
        if (!empty($behaviorData['honeypot_triggered'])) {
            $score = 100;
            $reasons[] = 'honeypot_triggered';
        }

        // Rule: Paste detected in fields
        if (!empty($behaviorData['paste_detected'])) {
            $score += 10;
            $reasons[] = 'paste_detected';
        }

        // Rule: No focus events (bot filling form without interaction)
        if (isset($behaviorData['focus_events']) && $behaviorData['focus_events'] === 0) {
            $score += 25;
            $reasons[] = 'no_focus_events';
        }

        return ['score' => min($score, 100), 'reasons' => $reasons];
    }

    private function getBlockedCountries(): array
    {
        $value = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'blocked_countries',
            ScopeInterface::SCOPE_STORE
        );

        if (empty($value)) {
            return [];
        }

        return array_map('trim', array_map('strtoupper', explode(',', $value)));
    }

    private function getBlockedIps(): array
    {
        $value = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . 'blocked_ips',
            ScopeInterface::SCOPE_STORE
        );

        if (empty($value)) {
            return [];
        }

        return array_map('trim', explode("\n", $value));
    }

    private function isIpBlocked(string $ip, array $blockedIps): bool
    {
        foreach ($blockedIps as $blocked) {
            $blocked = trim($blocked);
            if (empty($blocked)) {
                continue;
            }

            // Exact match
            if ($ip === $blocked) {
                return true;
            }

            // CIDR match
            if (str_contains($blocked, '/') && $this->ipInCidr($ip, $blocked)) {
                return true;
            }

            // Wildcard match (e.g., 192.168.*)
            if (str_contains($blocked, '*')) {
                $pattern = '/^' . str_replace(['.', '*'], ['\.', '\d+'], $blocked) . '$/';
                if (preg_match($pattern, $ip)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
