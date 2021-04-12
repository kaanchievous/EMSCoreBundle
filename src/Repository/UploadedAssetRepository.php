<?php

namespace EMS\CoreBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\QueryBuilder;
use EMS\CoreBundle\Entity\UploadedAsset;

/**
 * UploadedAssetRepository.
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class UploadedAssetRepository extends EntityRepository
{
    const PAGE_SIZE = 100;

    /**
     * @return int
     */
    public function countHashes()
    {
        $qb = $this->createQueryBuilder('ua');
        $qb->select('count(DISTINCT ua.sha1)')
            ->where($qb->expr()->eq('ua.available', ':true'));
        $qb->setParameters([
            ':true' => true,
        ]);

        try {
            return \intval($qb->getQuery()->getSingleScalarResult());
        } catch (NonUniqueResultException $e) {
            return 0;
        }
    }

    /**
     * @return array<array{hash:string}>
     */
    public function getHashes(int $page): array
    {
        $qb = $this->createQueryBuilder('ua');
        $qb->select('ua.sha1 as hash')
            ->where($qb->expr()->eq('ua.available', ':true'))
            ->orderBy('ua.sha1', 'ASC')
            ->groupBy('ua.sha1')
            ->setFirstResult(UploadedAssetRepository::PAGE_SIZE * $page)
            ->setMaxResults(UploadedAssetRepository::PAGE_SIZE);
        $qb->setParameters([
            ':true' => true,
        ]);

        $out = [];
        foreach ($qb->getQuery()->getArrayResult() as $record) {
            if (isset($record['hash']) && \is_string($record['hash'])) {
                $out[] = ['hash' => $record['hash']];
            }
        }

        return $out;
    }

    /**
     * @param string $hash
     *
     * @return mixed
     */
    public function dereference($hash)
    {
        $qb = $this->createQueryBuilder('ua');
        $qb->update()
            ->set('ua.available', ':false')
            ->set('ua.status', ':status')
            ->where($qb->expr()->eq('ua.available', ':true'))
            ->andWhere($qb->expr()->eq('ua.sha1', ':hash'));
        $qb->setParameters([
            ':true' => true,
            ':false' => false,
            ':hash' => $hash,
            ':status' => 'cleaned',
        ]);

        return $qb->getQuery()->execute();
    }

    public function getInProgress(string $hash, string $user): ?UploadedAsset
    {
        $uploadedAsset = $this->findOneBy([
            'sha1' => $hash,
            'available' => false,
            'user' => $user,
        ]);
        if (null === $uploadedAsset || $uploadedAsset instanceof UploadedAsset) {
            return $uploadedAsset;
        }
        throw new \RuntimeException(\sprintf('Unexpected class object %s', \get_class($uploadedAsset)));
    }

    /**
     * @return array<UploadedAsset>
     */
    public function get(int $from, int $size, ?string $orderField, string $orderDirection, string $searchValue): array
    {
        $qb = $this->createQueryBuilder('ua');
        $qb->setFirstResult($from)
            ->setMaxResults($size);

        if (null !== $orderField) {
            $qb->orderBy(\sprintf('ua.%s', $orderField), $orderDirection);
        }

        $this->addSearchFilters($qb, $searchValue);

        return $qb->getQuery()->execute();
    }

    /**
     * @param array<string> $ids
     *
     * @return array<UploadedAsset>
     */
    public function findByIds(array $ids): array
    {
        $qb = $this->createQueryBuilder('ua');
        $qb
            ->andWhere($qb->expr()->in('ua.id', $ids))
            ->orderBy('ua.created', 'desc');

        return $qb->getQuery()->execute();
    }

    public function removeByHash(string $hash): void
    {
        $qb = $this->createQueryBuilder('ua');
        $qb->delete();
        $qb->where($qb->expr()->eq('ua.sha1', ':hash'));
        $qb->setParameters([
            ':hash' => $hash,
        ]);
        $qb->getQuery()->execute();
    }

    public function removeById(string $id): void
    {
        /** @var UploadedAsset $uploadedAsset */
        $uploadedAsset = $this->findOneBy([
            'id' => $id,
        ]);
        $this->remove($uploadedAsset);
    }

    public function remove(UploadedAsset $uploadedAsset): void
    {
        $this->_em->remove($uploadedAsset);
        $this->_em->flush();
    }

    public function getLastUploadedByHash(string $hash): ?UploadedAsset
    {
        $qb = $this->createQueryBuilder('ua');
        $qb->where($qb->expr()->eq('ua.available', ':true'));
        $qb->andWhere($qb->expr()->eq('ua.sha1', ':hash'));
        $qb->setParameters([
            ':true' => true,
            ':hash' => $hash,
        ]);
        $qb->orderBy('ua.modified', 'DESC');
        $qb->setMaxResults(1);
        $uploadedAsset = $qb->getQuery()->getOneOrNullResult();

        if (null === $uploadedAsset || $uploadedAsset instanceof UploadedAsset) {
            return $uploadedAsset;
        }
        throw new \RuntimeException(\sprintf('Unexpected class object %s', \get_class($uploadedAsset)));
    }

    public function searchCount(string $searchValue = ''): int
    {
        $qb = $this->createQueryBuilder('ua');
        $qb->select('count(ua.id)');
        $this->addSearchFilters($qb, $searchValue);

        try {
            return \intval($qb->getQuery()->getSingleScalarResult());
        } catch (NonUniqueResultException $e) {
            return 0;
        }
    }

    private function addSearchFilters(QueryBuilder $qb, string $searchValue): void
    {
        if (\strlen($searchValue) > 0) {
            $or = $qb->expr()->orX(
                $qb->expr()->like('ua.user', ':term'),
                $qb->expr()->like('ua.sha1', ':term'),
                $qb->expr()->like('ua.type', ':term'),
                $qb->expr()->like('ua.name', ':term')
            );
            $qb->andWhere($or)
                ->setParameter(':term', '%'.$searchValue.'%');
        }
    }

    public function update(UploadedAsset $UploadedAsset): void
    {
        $this->getEntityManager()->persist($UploadedAsset);
        $this->getEntityManager()->flush();
    }
}
