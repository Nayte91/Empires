<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\ScoreCalculator;
use App\State\Player;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Userforged\ShopEngine\Dto\Product;

#[AsLiveComponent(template: 'organisms/playerBoard.html.twig')]
final class PlayerBoard
{
    use DefaultActionTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly AdvanceRegistry $advanceRegistry,
        private readonly ScoreCalculator $scoreCalculator,
    ) {}

    /** @return list<Advance> */
    public function getOwnedAdvances(): array
    {
        return array_values($this->advanceRegistry->getAdvancesByNames($this->player->advances));
    }

    /** What the owned advances alone are worth, for the Advances heading. */
    public function getAdvancePoints(): int
    {
        return $this->scoreCalculator->advancePointsFor($this->getOwnedAdvances());
    }

    /**
     * Owned advances paired with a read-only {@see Product} DTO, for reuse of the productCard molecule.
     *
     * @return list<array{advance: Advance, product: Product}>
     */
    public function getOwnedRows(): array
    {
        return array_map(
            static fn (Advance $advance): array => [
                'advance' => $advance,
                'product' => new Product(
                    key: $advance->key,
                    netCost: $advance->cost,
                    owned: true,
                    inCart: false,
                ),
            ],
            $this->getOwnedAdvances(),
        );
    }
}
