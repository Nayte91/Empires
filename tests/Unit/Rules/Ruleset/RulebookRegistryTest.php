<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Ruleset;

use App\Rules\Ruleset\RulebookRegistry;
use App\State\Region;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RulebookRegistryTest extends TestCase
{
    #[Test]
    public function anUndeclaredBookIsRefusedByName(): void
    {
        $registry = new RulebookRegistry(\dirname(__DIR__, 4).'/config/game/ast.yaml');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/west/');

        $registry->forRegion(Region::West);
    }
}
