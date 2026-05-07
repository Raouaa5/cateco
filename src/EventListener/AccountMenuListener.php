<?php

declare(strict_types=1);

namespace App\EventListener;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\Routing\RouterInterface;

final class AccountMenuListener
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    public function addWishlistItem(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        $menu
            ->addChild('wishlist', ['uri' => $this->router->generate('app_wishlist_index')])
            ->setLabel('Ma Wishlist')
            ->setLabelAttribute('icon', 'tabler:heart')
        ;

        // Move 'wishlist' before 'address_book'
        $order = [];
        foreach ($menu->getChildren() as $name => $child) {
            if ($name === 'wishlist') {
                continue; // skip for now, we'll insert it manually
            }
            $order[$name] = $name;
            if ($name === 'change_password') {
                $order['wishlist'] = 'wishlist'; // insert wishlist after change_password
            }
        }

        $menu->reorderChildren($order);
    }
}
