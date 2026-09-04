<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules\Action;

use App\Rules\Action\CreateGame;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CreateGameTest extends WebTestCase
{
    private ValidatorInterface $validator; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        self::bootKernel();

        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    #[Test]
    #[DataProvider('provideACommandThatNamesNoScenarioIsRefusedCases')]
    public function aCommandThatNamesNoScenarioIsRefused(int $playerCount, ?string $region): void
    {
        $messages = $this->violationMessagesFor($this->command($playerCount, $region), 'region');

        $this->assertSame(["No scenario is played by {$playerCount} players in this region."], $messages);
    }

    public static function provideACommandThatNamesNoScenarioIsRefusedCases(): iterable
    {
        yield 'one box above nine players, where the game needs both' => [12, 'east'];

        yield 'both boxes below ten players, where the game is played on one' => [6, null];

        yield 'a player count no scenario covers' => [19, 'west'];
    }

    #[Test]
    #[DataProvider('provideACommandThatNamesARealScenarioRaisesNoViolationCases')]
    public function aCommandThatNamesARealScenarioRaisesNoViolation(int $playerCount, ?string $region): void
    {
        $this->assertSame([], $this->violationMessagesFor($this->command($playerCount, $region), 'region'));
    }

    public static function provideACommandThatNamesARealScenarioRaisesNoViolationCases(): iterable
    {
        yield 'the smallest game there is' => [3, 'east'];

        yield 'the largest split by box' => [9, 'west'];

        yield 'the first that needs both boxes' => [10, null];

        yield 'the largest game there is' => [18, null];
    }

    private function command(int $playerCount, ?string $region): CreateGame
    {
        $createGame = new CreateGame();
        $createGame->playerCount = $playerCount;
        $createGame->region = $region;

        return $createGame;
    }

    /** @return list<string> */
    private function violationMessagesFor(CreateGame $createGame, string $property): array
    {
        $messages = [];

        foreach ($this->validator->validate($createGame) as $violation) {
            if ($property === $violation->getPropertyPath()) {
                $messages[] = (string) $violation->getMessage();
            }
        }

        return $messages;
    }
}
