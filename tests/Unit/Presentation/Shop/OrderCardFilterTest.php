<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Shop;

use App\Presentation\Shop\OrderCardFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrderCardFilterTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAFilterKeepsOnlyTheStatusesItNamesCases')]
    public function aFilterKeepsOnlyTheStatusesItNames(OrderCardFilter $filter, string $status, bool $isKept): void
    {
        $this->assertSame($isKept, $filter->accepts(['status' => $status]));
    }

    public static function provideAFilterKeepsOnlyTheStatusesItNamesCases(): iterable
    {
        yield 'pending keeps a submitted order' => [OrderCardFilter::Pending, 'pending', true];

        yield 'pending drops nothing submitted' => [OrderCardFilter::Pending, 'missing', false];

        yield 'pending drops a validated order' => [OrderCardFilter::Pending, 'validated', false];

        yield 'pending drops a past turn nobody ordered on' => [OrderCardFilter::Pending, 'empty', false];

        yield 'missing keeps nothing submitted' => [OrderCardFilter::Missing, 'missing', true];

        yield 'missing drops a submitted order' => [OrderCardFilter::Missing, 'pending', false];

        yield 'missing drops a validated order' => [OrderCardFilter::Missing, 'validated', false];

        yield 'missing drops a past turn nobody ordered on' => [OrderCardFilter::Missing, 'empty', false];

        yield 'valid keeps a validated order' => [OrderCardFilter::Validated, 'validated', true];

        yield 'valid drops a submitted order' => [OrderCardFilter::Validated, 'pending', false];

        yield 'valid drops nothing submitted' => [OrderCardFilter::Validated, 'missing', false];

        yield 'valid drops a past turn nobody ordered on' => [OrderCardFilter::Validated, 'empty', false];

        yield 'all keeps a submitted order' => [OrderCardFilter::All, 'pending', true];

        yield 'all keeps nothing submitted' => [OrderCardFilter::All, 'missing', true];

        yield 'all keeps a validated order' => [OrderCardFilter::All, 'validated', true];

        yield 'all keeps a past turn nobody ordered on' => [OrderCardFilter::All, 'empty', true];
    }

    #[Test]
    #[DataProvider('provideEveryFilterOffersAChipLabelCases')]
    public function everyFilterOffersAChipLabel(OrderCardFilter $filter): void
    {
        $this->assertNotSame('', $filter->label());
    }

    public static function provideEveryFilterOffersAChipLabelCases(): iterable
    {
        foreach (OrderCardFilter::cases() as $filter) {
            yield $filter->value => [$filter];
        }
    }
}
