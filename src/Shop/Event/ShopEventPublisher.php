<?php

declare(strict_types=1);

namespace App\Shop\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ShopEventPublisher
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        #[Autowire(service: 'shop.event.bus')]
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
    ) {}

    public function publish(object $event): void
    {
        try {
            $this->eventDispatcher->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error('Domain event listener failed (psr)', ['exception' => $e, 'event' => $event::class]);
        }

        try {
            $this->eventBus->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error('Domain event listener failed (messenger)', ['exception' => $e, 'event' => $event::class]);
        }
    }
}
