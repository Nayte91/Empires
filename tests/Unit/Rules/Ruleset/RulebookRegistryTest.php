<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Ruleset;

use App\Rules\Ruleset\RulebookRegistry;
use App\State\Region;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RulebookRegistryTest extends TestCase
{
    private RulebookRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new RulebookRegistry(\dirname(__DIR__, 4).'/config/game/rulebooks.yaml');
    }

    #[Test]
    #[DataProvider('provideEveryDeclaredBookIsAnAbsoluteLinkToAPdfCases')]
    public function everyDeclaredBookIsAnAbsoluteLinkToAPdf(string $key): void
    {
        $book = 'scenarios' === $key ? $this->registry->scenarios() : $this->registry->forRegion(Region::from($key));

        $this->assertNotSame('', $book->label);
        $this->assertNotSame('', $book->caption);
        $this->assertStringStartsWith('https://', $book->url);
        $this->assertStringEndsWith('.pdf', $book->url);
    }

    /** @return iterable<string, array{string}> */
    public static function provideEveryDeclaredBookIsAnAbsoluteLinkToAPdfCases(): iterable
    {
        yield 'the western block' => ['west'];
        yield 'the eastern block' => ['east'];
        yield 'the booklet a combined game is set up from' => ['scenarios'];
    }

    #[Test]
    public function eachBlockPointsAtItsOwnRulebook(): void
    {
        $this->assertNotSame(
            $this->registry->forRegion(Region::West)->url,
            $this->registry->forRegion(Region::East)->url,
        );
    }

    #[Test]
    public function anUndeclaredBookIsRefusedByName(): void
    {
        $registry = new RulebookRegistry(\dirname(__DIR__, 4).'/config/game/ast.yaml');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/west/');

        $registry->forRegion(Region::West);
    }
}
