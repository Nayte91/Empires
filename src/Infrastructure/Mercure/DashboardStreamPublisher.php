<?php

declare(strict_types=1);

namespace App\Infrastructure\Mercure;

use App\State\Game;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

final readonly class DashboardStreamPublisher
{
    public function __construct(
        private HubInterface $hub,
        private GameTopics $topics,
        private Environment $twig,
    ) {}

    public function publish(Game $game): void
    {
        $this->hub->publish(new Update(
            $this->topics->roster($game->id),
            $this->twig->render('molecules/roster.stream.html.twig', ['game' => $game]),
        ));

        $this->hub->publish(new Update(
            $this->topics->ast($game->id),
            $this->twig->render('molecules/ast.stream.html.twig', ['game' => $game]),
        ));
    }
}
