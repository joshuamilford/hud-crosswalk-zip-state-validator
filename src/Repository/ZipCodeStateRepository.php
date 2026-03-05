<?php

namespace App\Repository;

use App\Entity\ZipCodeState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ZipCodeState>
 */
class ZipCodeStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ZipCodeState::class);
    }

    /**
     * Returns all state abbreviations associated with a given ZIP code.
     *
     * @return string[]
     */
    public function findStatesByZip(string $zipCode): array
    {
        return $this->createQueryBuilder('z')
            ->select('z.stateAbbr')
            ->where('z.zipCode = :zip')
            ->setParameter('zip', $zipCode)
            ->orderBy('z.resRatio', 'DESC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Returns all ZIP→state rows for a given ZIP code, ordered by residential ratio descending.
     *
     * @return ZipCodeState[]
     */
    public function findRowsByZip(string $zipCode): array
    {
        return $this->createQueryBuilder('z')
            ->where('z.zipCode = :zip')
            ->setParameter('zip', $zipCode)
            ->orderBy('z.resRatio', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns all ZIP codes that span more than one state.
     *
     * @return string[]
     */
    public function findMultiStateZipCodes(): array
    {
        return $this->createQueryBuilder('z')
            ->select('DISTINCT z.zipCode')
            ->where('z.isMultiState = true')
            ->orderBy('z.zipCode', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Truncates the table — used before a fresh import.
     */
    public function truncate(): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $platform   = $connection->getDatabasePlatform();
        $connection->executeStatement(
            $platform->getTruncateTableSQL($this->getClassMetadata()->getTableName(), true)
        );
    }
}
