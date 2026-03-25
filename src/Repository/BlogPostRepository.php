<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BlogPost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BlogPost>
 *
 * @method BlogPost|null find($id, $lockMode = null, $lockVersion = null)
 * @method BlogPost|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method BlogPost[]    findAll()
 * @method BlogPost[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
class BlogPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogPost::class);
    }

    /**
     * @return BlogPost[]
     */
    public function findAllEnabled(): array
    {
        /** @var BlogPost[] $result */
        $result = $this->createQueryBuilder('b')
            ->andWhere('b.enabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult()
        ;
        return $result;
    }
}
