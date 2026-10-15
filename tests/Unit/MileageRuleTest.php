<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Domain\DecisionEngine;
use CarMoneyLab\Domain\MileageRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MileageRuleTest extends TestCase
{
    private MileageRule $rule;

    protected function setUp(): void
    {
        $config = (require __DIR__ . '/../../backend/config/rules.php')['mileage'];
        $this->rule = new MileageRule($config);
    }

    #[DataProvider('mileageCases')]
    public function testAppliesRuleToDecision(
        int $mileage,
        string $decision,
        string $expectedDecision,
        ?string $expectedReason,
    ): void {
        $result = $this->rule->apply($decision, $mileage);

        self::assertSame($expectedDecision, $result['decision']);
        self::assertSame($expectedReason, $result['review_reason']);
    }

    /** @return array<string,array{int,string,string,?string}> */
    public static function mileageCases(): array
    {
        return [
            '399999 км, approve — правило не сработало' => [
                399999, DecisionEngine::APPROVE, DecisionEngine::APPROVE, null,
            ],
            '400000 км, approve — граница включительная' => [
                400000, DecisionEngine::APPROVE, DecisionEngine::APPROVE, null,
            ],
            '400001 км, approve — даунгрейд до review' => [
                400001, DecisionEngine::APPROVE, DecisionEngine::REVIEW, MileageRule::REASON_OVER_THRESHOLD,
            ],
            '400001 км, reject — reject не меняется' => [
                400001, DecisionEngine::REJECT, DecisionEngine::REJECT, null,
            ],
            '400001 км, review — review не меняется' => [
                400001, DecisionEngine::REVIEW, DecisionEngine::REVIEW, null,
            ],
        ];
    }

    public function testThresholdComesFromConfig(): void
    {
        $rule = new MileageRule(['downgrade_threshold_km' => 150000]);

        $downgraded = $rule->apply(DecisionEngine::APPROVE, 150001);
        $kept = $rule->apply(DecisionEngine::APPROVE, 150000);

        self::assertSame(DecisionEngine::REVIEW, $downgraded['decision']);
        self::assertSame(MileageRule::REASON_OVER_THRESHOLD, $downgraded['review_reason']);
        self::assertSame(DecisionEngine::APPROVE, $kept['decision']);
        self::assertNull($kept['review_reason']);
    }
}
