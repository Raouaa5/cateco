<?php

declare(strict_types=1);

namespace App\Controller;

use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class MenuController extends AbstractController
{
    private TaxonRepositoryInterface $taxonRepository;

    public function __construct(TaxonRepositoryInterface $taxonRepository)
    {
        $this->taxonRepository = $taxonRepository;
    }

    public function renderMenu(): Response
    {
        $taxon = $this->taxonRepository->findOneBy(['code' => 'category']);
        $categories = $taxon ? $taxon->getChildren() : [];

        return $this->render('@SyliusShop/shared/layout/base/header/navbar/menu.html.twig', [
            'taxons' => $categories,
        ]);
    }
}
