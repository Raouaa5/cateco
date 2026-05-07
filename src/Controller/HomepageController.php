<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\RecommendationService;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class HomepageController extends AbstractController
{
    /** @param TaxonRepositoryInterface<TaxonInterface> $taxonRepository */
    public function __construct(
        private TaxonRepositoryInterface $taxonRepository,
        private RecommendationService    $recommendationService,
        private TokenStorageInterface    $tokenStorage,
    ) {}

    public function index(): Response
    {
        /** @var TaxonInterface|null $taxon */
        $taxon      = $this->taxonRepository->findOneBy(['code' => 'category']);
        $categories = $taxon ? $taxon->getChildren() : [];

        // Resolve logged-in customer ID (0 = anonymous → API returns popular products)
        $userId   = $this->resolveUserId();
        $recommendations = $this->recommendationService->getRecommendations($userId, topK: 8);

        return $this->render('@SyliusShop/homepage/index.html.twig', [
            'categories'      => $categories,
            'recommendations' => $recommendations,
        ]);
    }

    private function resolveUserId(): int
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return 0;
        }

        $user = $token->getUser();
        if ($user instanceof \Sylius\Component\Core\Model\ShopUserInterface && $user->getCustomer() !== null) {
            return (int) $user->getCustomer()->getId();
        }

        return 0;
    }
}
