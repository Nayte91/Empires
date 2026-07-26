<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

use Symfony\Component\Uid\Uuid;

/**
 * The lib's view of whoever is buying: identity plus the attributes pricing
 * needs (owned product keys). The library never owns users — the host maps
 * its User/Player/Customer entity onto this interface (see
 * App\Game\Shop\ShopConnector::buyerFor()).
 */
interface BuyerInterface
{
    public Uuid $id { get; }

    /** @var list<string> */
    public array $ownedKeys { get; }
}
