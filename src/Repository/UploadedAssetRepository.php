<?php

namespace EMS\CoreBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
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
     * @return array<mixed>
     */
    public function get(int $from, int $size): array
    {
        $qb = $this->createQueryBuilder('ua');
        $qb
            ->andWhere($qb->expr()->isNotNull('ua.id'))
            ->setFirstResult($from)
            ->setMaxResults($size)
            ->orderBy('ua.created', 'desc');

        return $qb->getQuery()->execute();
    }

    /**
     * @param array<string> $ids
     * @return array<mixed>
     */
    public function findByIds(array $ids): array
    {
        $qb = $this->createQueryBuilder('ua');
        $qb
            ->andWhere($qb->expr()->in('ua.id', $ids))
            ->orderBy('ua.created', 'desc');

        return $qb->getQuery()->execute();
    }
}
