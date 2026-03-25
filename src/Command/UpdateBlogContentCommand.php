<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BlogPost;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:blog:update-content',
    description: 'Updates blog post content with rich HTML provided by user',
)]
class UpdateBlogContentCommand extends Command
{
    private const CONTENT_UPDATES = [
        'enlever-mauvaises-herbes' => <<<HTML
<p>Avec le climat tropical de la Guyane, les mauvaises herbes peuvent rapidement envahir les allées du jardin, le gazon et le potager. Pour conserver un extérieur propre et accueillant, un désherbage régulier s’impose.</p>

<p>Découvrez avec les experts de Catéco comment enlever les mauvaises herbes, quels produits utiliser et quelles solutions sont les plus efficaces. Pulvérisateur, désherbeur thermique ou désherbant jardin, tous les articles sont disponibles sur notre site ou en magasin.</p>

<h2>Quelles solutions pour enlever les mauvaises herbes ?</h2>
<p>Ponctuellement, vous pouvez utiliser un couteau, un sarcloir ou une binette pour désherber manuellement votre jardin. C’est la solution la plus écologique et la plus économique, mais pas forcément la plus rapide ni la plus efficace. Catéco propose différents outils pour enlever les mauvaises herbes et entretenir vos espaces extérieurs.</p>

<h3>Désherbeur thermique</h3>
<p>Le désherbeur thermique est une méthode sûre et efficace pour faire disparaître les mauvaises herbes sans fatigue et sans utilisation de produits chimiques. Cet appareil électrique ou à gaz utilise la chaleur pour détruire les plantes indésirables : le choc thermique détruit les mauvaises herbes jusqu’à la racine.</p>
<p>Nous vous recommandons de traiter chaque plante dès son apparition. Le désherbeur thermique n’est pas recommandé pour les mauvaises herbes sur pelouse, il risquerait de bruler l’herbe autour ; réservez-le pour les allées, les bordures et les terrasses pavées. Ne l’utilisez pas en période de sécheresse et toujours avec précaution pour éviter tout risque d’incendie.</p>

<h3>Tête débrousailleuse et tête de désherbage</h3>
<p>Pour ceux qui préfèrent le désherbage mécanique, la tête débrousailleuse ne laisse aucune chance aux mauvaises herbes et aux ronces : cette tête de coupe universelle s’adapte sur votre débrousailleuse pour faire place nette dans le jardin. Tout aussi redoutable, la tête de désherbage sur roue et son crochet acéré permettent de retirer les plantes indésirables qui poussent entre les dalles et les pavés de votre allée.</p>

<h3>Pulvérisateur et cloche</h3>
<p>Optez pour un pulvérisateur pour répandre un produit désherbant sur les mauvaises herbes à éliminer. Ajoutez une cloche de désherbage pour une diffusion ciblée. Évitez d’utiliser des produits chimiques et fabriquez vous-même un désherbant naturel avec de l’eau, du vinaigre blanc et du gros sel. Pulvérisez à la racine des mauvaises herbes et répétez l’opération plusieurs fois dans la semaine pour les faire disparaître. Vous pouvez aussi utiliser l’eau de cuisson des pommes de terre : versez l’eau bouillante salée sur votre allée en gravier par exemple.</p>

<h2>Nos astuces pour lutter contre les mauvaises herbes</h2>
<p>Prenez les devants et empêchez les mauvaises herbes d’envahir votre jardin avec quelques astuces de jardinier. Pratiquez le paillage au potager : il s’agit de couvrir le sol avec de la paille, des feuilles mortes, du gazon tondu ou des écorces. Cette couche de matériau organique bloque les rayons du soleil et limite le développement des mauvaises herbes. Notez que le paillage doit être renouvelé régulièrement, car il se décompose avec le temps. De la même manière, vous pouvez utiliser une bâche noire ou une toile de paillage foncée pour protéger les sols avant plantation.</p>
<p>Et pour favoriser la biodiversité, acceptez de laisser une zone en friche qui constitue un refuge pour les insectes et autres animaux, bien utiles pour votre jardin. Vous pouvez également semer une prairie fleurie pour limiter de développement de certaines plantes indésirables.</p>

<h2>Le désherbage selon Catéco</h2>
<p>Expert en solutions pour le jardin et la maison, Catéco vous facilite le désherbage en Guyane. Vous pouvez passer commande directement sur notre site internet et choisir la livraison à domicile ou bien visiter nos magasins pour bénéficier de conseils et repartir avec vos produits.</p>
<p>Désherbeur thermique, désherbant pas cher ou pulvérisateur, nous sélectionnons les meilleurs équipements pour vous permettre d’enlever les mauvaises herbes simplement et efficacement.</p>
HTML,
        'amenager-terrasse-bois' => <<<HTML
<p>Vous rêvez d’une terrasse en bois à la fois design, confortable et fonctionnelle ? Ce matériau naturel s’intègre à merveille dans tous les extérieurs, du jardin au balcon. Polyvalente, élégante et durable, la terrasse en bois offre d’infinies possibilités d’aménagement : espace repas, coin détente, jardin suspendu, zone ombragée… Les options sont nombreuses pour répondre à vos envies. Que vous soyez en pleine rénovation ou dans un projet de construction neuve, il est essentiel de penser chaque détail pour que votre terrasse devienne un lieu de vie agréable, été comme hiver. Voici nos conseils pour l’optimiser avec style.</p>

<h2>Concevoir un espace repas chaleureux et fonctionnel</h2>

<h3>Installer une table conviviale</h3>
<p>Installer une table en bois accompagnée de chaises assorties permet de transformer votre terrasse en bois en espace repas convivial. Ajoutez quelques lampes suspendues, des bougies ou une guirlande lumineuse pour une atmosphère encore plus accueillante.</p>

<h3>Ajouter une pergola en bois</h3>
<p>Une pergola en bois offre une ombre naturelle tout en laissant filtrer la lumière. Agrémentez-la de plantes grimpantes ou de voilages légers pour une ambiance douce et intimiste. Idéal pour profiter de la terrasse en bois à toute heure.</p>
<p><a href="/fr_FR/taxons/amenagement-exterieur/tonnelle-et-pergola/pergola-et-carport" style="color: #f59e0b; font-weight: bold; text-decoration: underline;">Découvrez nos pergolas</a></p>

<h2>Créer des coins d’assise adaptés à tous les moments</h2>

<h3>Délimiter les espaces avec des claustras</h3>
<p>Les claustras en bois permettent de séparer visuellement les zones de votre terrasse tout en conservant une belle unité de style. Placés entre un coin repas et un espace détente, ils apportent intimité et esthétisme. Vous pouvez aussi y fixer des plantes ou de petits luminaires pour renforcer le charme naturel de votre terrasse en bois.</p>

<h3>Intégrer des bancs</h3>
<p>Les bancs en bois ou en métal, placés le long des bords de la terrasse en bois, permettent de structurer l’espace tout en multipliant les assises. Leur intégration facilite également la circulation. On peut même y ajouter des coffres de rangement pour les coussins ou les jouets des enfants.</p>

<h3>Apporter du confort avec des textiles</h3>
<p>Complétez l’ensemble avec des <a href="/fr_FR/taxons/amenagement-exterieur/salon-de-jardin" style="text-decoration: underline;">coussins moelleux, plaids ou fauteuils de jardin</a>. En mixant les textures et les couleurs, vous créez un coin détente cosy sur votre terrasse en bois, idéal pour lire ou partager un moment à plusieurs. Pensez aussi aux hamacs ou balancelles pour une touche bohème.</p>

<h2>Intégrer harmonieusement la végétation à la terrasse</h2>

<h3>Installer des jardinières encastrées</h3>
<p>Disposez des jardinières intégrées dans la terrasse en bois pour accueillir des plantes vivaces, des petits arbustes ou des herbes aromatiques. Une façon simple d’introduire de la nature sans occuper l’espace au sol. Vous pouvez aussi y glisser quelques luminaires pour un effet féérique à la nuit tombée.</p>

<h3>Conserver les arbres existants</h3>
<p>Un arbre en plein milieu de votre futur aménagement ? Faites-en un atout. En intégrant l’arbre dans la conception de votre terrasse en bois et en l’entourant d’un banc circulaire, vous créez une zone d’ombre naturelle et esthétique. Les feuillages offriront un abri naturel l’été tout en apportant de la fraîcheur.</p>

<h2>Soigner les finitions pour une terrasse en bois haut de gamme</h2>

<h3>Intégrer un éclairage extérieur</h3>
<p>L’éclairage LED est essentiel pour prolonger l’usage de votre terrasse en bois le soir. Installez des spots encastrés, des appliques solaires ou des luminaires nomades pour un éclairage ciblé et décoratif. N’hésitez pas à jouer sur les hauteurs et les températures de lumière pour créer différentes ambiances.</p>

<h3>Aménager les accès au jardin</h3>
<p>Des marches en bois, un chemin en gravier ou des pas japonais permettent de relier la terrasse en bois au jardin en douceur. Ces éléments assurent à la fois une transition esthétique et une bonne circulation. Vous pouvez également installer des garde-corps en bois ou en verre pour plus de sécurité.</p>

<h3>Ajouter un espace d’eau ou de feu pour plus de charme</h3>
<p><a href="/fr_FR/taxons/amenagement-exterieur/eclairage-exterieur/spot" style="color: #f59e0b; font-weight: bold; text-decoration: underline;">Découvrez nos spots lumineux</a></p>

<p>En jouant sur les volumes, les matériaux, la lumière et la végétation, votre terrasse en bois devient un véritable prolongement de la maison. Bien pensée, elle s’adapte à toutes les saisons et à tous les usages : déjeuner en famille, lecture à l’ombre, soirées entre amis, moments de détente au bord de l’eau… En optant pour des matériaux de qualité et un aménagement personnalisé, vous valorisez aussi durablement votre habitat. Pour vous aider à concrétiser ces idées, découvrez notre sélection de produits et bénéficiez de l’expertise de nos conseillers pour imaginer un aménagement à votre image. Avec un accompagnement personnalisé, votre terrasse en bois deviendra un espace de vie à part entière, confortable en toute saison.</p>
HTML,
        'choisir-abri-de-jardin' => <<<HTML
<p>Vous envisagez d’acheter un abri de jardin ? Pour organiser le rangement des outils, le mobilier de jardin ou les accessoires de jardinage, l’abri de jardin offre une surface de stockage supplémentaire dans une maison. Pratique et multifonctions, cette structure existe en plusieurs matières, dimensions et styles. Comment choisir un abri de jardin ? Voici quelques conseils.</p>

<h2>L’abri de jardin : une pièce multi-usages dans une maison</h2>
<p>Dans une maison, cet abri de rangement extérieur a vocation à stocker les accessoires de jardin, l’outillage, les meubles, les vélos, les skis, la tondeuse, les produits d’entretien... En fonction du modèle, un abri de jardin peut aussi servir d’espace de détente en complément d’une terrasse ou d’atelier pour les loisirs. Cette structure peut revêtir de multiples aspects : petite maison, chalet ou cabane avec un toit, une porte et parfois des fenêtres. L’abri de jardin se décline en un large choix de matériaux (métal acier, bois, résine, PVC), dimensions et prix. Le choix de la surface va dépendre surtout de votre utilisation, de vos besoins et de vos contraintes d’espace. Le prix varie en fonction du choix du matériau. Les abris de jardin en kit, plus accessibles qu’une construction en dur, se caractérisent par leur facilité de montage. Pratique et esthétique, l’abri de jardin est une pièce de rangement qui cumule de nombreux atouts !</p>

<h2>Les différents types d’abris de jardin</h2>

<h3>L’abri de jardin en métal</h3>
<p>Les abris métalliques (acier galvanisé) à montage rapide et facile sont un excellent choix. À la fois résistants et pas chers, ces abris s’assemblent en un tour de main et n’exigent aucun outillage spécifique. Pour stocker votre matériel à petit prix, l’abri de jardin en métal est une solution d’appoint idéale. Certains modèles sont même équipés d’un toit ouvrant. Ce type de construction est disponible en plusieurs dimensions. Tout dépend de l’espace disponible dont vous disposez dans votre jardin.</p>

<p><strong>Important :</strong> pour un abri de jardin dont l’emprise au sol ou la surface de plancher est comprise entre 5 et 20 m2, une déclaration préalable de travaux doit être déposée à la mairie.</p>

<h3>L’abri de jardin en toile</h3>
<p>Pratique et polyvalent, l’abri de jardin en toile se compose généralement d’une structure en acier galvanisé et d’une bâche résistante en plastique. La porte est zippée à l’avant pour une ouverture simplifiée. Facile à installer (rapidité de montage) et à démonter, l’abri de jardin en toile est particulièrement recommandé pour un usage saisonnier. Cet abri souple offre une pièce supplémentaire pour le rangement des vélos, la tondeuse, les accessoires de piscine...</p>

<h3>L’abri de jardin en PVC (ou résine)</h3>
<p>Fonctionnels et faciles d’entretien, les abris de jardin en PVC (ou résine) sont également très simples à installer (pré-assemblés). La résine est un matériau plastique qui ne craint pas les intempéries (résiste à l’humidité). De ce fait, un abri en PVC/résine est un excellent choix pour stocker les outils et le matériel de jardin.</p>

<h3>L’abri de jardin en bois</h3>
<p>L’aspect authentique du bois apporte beaucoup de cachet à un espace extérieur. Le prix d’un abri de jardin en bois est plus élevé qu’un abri en métal ou en PVC. Véritable petite maison, ce type d’abri est doté d’une toiture, d’une porte et parfois de fenêtres. Polyvalent, ce type d’abri de jardin en forme de cabanon peut servir d’atelier pour les activités créatives.</p>
HTML,
    ];

    private const IMAGE_UPDATES = [
        'amenager-terrasse-bois' => 'https://www.bois-expo.com/wp-content/uploads/2025/07/freepik__candid-photography-with-natural-textures-and-highl__17152.jpg.webp',
        'choisir-salon-de-jardin' => 'https://www.hesperide.com/img/uploads/cms_page/124/1_1_4842191552496f6c22e886170a34388b.jpeg?1690637558',
        'entretenir-tondeuse-gazon' => 'https://www.stihl.be/content/dam/stihl/mediapool/products/cordless-tools/lawn-mowers/ma-339/a09cc4c539044ba69ecf36895b55da93.jpg.transform/image-landscape-mq4/img.jpg',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repo = $this->em->getRepository(BlogPost::class);

        foreach (self::CONTENT_UPDATES as $slug => $htmlContent) {
            $post = $repo->findOneBy(['slug' => $slug]);
            if ($post) {
                $post->setContent($htmlContent);
                $io->text('  ✔ Updated content for: ' . $post->getTitle());
            } else {
                $io->warning('  Post not found: ' . $slug);
            }
        }

        foreach (self::IMAGE_UPDATES as $slug => $imageUrl) {
            $post = $repo->findOneBy(['slug' => $slug]);
            if ($post) {
                $post->setImage($imageUrl);
                $io->text('  ✔ Updated image for: ' . $post->getTitle());
            } else {
                $io->warning('  Post not found for image loop: ' . $slug);
            }
        }

        $this->em->flush();
        $io->success('Done! Updated content and images.');

        return Command::SUCCESS;
    }
}
