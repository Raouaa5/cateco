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
    name: 'app:blog:update-images',
    description: 'Updates blog post images and inserts new posts',
)]
class UpdateBlogImagesCommand extends Command
{
    /** Existing posts: slug => new image URL */
    private const IMAGE_UPDATES = [
        'nettoyer-pulverisateur-jardin' => 'https://cateco.fr/img/ets_blog/post/4848673f81-nettoyer-pulverisateur.jpg',
        'brise-vue-naturel-synthetique' => 'https://cateco.fr/img/ets_blog/post/f1fbfad627-synthetique-brise-vue.jpg',
        'poser-caillebotis'             => 'https://cateco.fr/img/ets_blog/post/39c1c78c0b-caillebotis.png',
        'enlever-mauvaises-herbes'      => 'https://cateco.fr/img/ets_blog/post/enlever-mauvaise-herbe.jpg',
        'taxe-abri-jardin'              => 'https://cateco.fr/img/ets_blog/post/abri-jardin.jpg',
        'brise-vue-se-proteger'         => 'https://cateco.fr/img/ets_blog/post/brise_vue.jpg',
    ];

    /** New posts to insert */
    private const NEW_POSTS = [
        [
            'title'     => 'Comment choisir un abri de jardin ?',
            'slug'      => 'choisir-abri-de-jardin',
            'excerpt'   => 'Vous souhaitez installer un abri de jardin pour ranger vos outils et votre matériel ? Découvrez nos conseils pour choisir l\'abri qui correspond à vos besoins et à votre espace.',
            'content'   => '<p>Vous souhaitez installer un abri de jardin pour ranger vos outils et votre matériel ? Découvrez nos conseils pour choisir l\'abri qui correspond à vos besoins et à votre espace.</p><p>La taille de l\'abri doit être adaptée à votre jardin et à ce que vous souhaitez y stocker. Les abris en résine sont résistants aux UV et à l\'humidité, idéaux pour le climat guyanais. Les abris en bois apportent un plus esthétique mais nécessitent un entretien régulier.</p>',
            'image'     => 'https://cateco.fr/img/ets_blog/post/abri_de_jardin.jpg',
            'createdAt' => '2024-12-17',
        ],
        [
            'title'     => 'Profitez de votre terrasse en toute intimité avec un paravent pour l\'extérieur',
            'slug'      => 'paravent-exterieur-terrasse',
            'excerpt'   => 'Imaginez ce scénario : par un beau dimanche d\'été en Guyane, vous avez préparé un délicieux déjeuner pour votre famille et vous avez prévu de vous installer sur la terrasse pour le déguster en toute sérénité.',
            'content'   => '<p>Imaginez ce scénario : par un beau dimanche d\'été en Guyane, vous avez préparé un délicieux déjeuner pour votre famille et vous avez prévu de vous installer sur la terrasse pour le déguster en toute sérénité.</p><p>Un paravent extérieur est la solution idéale pour profiter de votre terrasse en toute intimité. Léger et modulable, il se déplace facilement et s\'adapte à tous types d\'espaces extérieurs. Disponibles en différents matériaux (bambou, rotin, tissu), les paravents ajoutent également une touche décorative à votre extérieur.</p>',
            'image'     => 'https://cateco.fr/img/ets_blog/post/paravent-exterieur.jpg',
            'createdAt' => '2024-12-17',
        ],
        [
            'title'     => 'Nos conseils pour construire vous-même votre poulailler',
            'slug'      => 'construire-son-poulailler',
            'excerpt'   => 'Vous souhaitez vous lancer dans l\'élevage de poules pour avoir des œufs frais ? La première étape est de construire un poulailler adapté à vos besoins.',
            'content'   => '<p>Vous souhaitez vous lancer dans l\'élevage de poules pour avoir des œufs frais ? La première étape est de construire un poulailler adapté à vos besoins.</p><p>Pour un poulailler réussi, choisissez un emplacement ensoleillé et bien drainé. Les matériaux doivent être robustes et résistants aux prédateurs. Prévoyez un espace intérieur d\'au moins 1 m² par poule, ainsi qu\'un espace extérieur grillagé pour qu\'elles puissent se promener en toute sécurité. N\'oubliez pas les perchoirs et les pondoirs pour assurer le confort de vos poules.</p>',
            'image'     => 'https://cateco.fr/img/ets_blog/post/2e9444c46f-construire-poullailler.jpg',
            'createdAt' => '2024-12-17',
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
        $repo = $this->em->getRepository(BlogPost::class);

        // --- Update existing image URLs ---
        $io->section('Updating image URLs for existing posts...');
        foreach (self::IMAGE_UPDATES as $slug => $imageUrl) {
            $post = $repo->findOneBy(['slug' => $slug]);
            if ($post) {
                $post->setImage($imageUrl);
                $io->text('  ✔ Updated: ' . $post->getTitle());
            } else {
                $io->warning('  Post not found: ' . $slug);
            }
        }

        // --- Insert new posts ---
        $io->section('Inserting new blog posts...');
        $newCount = 0;
        foreach (self::NEW_POSTS as $data) {
            $existing = $repo->findOneBy(['slug' => $data['slug']]);
            if ($existing) {
                $io->note('  Skipping (already exists): ' . $data['title']);
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
            $newCount++;
            $io->text('  ✔ Added: ' . $data['title']);
        }

        $this->em->flush();

        $io->success(sprintf('Done! Updated %d image(s), inserted %d new post(s).', count(self::IMAGE_UPDATES), $newCount));

        return Command::SUCCESS;
    }
}
