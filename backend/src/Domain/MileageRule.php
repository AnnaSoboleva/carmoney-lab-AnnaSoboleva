<?php

declare(strict_types=1);

namespace CarMoneyLab\Domain;

/**
 * Правило по пробегу: понижает решение approve до review,
 * если пробег заявки больше порога из конфига.
 *
 * Решения review и reject не меняются. При даунгрейде возвращается
 * код причины, иначе — null.
 */
final class MileageRule
{
    public const REASON_OVER_THRESHOLD = 'mileage_over_threshold';

    /** @param array{downgrade_threshold_km:int} $config */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * @return array{decision:string,review_reason:?string}
     */
    public function apply(string $decision, int $mileage): array
    {
        if ($decision === DecisionEngine::APPROVE && $mileage > $this->config['downgrade_threshold_km']) {
            return ['decision' => DecisionEngine::REVIEW, 'review_reason' => self::REASON_OVER_THRESHOLD];
        }

        return ['decision' => $decision, 'review_reason' => null];
    }
}
