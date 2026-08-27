<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Mercure;

use App\Infrastructure\Mercure\BestEffortHub;
use App\Tests\Support\Mercure\RecordingHub;
use App\Tests\Support\Mercure\ThrowingHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Mercure\Update;

/**
 * The one guarantee production buys by wrapping its hub: a move a player made survives a hub that
 * cannot be reached. It used to be a `catch` inside the publisher, which meant every environment
 * inherited the silence — including the one where a misconfigured hub ought to be heard at once.
 */
final class BestEffortHubTest extends TestCase
{
    #[Test]
    public function anUnreachableHubCostsThePublicationAndNothingElse(): void
    {
        $logger = $this->recordingLogger();
        $hub = new BestEffortHub(new ThrowingHub(), $logger);

        $result = $hub->publish(new Update('empires/game/1/roster', '<turbo-stream></turbo-stream>'));

        $this->assertSame('', $result);
        $this->assertCount(1, $logger->records);
        $this->assertSame('Mercure publication failed', $logger->records[0]['message']);
    }

    /** The failure names the topic that was lost, or the log says only that something went wrong. */
    #[Test]
    public function theLoggedFailureNamesTheTopicItCouldNotReach(): void
    {
        $logger = $this->recordingLogger();

        new BestEffortHub(new ThrowingHub(), $logger)->publish(new Update('empires/game/1/operator', 'x'));

        $this->assertSame(['empires/game/1/operator'], $logger->records[0]['context']['topics']);
    }

    /** Forgiving is all it adds: a reachable hub is passed through untouched. */
    #[Test]
    public function aReachableHubIsLeftEntirelyAlone(): void
    {
        $inner = new RecordingHub();
        $logger = $this->recordingLogger();

        $result = new BestEffortHub($inner, $logger)->publish(new Update('empires/game/1/ast', 'payload'));

        $this->assertSame('recording-hub-id', $result);
        $this->assertSame(['empires/game/1/ast'], $inner->topics());
        $this->assertSame([], $logger->records);
    }

    /** @return AbstractLogger&object{records: list<array{message: string, context: array<string, mixed>}>} */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
    }
}
