<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\Fixture\Tables;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RedirectTest extends WebTestCase
{
    #[Test]
    public function theGameIndexSendsBackToTheHome(): void
    {
        $client = self::createClient();

        $client->request(Request::METHOD_GET, '/game');

        $this->assertResponseRedirects('/', 301);
    }

    #[Test]
    public function theOperatorHubSendsToTheBoard(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = Tables::westTable($entityManager);

        $client->request(Request::METHOD_GET, '/game/'.$game->slug.'/operator');

        $this->assertResponseRedirects('/game/'.$game->slug.'/operator/board', 301);
    }
}
