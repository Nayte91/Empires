<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Ruleset;

use App\Rules\Ruleset\AdvanceEffect;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one place the effect→advance bindings are asserted. The calculators' own tests state a
 * behaviour and hand the player a plain advance key, so moving an effect onto another advance in
 * config/game/advances.yaml fails here — once — rather than in nine files at once.
 */
final class AdvanceEffectRegistryTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEachEffectIsGrantedByExactlyOneAdvanceCases')]
    public function eachEffectIsGrantedByExactlyOneAdvance(AdvanceEffect $effect, string $advanceKey): void
    {
        $this->assertSame([$advanceKey], GameConfig::advanceEffects()->keysGranting($effect));
    }

    /** @return iterable<string, array{AdvanceEffect, string}> */
    public static function provideEachEffectIsGrantedByExactlyOneAdvanceCases(): iterable
    {
        yield 'moving last' => [AdvanceEffect::MovesLast, 'military'];

        yield 'immunity to the tax revolt' => [AdvanceEffect::TaxRevoltImmunity, 'democracy'];

        yield 'choosing the tax rate' => [AdvanceEffect::TaxRateChoice, 'coinage'];

        yield 'raising the tax rate' => [AdvanceEffect::TaxRateRaise, 'monarchy'];

        yield 'the city build rebate' => [AdvanceEffect::CityBuildRebate, 'architecture'];

        yield 'the extra hand card' => [AdvanceEffect::ExtraHandCard, 'roadbuilding'];
    }

    /** An effect the code knows about but no advance declares would silently never fire. */
    #[Test]
    public function everyEffectTheCodeKnowsIsDeclaredByAnAdvance(): void
    {
        $registry = GameConfig::advanceEffects();

        foreach (AdvanceEffect::cases() as $effect) {
            $this->assertNotSame([], $registry->keysGranting($effect), $effect->value);
        }
    }

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
