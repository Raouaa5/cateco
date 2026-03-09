<?php

declare(strict_types=1);

namespace App\Twig;


use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MenuExtension extends AbstractExtension
{
    private TaxonRepositoryInterface $taxonRepository;

    public function __construct(TaxonRepositoryInterface $taxonRepository)
    {
        $this->taxonRepository = $taxonRepository;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_mega_menu_taxons', [$this, 'getMegaMenuTaxons']),
        ];
    }

    public function getMegaMenuTaxons(): iterable
    {
        $taxon = $this->taxonRepository->findOneBy(['code' => 'category']);
        return $taxon ? $taxon->getChildren() : [];
    }
}
