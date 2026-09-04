<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Ruleset;

use App\Rules\Ruleset\AdvanceEffect;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdvanceEffectRegistryTest extends TestCase
{
    #[Test]
    public function ownershipIsAnsweredFromTheAdvancesThePlayerActuallyHolds(): void
    {
        $registry = GameConfig::advanceEffects();

        $this->assertTrue($registry->grants(['pottery', 'coinage'], AdvanceEffect::TaxRateChoice));
        $this->assertSame(['coinage'], $registry->owned(['pottery', 'coinage'], AdvanceEffect::TaxRateChoice));

        $this->assertFalse($registry->grants(['pottery', 'monarchy'], AdvanceEffect::TaxRateChoice));
        $this->assertSame([], $registry->owned([], AdvanceEffect::TaxRateChoice));
    }
}
