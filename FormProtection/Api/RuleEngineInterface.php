<?php

declare(strict_types=1);

namespace Devexa\FormProtection\Api;

interface RuleEngineInterface
{
    /**
     * Apply configured rules and return score adjustments
     *
     * @return array{score: int, reasons: string[]}
     */
    public function applyRules(array $behaviorData, string $ip, ?string $country = null): array;
}
