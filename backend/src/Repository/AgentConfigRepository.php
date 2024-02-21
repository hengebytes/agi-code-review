<?php

namespace App\Repository;

use App\Entity\AgentConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentConfig>
 *
 * @method AgentConfig|null find($id, $lockMode = null, $lockVersion = null)
 * @method AgentConfig|null findOneBy(array $criteria, array $orderBy = null)
 * @method AgentConfig[]    findAll()
 * @method AgentConfig[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AgentConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentConfig::class);
    }
}
