<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Doctrine\ORM\EntityManagerInterface;

final class WishlistController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Display the customer's wishlist page.
     */
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $customerId = $this->getCustomerId();

        if (!$customerId) {
            return $this->render('wishlist/index.html.twig', ['items' => []]);
        }

        try {
            $items = $this->connection->fetchAllAssociative('
                SELECT
                    w.id,
                    w.created_at,
                    p.id    AS product_id,
                    pt.name AS product_name,
                    pt.slug AS product_slug,
                    pimg.path AS image_path,
                    (
                        SELECT COUNT(v.id)
                        FROM sylius_product_variant v
                        WHERE v.product_id = p.id
                          AND v.enabled = 1
                          AND (v.tracked = 0 OR (v.on_hand - v.on_hold) > 0)
                    ) AS available_variants_count
                FROM cateco_wishlist_item w
                JOIN sylius_product p ON p.id = w.product_id
                LEFT JOIN sylius_product_translation pt
                    ON pt.translatable_id = p.id AND pt.locale = :locale
                LEFT JOIN (
                    SELECT owner_id, MIN(id) AS img_id FROM sylius_product_image GROUP BY owner_id
                ) first_img ON first_img.owner_id = p.id
                LEFT JOIN sylius_product_image pimg ON pimg.id = first_img.img_id
                WHERE w.customer_id = :customerId
                ORDER BY w.created_at DESC
            ', ['customerId' => $customerId, 'locale' => 'fr_FR']);

            foreach ($items as &$item) {
                try {
                    $price = $this->connection->fetchOne('
                        SELECT cp.price
                        FROM sylius_product_variant pv
                        JOIN sylius_channel_pricing cp ON cp.product_variant_id = pv.id
                        WHERE pv.product_id = :pid
                        LIMIT 1
                    ', ['pid' => $item['product_id']]);
                    $item['price'] = $price ?: 0;
                } catch (\Exception $e) {
                    $item['price'] = 0;
                }
            }
            unset($item);
        } catch (\Exception $e) {
            $items = [];
        }

        return $this->render('wishlist/index.html.twig', ['items' => $items]);
    }

    /**
     * Toggle a product in/out of the wishlist (AJAX POST).
     */
    public function toggle(int $productId): JsonResponse
    {
        try {
            if (!$this->getUser()) {
                return $this->json(['redirect' => $this->generateUrl('sylius_shop_login')], 401);
            }

            $customerId = $this->getCustomerId();
            if (!$customerId) {
                return $this->json(['error' => 'No customer linked to this user'], 422);
            }

            // Check if product exists
            $exists = $this->connection->fetchOne(
                'SELECT id FROM sylius_product WHERE id = :id',
                ['id' => $productId]
            );
            if (!$exists) {
                return $this->json(['error' => 'Product not found'], 404);
            }

            // Check existing wishlist entry
            $existingId = $this->connection->fetchOne(
                'SELECT id FROM cateco_wishlist_item WHERE customer_id = :cid AND product_id = :pid',
                ['cid' => $customerId, 'pid' => $productId]
            );

            if ($existingId) {
                $this->connection->delete('cateco_wishlist_item', ['id' => $existingId]);
                $inWishlist = false;
            } else {
                $this->connection->insert('cateco_wishlist_item', [
                    'customer_id' => $customerId,
                    'product_id'  => $productId,
                    'created_at'  => (new \DateTime())->format('Y-m-d H:i:s'),
                ]);
                $inWishlist = true;
            }

            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM cateco_wishlist_item WHERE customer_id = :cid',
                ['cid' => $customerId]
            );

            return $this->json(['in_wishlist' => $inWishlist, 'count' => $count]);

        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove a single wishlist item (form POST from wishlist page).
     */
    #[IsGranted('ROLE_USER')]
    public function remove(int $id): Response
    {
        try {
            $customerId = $this->getCustomerId();
            if ($customerId) {
                $this->connection->delete('cateco_wishlist_item', [
                    'id'          => $id,
                    'customer_id' => $customerId,
                ]);
            }
        } catch (\Exception $e) {
            // Silently ignore removal errors
        }

        return $this->redirectToRoute('app_wishlist_index');
    }
    #[IsGranted('ROLE_USER')]
    public function clear(): Response
    {
        try {
            $customerId = $this->getCustomerId();
            if ($customerId) {
                $this->connection->delete('cateco_wishlist_item', [
                    'customer_id' => $customerId,
                ]);
            }
        } catch (\Exception $e) {
            // Silently ignore or flash message
        }

        return $this->redirectToRoute('app_wishlist_index');
    }

    #[IsGranted('ROLE_USER')]
    public function addToCart(
        int $id,
        ProductRepositoryInterface $productRepository,
        CartContextInterface $cartContext,
        FactoryInterface $orderItemFactory,
        OrderItemQuantityModifierInterface $orderItemQuantityModifier,
        OrderModifierInterface $orderModifier,
        EntityManagerInterface $entityManager
    ): Response {
        try {
            $product = $productRepository->find($id);
            if (!$product) {
                $this->addFlash('error', 'Produit introuvable.');
                return $this->redirectToRoute('app_wishlist_index');
            }

            $variant = $product->getVariants()->first();
            if (!$variant) {
                $this->addFlash('error', 'Ce produit n\'a pas de variante disponible.');
                return $this->redirectToRoute('app_wishlist_index');
            }

            $cart = $cartContext->getCart();

            /** @var \Sylius\Component\Core\Model\OrderItemInterface $orderItem */
            $orderItem = $orderItemFactory->createNew();
            $orderItem->setVariant($variant);
            $orderItemQuantityModifier->modify($orderItem, 1);

            $orderModifier->addToOrder($cart, $orderItem);

            $entityManager->persist($cart);
            $entityManager->flush();
            
            $this->addFlash('success', 'Produit ajouté au panier avec succès !');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de l\'ajout: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_wishlist_index');
    }

    #[IsGranted('ROLE_USER')]
    public function addAllToCart(
        ProductRepositoryInterface $productRepository,
        CartContextInterface $cartContext,
        FactoryInterface $orderItemFactory,
        OrderItemQuantityModifierInterface $orderItemQuantityModifier,
        OrderModifierInterface $orderModifier,
        EntityManagerInterface $entityManager
    ): Response {
        try {
            $customerId = $this->getCustomerId();
            if (!$customerId) {
                return $this->redirectToRoute('app_wishlist_index');
            }

            $rows = $this->connection->fetchAllAssociative('
                SELECT w.product_id 
                FROM cateco_wishlist_item w
                JOIN sylius_product p ON p.id = w.product_id
                WHERE w.customer_id = :cid
                  AND (
                      SELECT COUNT(v.id)
                      FROM sylius_product_variant v
                      WHERE v.product_id = p.id
                        AND v.enabled = 1
                        AND (v.tracked = 0 OR (v.on_hand - v.on_hold) > 0)
                  ) > 0
            ', ['cid' => $customerId]);

            if (empty($rows)) {
                $this->addFlash('error', 'Aucun produit disponible dans votre liste.');
                return $this->redirectToRoute('app_wishlist_index');
            }

            $cart = $cartContext->getCart();
            $addedCount = 0;

            foreach ($rows as $row) {
                $product = $productRepository->find($row['product_id']);
                if (!$product) continue;

                $variant = $product->getVariants()->first();
                if (!$variant) continue;

                /** @var \Sylius\Component\Core\Model\OrderItemInterface $orderItem */
                $orderItem = $orderItemFactory->createNew();
                $orderItem->setVariant($variant);
                $orderItemQuantityModifier->modify($orderItem, 1);

                $orderModifier->addToOrder($cart, $orderItem);
                $addedCount++;
            }

            if ($addedCount > 0) {
                $entityManager->persist($cart);
                $entityManager->flush();
                $this->addFlash('success', "$addedCount produit(s) ajouté(s) au panier.");
            } else {
                $this->addFlash('error', 'Aucun produit n\'a pu être ajouté.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de l\'ajout: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_wishlist_index');
    }

    private function getCustomerId(): ?int
    {
        $user = $this->getUser();
        if (!$user || !method_exists($user, 'getCustomer')) {
            return null;
        }
        $customer = $user->getCustomer();
        return $customer ? $customer->getId() : null;
    }
}
