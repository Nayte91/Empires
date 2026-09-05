<?php

declare(strict_types=1);

namespace App\Presentation\ValueResolver;

use App\State\Player;
use App\State\Repository\PlayerRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AsTargetedValueResolver('player')]
final readonly class PlayerResolver implements ValueResolverInterface
{
    public function __construct(private PlayerRepositoryInterface $playerRepository) {}

    /** @return iterable<Player> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $player = $this->playerRepository->findOneByGameSlugAndSlug(
            (string) $request->attributes->get('gameSlug'),
            (string) $request->attributes->get('playerSlug'),
        );

        if (!$player instanceof Player) {
            throw new NotFoundHttpException();
        }

        yield $player;
    }
}
