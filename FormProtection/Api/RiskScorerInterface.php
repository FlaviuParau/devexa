<?php

declare(strict_types=1);

namespace Devexa\FormProtection\Api;

interface RiskScorerInterface
{
    public const ACTION_ALLOW = 'allow';
    public const ACTION_CHALLENGE = 'challenge';
    public const ACTION_BLOCK = 'block';

    /**
     * Calculate risk score from behavior data
     *
     * @return array{score: int, action: string, reasons: string[]}
     */
    public function evaluate(array $behaviorData, string $ip, ?string $country = null): array;
}
