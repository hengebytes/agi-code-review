<?php

namespace App\Repository;

use App\Entity\ProjectAgentConnection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectAgentConnection>
 *
 * @method ProjectAgentConnection|null find($id, $lockMode = null, $lockVersion = null)
 * @method ProjectAgentConnection|null findOneBy(array $criteria, array $orderBy = null)
 * @method ProjectAgentConnection[]    findAll()
 * @method ProjectAgentConnection[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AgentConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectAgentConnection::class);
    }

    public function findAllByConfigValue(
        string $fieldName,
        string $value
    ): array {
        return $this->createQueryBuilder('p')
            ->where('p.config LIKE :nameValue')
            ->setParameter('nameValue', '%\"' . $fieldName . '\": \"' . $value . '\"%')
            //->where('JSON_EXTRACT(p.config, :jsonPath) = :value')
            //->setParameter('jsonPath', '$.' . $fieldName)
            //->setParameter('value', $value)
            ->getQuery()
            ->getResult();
    }
}
