<?php

namespace App\Repository;

use App\Jira\Entity\JiraIssue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JiraIssue>
 *
 * @method JiraIssue|null find($id, $lockMode = null, $lockVersion = null)
 * @method JiraIssue|null findOneBy(array $criteria, array $orderBy = null)
 * @method JiraIssue[]    findAll()
 * @method JiraIssue[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class JiraIssueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JiraIssue::class);
    }
}
