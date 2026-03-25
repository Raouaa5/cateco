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
    name: 'app:blog:seed',
    description: 'Seeds the database with initial blog posts',
)]
class SeedBlogPostsCommand extends Command
{
    private const POSTS = [
        [
            'title'     => 'Comment nettoyer un pulvérisateur de jardin ?',
            'slug'      => 'nettoyer-pulverisateur-jardin',
            'excerpt'   => 'Indispensable pour entretenir votre jardin, vos allées ou la toiture de votre maison, votre pulvérisateur doit faire l\'objet d\'un nettoyage régulier pour assurer son efficacité et sa longévité.',
            'content'   => '<p>Indispensable pour entretenir votre jardin, vos allées ou la toiture de votre maison, votre pulvérisateur doit faire l\'objet d\'un nettoyage régulier pour assurer son efficacité et sa longévité.</p><p>Commencez par vider complètement le réservoir de tout produit restant. Rincez-le plusieurs fois à l\'eau claire. Démontez ensuite la lance et la buse pour les nettoyer séparément avec une brosse fine. Vérifiez les joints et remplacez-les si nécessaire. Stockez votre pulvérisateur dans un endroit sec et à l\'abri du gel.</p>',
            'image'     => 'assets/shop/images/blog/blog1.jpg',
            'createdAt' => '2024-12-17',
        ],
        [
            'title'     => 'Quand et combien de fois arroser son potager ?',
            'slug'      => 'arroser-son-potager',
            'excerpt'   => 'Si vous avez un potager dans votre jardin, l\'arrosage est un point essentiel. Mais pour faire pousser vos plantes et semis dans des conditions idéales, il est important de respecter certaines règles.',
            'content'   => '<p>Si vous avez un potager dans votre jardin, l\'arrosage est un point essentiel. Mais pour faire pousser vos plantes et semis dans des conditions idéales, il est important de respecter certaines règles.</p><p>En général, il est recommandé d\'arroser le matin ou le soir pour éviter l\'évaporation. En période de sécheresse, un arrosage quotidien peut être nécessaire. Utilisez si possible un système goutte-à-goutte pour économiser l\'eau et favoriser une hydratation optimale des racines.</p>',
            'image'     => 'assets/shop/images/blog/blog2.jpg',
            'createdAt' => '2024-12-17',
        ],
        [
            'title'     => 'Comment poser un caillebotis ?',
            'slug'      => 'poser-caillebotis',
            'excerpt'   => 'Vous souhaitez poser un caillebotis pour embellir votre terrasse ou vos allées de jardin ? Pratique, durable, résistant aux intempéries... Un revêtement de sol en caillebotis apporte une réelle valeur ajoutée.',
            'content'   => '<p>Vous souhaitez poser un caillebotis pour embellir votre terrasse ou vos allées de jardin ? Pratique, durable, résistant aux intempéries, innovant, esthétique, économique... Un revêtement de sol en caillebotis apporte une réelle valeur ajoutée à vos espaces extérieurs.</p><p>Pour l\'installation, commencez par préparer la surface : elle doit être plane et stable. Posez les dalles caillebotis en les emboitant simplement les unes aux autres. Aucun outil ni colle n\'est nécessaire dans la plupart des cas.</p>',
            'image'     => 'assets/shop/images/blog/blog3.jpg',
            'createdAt' => '2024-12-17',
        ],
        [
            'title'     => 'Brise-vue : se protéger des regards et du vent',
            'slug'      => 'brise-vue-se-proteger',
            'excerpt'   => 'Pour être à l\'abri des regards des curieux et du vent, le brise-vent est la solution d\'occultation idéale pour profiter tranquillement de son jardin.',
            'content'   => '<p>Pour être à l\'abri des regards des curieux et du vent, le brise-vent est la solution d\'occultation idéale pour profiter tranquillement de son jardin.</p><p>Il existe différents types de brise-vue : en osier, en bois, en PVC, en tissu synthétique... Chacun a ses avantages selon votre besoin d\'occultation et l\'esthétique souhaitée. Fixez-les sur une clôture existante ou des poteaux solidement ancrés dans le sol.</p>',
            'image'     => 'assets/shop/images/blog/blog4.jpg',
            'createdAt' => '2024-12-17',
        ],
        [
            'title'     => 'Brise-vue naturel ou synthétique : que choisir ?',
            'slug'      => 'brise-vue-naturel-synthetique',
            'excerpt'   => 'Pour préserver son intimité et rester à l\'abri des curieux, le brise-vue fait partie des accessoires indispensables dans une maison.',
            'content'   => '<p>Pour préserver son intimité et rester à l\'abri des curieux, le brise-vue fait partie des accessoires indispensables dans une maison.</p><p>Le brise-vue naturel, souvent en bambou ou en osier, s\'intègre harmonieusement dans un jardin, mais nécessite plus d\'entretien. Le brise-vue synthétique en HDPE est plus résistant, durable et facile à entretenir. Votre choix dépendra de votre budget, de l\'esthétique souhaitée et de votre tolérance à l\'entretien.</p>',
            'image'     => 'assets/shop/images/blog/blog5.jpg',
            'createdAt' => '2024-12-17',
        ],
        [
            'title'     => 'Taxe sur un abri de jardin : ce qu\'il faut savoir',
            'slug'      => 'taxe-abri-jardin',
            'excerpt'   => 'Vous envisagez d\'installer un abri à l\'extérieur pour stocker votre équipement de jardin en Guyane ? Avant de vous lancer, il faut savoir que l\'installation des abris de rangement est encadrée par la loi.',
            'content'   => '<p>Vous envisagez d\'installer un abri à l\'extérieur pour stocker votre équipement de jardin en Guyane ? Avant de vous lancer dans ce projet, il faut savoir que l\'installation des abris de rangement extérieur est encadrée par la loi.</p><p>En fonction de la surface au sol de votre abri, des règles d\'urbanisme s\'appliquent : simple déclaration préalable ou permis de construire. Au-delà de 20 m², un permis de construire est obligatoire. Renseignez-vous auprès de votre mairie pour connaître les réglementations locales.</p>',
            'image'     => 'assets/shop/images/blog/blog6.jpg',
            'createdAt' => '2024-12-17',
        ],
        [
            'title'     => 'Nos conseils et astuces pour enlever les mauvaises herbes',
            'slug'      => 'enlever-mauvaises-herbes',
            'excerpt'   => 'Avec le climat tropical de la Guyane, les mauvaises herbes peuvent rapidement envahir les allées du jardin, le gazon et le potager. Un désherbage régulier s\'impose.',
            'content'   => '<p>Avec le climat tropical de la Guyane, les mauvaises herbes peuvent rapidement envahir les allées du jardin, le gazon et le potager. Pour conserver un extérieur propre et accueillant, un désherbage régulier s\'impose.</p><p>Plusieurs méthodes existent : le désherbage manuel (binage, sarclage), le désherbage thermique avec un désherbeur à flamme, ou encore l\'utilisation d\'un désherbant. Optez pour des solutions naturelles comme le vinaigre blanc pour préserver votre environnement.</p>',
            'image'     => 'assets/shop/images/blog/blog7.jpg',
            'createdAt' => '2024-12-17',
        ],
        [
            'title'     => 'Comment choisir son salon de jardin ?',
            'slug'      => 'choisir-salon-de-jardin',
            'excerpt'   => 'Profiter de votre terrasse ou de votre jardin commence par le choix du bon salon de jardin. Matériaux, taille, style... Voici nos conseils pour faire le bon choix.',
            'content'   => '<p>Profiter de votre terrasse ou de votre jardin commence par le choix du bon salon de jardin. Plusieurs critères sont à prendre en compte : la taille de votre espace, le nombre de personnes, le matériau (résine tressée, aluminium, teck) et le style souhaité.</p><p>En Guyane, le climat chaud et humide demande des matériaux résistants à la chaleur et à l\'humidité. Privilégiez des matériaux de synthèse comme la résine tressée ou l\'aluminium traité, qui ne rouillent pas et sont faciles d\'entretien.</p>',
            'image'     => 'assets/shop/images/blog/blog8.jpg',
            'createdAt' => '2025-01-10',
        ],
        [
            'title'     => 'Entretenir sa tondeuse à gazon : les bons gestes',
            'slug'      => 'entretenir-tondeuse-gazon',
            'excerpt'   => 'Une tondeuse bien entretenue vous garantit un gazon parfaitement tondu toute l\'année. Voici les gestes essentiels pour prolonger la durée de vie de votre appareil.',
            'content'   => '<p>Une tondeuse bien entretenue vous garantit un gazon parfaitement tondu toute l\'année. Voici les gestes essentiels pour prolonger la durée de vie de votre appareil.</p><p>Après chaque utilisation, nettoyez le dessous du plateau de coupe pour enlever l\'herbe accumulée. Vérifiez et changez l\'huile moteur régulièrement. Affûtez les lames au moins une fois par saison. En fin de saison, videz le réservoir d\'essence avant de stocker votre tondeuse dans un endroit sec.</p>',
            'image'     => 'assets/shop/images/blog/blog9.jpg',
            'createdAt' => '2025-01-15',
        ],
        [
            'title'     => 'Aménager une terrasse en bois : toutes nos astuces',
            'slug'      => 'amenager-terrasse-bois',
            'excerpt'   => 'La terrasse en bois est un incontournable pour profiter de son extérieur. Découvrez comment bien choisir les essences de bois, les finitions, et comment entretenir votre terrasse.',
            'content'   => '<p>La terrasse en bois est un incontournable pour profiter de son extérieur. Découvrez comment bien choisir les essences de bois, les finitions, et comment entretenir votre terrasse dans le temps.</p><p>Pour une terrasse durable sous le climat guyanais, choisissez des essences naturellement résistantes aux insectes et à l\'humidité : teck, ipé, cumaru. Appliquez une huile protectrice chaque année pour préserver la beauté du bois et prolonger sa durée de vie.</p>',
            'image'     => 'assets/shop/images/blog/blog10.jpg',
            'createdAt' => '2025-02-01',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding blog posts...');

        $repo = $this->em->getRepository(BlogPost::class);
        $count = 0;

        foreach (self::POSTS as $data) {
            // Skip if already exists to prevent duplicates
            $existing = $repo->findOneBy(['slug' => $data['slug']]);
            if ($existing) {
                $io->note('Skipping existing post: ' . $data['title']);
                continue;
            }

            $post = new BlogPost();
            $post->setTitle($data['title']);
            $post->setSlug($data['slug']);
            $post->setExcerpt($data['excerpt']);
            $post->setContent($data['content']);
            $post->setImage($data['image']);
            $post->setCreatedAt(new \DateTime($data['createdAt']));
            $post->setEnabled(true);

            $this->em->persist($post);
            $count++;
        }

        $this->em->flush();

        $io->success(sprintf('Done! Inserted %d new blog post(s).', $count));

        return Command::SUCCESS;
    }
}
