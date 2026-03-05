<?php

namespace App\Repository;

use App\Entity\ZipCodeGazetteer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ZipCodeGazetteer> */
class ZipCodeGazetteerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ZipCodeGazetteer::class);
    }

    public function findByZip(string $zipCode): ?ZipCodeGazetteer
    {
        return $this->findOneBy(['zipCode' => $zipCode]);
    }

    public function truncate(): void
    {
        $conn     = $this->getEntityManager()->getConnection();
        $platform = $conn->getDatabasePlatform();
        $conn->executeStatement(
            $platform->getTruncateTableSQL($this->getClassMetadata()->getTableName(), true)
        );
    }
}
