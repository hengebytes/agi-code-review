<?php

namespace App\Repository;

use App\Github\Entity\GithubPullRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GithubPullRequest>
 *
 * @method GithubPullRequest|null find($id, $lockMode = null, $lockVersion = null)
 * @method GithubPullRequest|null findOneBy(array $criteria, array $orderBy = null)
 * @method GithubPullRequest[]    findAll()
 * @method GithubPullRequest[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class GithubPullRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GithubPullRequest::class);
    }
}
