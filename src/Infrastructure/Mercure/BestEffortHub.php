<?php

declare(strict_types=1);

namespace App\Infrastructure\Mercure;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * The hub as production wants it: publishing is best effort, and a hub that cannot be reached costs
 * a stale screen rather than the move a player just made. The publishers around it hold no opinion
 * on the matter — they publish, and this decides what an unreachable hub is worth.
 *
 * The two attributes are the whole wiring: registered in production only, decorating the hub in
 * place, so `HubInterface` resolves here without an alias to maintain. Everywhere else the class
 * does not exist and the real hub speaks for itself — a dev whose hub is misconfigured finds out on
 * their first mutation instead of reading it in a log three weeks later, which is what happened.
 *
 * The Mercure bundle offers no tolerance setting of its own, so this behaviour has nowhere else to
 * live; what it does not need is a configuration entry to say where.
 *
 * Deliberately narrow: it forgives the *transport*, never the render. A template that throws is a
 * bug the suite is meant to catch, and swallowing it would buy a screen frozen for reasons nobody
 * can see — the failure mode this class exists to end.
 */
#[When('prod')]
#[AsDecorator('mercure.hub.default')]
final readonly class BestEffortHub implements HubInterface
{
    public function __construct(
        #[AutowireDecorated]
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {}

    public function getPublicUrl(): string
    {
        return $this->hub->getPublicUrl();
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->hub->getFactory();
    }

    public function publish(Update $update): string
    {
        try {
            return $this->hub->publish($update);
        } catch (\Throwable $e) {
            $this->logger->error('Mercure publication failed', ['exception' => $e, 'topics' => $update->getTopics()]);

            return '';
        }
    }
}
