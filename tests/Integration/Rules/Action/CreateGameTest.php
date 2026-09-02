<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules\Action;

use App\Rules\Action\CreateGame;
use App\State\Game;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
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
    #[DataProvider('provideAGameNameOverTheLengthLimitReportsItsLengthAsItsOnlyProblemCases')]
    public function aGameNameOverTheLengthLimitReportsItsLengthAsItsOnlyProblem(int $length): void
    {
        $createGame = new CreateGame();
        $createGame->slug = str_repeat('a', $length);

        $codes = $this->violationCodesFor($createGame, 'slug');

        $this->assertSame([Length::TOO_LONG_ERROR], $codes);
    }

    public static function provideAGameNameOverTheLengthLimitReportsItsLengthAsItsOnlyProblemCases(): iterable
    {
        yield 'one character over the limit' => [Game::MAX_SLUG_LENGTH + 1];

        yield 'far over the limit, the length SQLite was measured storing' => [300];
    }

    #[Test]
    #[DataProvider('provideAGameNameUpToTheLengthLimitRaisesNoViolationCases')]
    public function aGameNameUpToTheLengthLimitRaisesNoViolation(int $length): void
    {
        $createGame = new CreateGame();
        $createGame->slug = str_repeat('a', $length);

        $codes = $this->violationCodesFor($createGame, 'slug');

        $this->assertSame([], $codes);
    }

    public static function provideAGameNameUpToTheLengthLimitRaisesNoViolationCases(): iterable
    {
        yield 'a single character' => [1];

        yield 'one character below the limit' => [Game::MAX_SLUG_LENGTH - 1];

        yield 'exactly the limit' => [Game::MAX_SLUG_LENGTH];
    }

    #[Test]
    #[DataProvider('provideARegionNoScenarioOffersIsRefusedCases')]
    public function aRegionNoScenarioOffersIsRefused(string $region): void
    {
        $createGame = new CreateGame();
        $createGame->region = $region;

        $codes = $this->violationCodesFor($createGame, 'region');

        $this->assertSame([Choice::NO_SUCH_CHOICE_ERROR], $codes);
    }

    public static function provideARegionNoScenarioOffersIsRefusedCases(): iterable
    {
        yield 'a word that names no region' => ['banane'];

        yield 'the empty string, which is not the way to say "no region"' => [''];
    }

    #[Test]
    #[DataProvider('provideEveryRegionAScenarioOffersRaisesNoViolationCases')]
    public function everyRegionAScenarioOffersRaisesNoViolation(?string $region): void
    {
        $createGame = new CreateGame();
        $createGame->region = $region;

        $codes = $this->violationCodesFor($createGame, 'region');

        $this->assertSame([], $codes);
    }

    public static function provideEveryRegionAScenarioOffersRaisesNoViolationCases(): iterable
    {
        yield 'the region the form sends by default' => ['west'];

        yield 'the other one' => ['east'];

        yield 'no region, as every game of ten players or more' => [null];
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

    /** @return list<?string> */
    private function violationCodesFor(CreateGame $createGame, string $property): array
    {
        $codes = [];

        foreach ($this->validator->validateProperty($createGame, $property) as $violation) {
            $codes[] = $violation->getCode();
        }

        return $codes;
    }
}
