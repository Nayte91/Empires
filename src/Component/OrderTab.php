<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Order;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Shop\OrderStatus;
use App\Shop\Service\OrderValidator;
use App\Shop\Service\PriceCalculator;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'molecules/orderTab.html.twig')]
final class OrderTab
{
    use DefaultActionTrait;

    #[LiveProp]
    public Order $order; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(writable: true)]
    public int $money = 0;

    public ?string $error = null;

    public function __construct(
        private readonly OrderValidator $orderValidator,
        private readonly AdvanceCatalog $advanceCatalog,
        private readonly PriceCalculator $priceCalculator,
    ) {}

    #[LiveAction]
    public function validate(): void
    {
        try {
            $this->orderValidator->validate($this->order, $this->money);
            $this->error = null;
        } catch (\DomainException $exception) {
            $this->error = $exception->getMessage();
        }
    }

    public function isValidated(): bool
    {
        return OrderStatus::Validated === $this->order->status;
    }

    /**
     * Net prices at the current instant: frozen lines once validated, otherwise
     * recalculated against the player's currently owned advances (prices may
     * move as the player picks up credit-granting advances in other tabs).
     *
     * @return list<array{slug: string, netCost: int}>
     */
    public function getPricedLines(): array
    {
        if ($this->isValidated()) {
            /** @var list<array{key: string, netCost: int}> $frozenLines */
            $frozenLines = $this->order->lines;

            return array_map(
                static fn (array $line): array => ['slug' => $line['key'], 'netCost' => $line['netCost']],
                $frozenLines,
            );
        }

        /** @var list<string> $slugs */
        $slugs = $this->order->lines;

        /** @var list<Advance> $ownedAdvances */
        $ownedAdvances = $this->advanceCatalog->getAdvancesByNames($this->order->player->advances);

        return array_map(
            function (string $slug) use ($ownedAdvances): array {
                $advance = $this->advanceCatalog->getAdvanceByName($slug);
                $netCost = $advance instanceof Advance ? $this->priceCalculator->netCost($advance, $ownedAdvances) : 0;

                return ['slug' => $slug, 'netCost' => $netCost];
            },
            $slugs,
        );
    }

    public function getTotal(): int
    {
        return array_sum(array_column($this->getPricedLines(), 'netCost'));
    }
}
