<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BlogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BlogController extends AbstractController
{
    public function __construct(
        private readonly BlogService $blogService,
    ) {}

    #[Route('/blog', name: 'app_blog_index')]
    public function index(): Response
    {
        $posts = $this->blogService->getRandomUniquePosts(6);

        return $this->render('Shop/Blog/index.html.twig', [
            'posts' => $posts,
        ]);
    }

    #[Route('/blog/{slug}', name: 'app_blog_post_show')]
    public function show(string $slug): Response
    {
        $post = $this->blogService->findBySlug($slug);

        if (!$post) {
            throw $this->createNotFoundException('Article de blog introuvable.');
        }

        return $this->render('Shop/Blog/show.html.twig', [
            'post' => $post,
        ]);
    }
}
