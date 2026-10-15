<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Domain\ApplicationValidator;
use CarMoneyLab\Domain\AssessmentService;
use CarMoneyLab\Domain\DecisionEngine;
use CarMoneyLab\Domain\LtvCalculator;
use CarMoneyLab\Domain\MileageRule;
use CarMoneyLab\Domain\VehicleAge;
use CarMoneyLab\Domain\VinValidator;
use PHPUnit\Framework\TestCase;

final class AssessmentServiceTest extends TestCase
{
    private AssessmentService $service;

    protected function setUp(): void
    {
        $rules = require __DIR__ . '/../../backend/config/rules.php';
        $age = new VehicleAge((int) date('Y'));

        $this->service = new AssessmentService(
            new ApplicationValidator($rules, new VinValidator($rules['vin']), $age),
            new LtvCalculator(),
            new DecisionEngine($rules['ltv']),
            new MileageRule($rules['mileage']),
            $age,
        );
    }

    /** @return array<string,mixed> */
    private function payload(int $amount, int $marketValue, int $mileage = 96000): array
    {
        return [
            'vin' => 'XTA21099998765432',
            'year' => (int) date('Y') - 4,
            'mileage' => $mileage,
            'market_value' => $marketValue,
            'requested_amount' => $amount,
            'term_months' => 24,
        ];
    }

    public function testApprovesLowLtvAndSetsLimitToRequestedAmount(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000));

        self::assertSame(50.0, $result['ltv']);
        self::assertSame(DecisionEngine::APPROVE, $result['decision']);
        self::assertSame(450000, $result['approved_limit']);
        self::assertSame(4, $result['vehicle_age']);
    }

    public function testSendsMiddleLtvToReviewWithZeroLimit(): void
    {
        $result = $this->service->assess($this->payload(675000, 900000));

        self::assertSame(75.0, $result['ltv']);
        self::assertSame(DecisionEngine::REVIEW, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testRejectsHighLtv(): void
    {
        $result = $this->service->assess($this->payload(855000, 900000));

        self::assertSame(95.0, $result['ltv']);
        self::assertSame(DecisionEngine::REJECT, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testDowngradesApproveToReviewWhenMileageOverThreshold(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000, 400001));

        self::assertSame(50.0, $result['ltv']);
        self::assertSame(DecisionEngine::REVIEW, $result['decision']);
        self::assertSame(MileageRule::REASON_OVER_THRESHOLD, $result['review_reason']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testKeepsApproveAtMileageThreshold(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000, 400000));

        self::assertSame(DecisionEngine::APPROVE, $result['decision']);
        self::assertNull($result['review_reason']);
        self::assertSame(450000, $result['approved_limit']);
    }

    public function testKeepsApproveBelowMileageThreshold(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000, 399999));

        self::assertSame(DecisionEngine::APPROVE, $result['decision']);
        self::assertNull($result['review_reason']);
    }

    public function testKeepsRejectWhenMileageOverThreshold(): void
    {
        $result = $this->service->assess($this->payload(855000, 900000, 400001));

        self::assertSame(DecisionEngine::REJECT, $result['decision']);
        self::assertNull($result['review_reason']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testKeepsReviewWhenMileageOverThreshold(): void
    {
        $result = $this->service->assess($this->payload(675000, 900000, 400001));

        self::assertSame(DecisionEngine::REVIEW, $result['decision']);
        self::assertNull($result['review_reason']);
        self::assertSame(0, $result['approved_limit']);
    }
}
