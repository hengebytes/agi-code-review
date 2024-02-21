<?php

namespace App\Repository;

use App\Entity\TaskResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskResult>
 *
 * @method TaskResult|null find($id, $lockMode = null, $lockVersion = null)
 * @method TaskResult|null findOneBy(array $criteria, array $orderBy = null)
 * @method TaskResult[]    findAll()
 * @method TaskResult[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TaskResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskResult::class);
    }
}
