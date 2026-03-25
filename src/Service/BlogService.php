<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BlogPost;
use App\Repository\BlogPostRepository;

class BlogService
{
    public function __construct(
        private readonly BlogPostRepository $blogPostRepository,
    ) {}

    /**
     * Get random unique blog posts.
     *
     * @return BlogPost[]
     */
    public function getRandomUniquePosts(int $limit = 6): array
    {
        $posts = $this->blogPostRepository->findAllEnabled();

        // Remove any potential duplicates by ID
        $unique = [];
        foreach ($posts as $post) {
            $unique[$post->getId()] = $post;
        }

        // Convert to indexed array and shuffle
        $unique = array_values($unique);
        shuffle($unique);

        // Return only requested limit
        return array_slice($unique, 0, $limit);
    }

    /**
     * Find a single blog post by its slug.
     */
    public function findBySlug(string $slug): ?BlogPost
    {
        return $this->blogPostRepository->findOneBy(['slug' => $slug, 'enabled' => true]);
    }
}
