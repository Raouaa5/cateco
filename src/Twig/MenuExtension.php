<?php

declare(strict_types=1);

namespace App\Twig;

use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MenuExtension extends AbstractExtension
{
    /** @param TaxonRepositoryInterface<TaxonInterface> $taxonRepository */
    public function __construct(
        private TaxonRepositoryInterface $taxonRepository
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_mega_menu_taxons', [$this, 'getMegaMenuTaxons']),
        ];
    }

    /** @return iterable<TaxonInterface> */
    public function getMegaMenuTaxons(): iterable
    {
        /** @var TaxonInterface|null $taxon */
        $taxon = $this->taxonRepository->findOneBy(['code' => 'category']);
        return $taxon ? $taxon->getChildren() : [];
    }
}
