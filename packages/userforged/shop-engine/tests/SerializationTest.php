<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Userforged\ShopEngine\Dto\LineIntent;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\PromotionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The persistence contract, asserted in one file because it is one rule
 * implemented three times: toArray() drops every field still holding its
 * default, and fromArray() puts it back. A host stores these arrays as JSON
 * (Doctrine\OrderLinesType is the shipped adapter), so an asymmetry between
 * the two directions does not fail loudly — it silently returns a different
 * order than the one that was written.
 */
final class SerializationTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEveryDtoSurvivesAToArrayFromArrayRoundTripCases')]
    public function everyDtoSurvivesAToArrayFromArrayRoundTrip(LineIntent|OrderLine|AppliedPromotion $dto): void
    {
        $restored = $dto::fromArray($dto->toArray());

        $this->assertEquals($dto, $restored);
    }

    public static function provideEveryDtoSurvivesAToArrayFromArrayRoundTripCases(): iterable
    {
        yield 'a line intent carrying both a gift and an allocation' => [new LineIntent('anatomy', gift: 'astronavigation', allocation: ['craft' => 10])];

        yield 'a line intent holding nothing but its key' => [new LineIntent('pottery')];

        yield 'an applied promotion carrying an amount and an allocation' => [new AppliedPromotion(PromotionType::Option, 'monument', 40, ['craft' => 10, 'science' => 10])];

        yield 'an applied promotion with a zero amount and no allocation' => [new AppliedPromotion(PromotionType::Gift, 'anatomy')];

        yield 'an order line carrying a nested promotion' => [new OrderLine('astronavigation', 0, new AppliedPromotion(PromotionType::Gift, 'anatomy', 80))];

        yield 'an order line with no promotion at all' => [new OrderLine('pottery', 60)];
    }

    /**
     * The stored shape is half the contract: a host may index or query these
     * JSON documents, so which keys are absent is as load-bearing as which
     * values round-trip.
     *
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('provideToArrayOmitsEveryFieldStillAtItsDefaultCases')]
    public function toArrayOmitsEveryFieldStillAtItsDefault(LineIntent|OrderLine|AppliedPromotion $dto, array $expected): void
    {
        $this->assertSame($expected, $dto->toArray());
    }

    public static function provideToArrayOmitsEveryFieldStillAtItsDefaultCases(): iterable
    {
        yield 'a line intent drops a null gift and an empty allocation' => [
            new LineIntent('pottery'),
            ['key' => 'pottery'],
        ];

        yield 'an applied promotion drops a zero amount and an empty allocation' => [
            new AppliedPromotion(PromotionType::Gift, 'anatomy'),
            ['type' => 'gift', 'source' => 'anatomy'],
        ];

        yield 'an order line drops an absent promotion' => [
            new OrderLine('pottery', 60),
            ['key' => 'pottery', 'netCost' => 60],
        ];
    }
}
