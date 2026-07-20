<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Player;
use App\Game\Stat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(name: 'molecules:StatPicker', template: 'molecules/StatPicker.html.twig')]
final class StatPicker
{
    use DefaultActionTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp]
    public Stat $stat; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(writable: true, updateFromParent: true)]
    public int $value; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HubInterface $hub,
    ) {}

    public function mount(Player $player, string $stat): void
    {
        $this->player = $player;
        $this->stat = Stat::from($stat);
        $this->value = $this->stat->read($player);
    }

    #[LiveAction]
    public function save(): void
    {
        $current = $this->stat->read($this->player);

        if ($current === $this->value) {
            return;
        }

        $this->stat->write($this->player, $this->value);
        $this->value = $this->stat->read($this->player);

        $this->entityManager->flush();
        $this->publish('player-updated');
    }

    private function publish(string $event): void
    {
        $this->hub->publish(new Update(
            'empires/game/'.$this->player->game->id,
            sprintf('{"event":"%s"}', $event),
        ));
    }
}
