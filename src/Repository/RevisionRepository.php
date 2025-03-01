<?php

namespace EMS\CoreBundle\Repository;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use EMS\CoreBundle\Entity\ContentType;
use EMS\CoreBundle\Entity\Environment;
use EMS\CoreBundle\Entity\Revision;

class RevisionRepository extends EntityRepository
{
    public function findRevision(string $ouuid, string $contentTypeName, ?\DateTimeInterface $dateTime = null): ?Revision
    {
        $qb = $this->createQueryBuilder('r');
        $qb
            ->join('r.contentType', 'c')
            ->andWhere($qb->expr()->eq('c.name', ':content_type_name'))
            ->andWhere($qb->expr()->eq('r.ouuid', ':ouuid'))
            ->setParameters(['ouuid' => $ouuid, 'content_type_name' => $contentTypeName])
            ->orderBy('r.startTime', 'DESC')
            ->setMaxResults(1);

        if (null === $dateTime) {
            $qb->andWhere($qb->expr()->isNull('r.endTime'));
        } else {
            $format = $this->getEntityManager()->getConnection()->getDatabasePlatform()->getDateTimeFormatString();
            $qb
                ->andWhere($qb->expr()->lte('r.startTime', ':dateTime'))
                ->andWhere($qb->expr()->gte('r.endTime', ':dateTime'))
                ->setParameter('dateTime', $dateTime->format($format));
        }

        $result = $qb->getQuery()->getResult();

        return isset($result[0]) && $result[0] instanceof Revision ? $result[0] : null;
    }

    public function findByContentType(ContentType $contentType, $orderBy = null, $limit = null, $offset = null)
    {
        return $this->findBy([
                'contentType' => $contentType,
            ], $orderBy, $limit, $offset);
    }

    public function save(Revision $revision): void
    {
        $this->_em->persist($revision);
        $this->_em->flush();
    }

    /**
     * @param int $page
     *
     * @return Paginator
     */
    public function getRevisionsPaginatorPerEnvironment(Environment $env, $page = 0)
    {
        /** @var QueryBuilder $qb */
        $qb = $this->createQueryBuilder('r');
        $qb->join('r.environments', 'e')
        ->where('e.id = :eid')
        //->andWhere($qb->expr()->eq('r.deleted', ':false')
        ->setMaxResults(50)
        ->setFirstResult($page * 50)
        ->orderBy('r.id', 'asc')
        ->setParameters(['eid' => $env->getId()]);

        $paginator = new Paginator($qb->getQuery());

        return $paginator;
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function findOneById(int $id): Revision
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.id = :id')
            ->setParameter('id', $id);

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * @param string $hash
     *
     * @return int
     *
     * @throws DBALException
     */
    public function hashReferenced($hash)
    {
        if ('postgresql' === $this->getEntityManager()->getConnection()->getDatabasePlatform()->getName()) {
            $result = $this->getEntityManager()->getConnection()->fetchAll("select count(*) as counter FROM public.revision where raw_data::text like '%$hash%'");

            return \intval($result[0]['counter']);
        }

        try {
            $qb = $this->createQueryBuilder('r')
                ->select('count(r)')
                ->where('r.rawData like :hash')
                ->setParameter('hash', "%$hash%");
            $query = $qb->getQuery();

            return \intval($query->getSingleScalarResult());
        } catch (NonUniqueResultException $e) {
            return 0;
        }
    }

    /**
     * @param int $page
     *
     * @return Paginator
     */
    public function getRevisionsPaginatorPerEnvironmentAndContentType(Environment $env, ContentType $contentType, $page = 0)
    {
        /** @var QueryBuilder $qb */
        $qb = $this->createQueryBuilder('r');
        $qb->join('r.environments', 'e')
        ->where('e.id = :eid')
        ->andWhere('r.contentType = :ct')
        ->setMaxResults(50)
        ->setFirstResult($page * 50)
        ->orderBy('r.id', 'asc')
        ->setParameters(['eid' => $env->getId(), 'ct' => $contentType]);

        $paginator = new Paginator($qb->getQuery());

        return $paginator;
    }

    /**
     * @param string $ouuid
     *
     * @return Revision
     *
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function findByEnvironment($ouuid, ContentType $contentType, Environment $environment)
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.environments', 'e')
            ->andWhere('r.ouuid = :ouuid')
            ->andWhere('r.contentType = :contentType')
            ->andWhere('e.id = :eid')
            ->setParameter('ouuid', $ouuid)
            ->setParameter('contentType', $contentType)
            ->setParameter('eid', $environment->getId());

        return $qb->getQuery()->getSingleResult();
    }

    public function draftCounterGroupedByContentType($circles, $isAdmin)
    {
        $qb = $this->createQueryBuilder('r');
        $qb->join('r.contentType', 'c');
        $qb->select('c.id content_type_id', 'count(c.id) counter');
        $qb->groupBy('c.id');

        $draftConditions = $qb->expr()->andX();
        $draftConditions->add($qb->expr()->eq('r.draft', ':true'));
        $draftConditions->add($qb->expr()->isNull('r.endTime'));

        $draftOrAutoSave = $qb->expr()->orX();
        $draftOrAutoSave->add($draftConditions);
        $draftOrAutoSave->add($qb->expr()->isNotNull('r.autoSave'));

        $and = $qb->expr()->andX();
        $and->add($qb->expr()->eq('r.deleted', ':false'));
        $and->add($draftOrAutoSave);
        $parameters = [
                ':false' => false,
                ':true' => true,
        ];
        if (!$isAdmin) {
            $inCircles = $qb->expr()->orX();
            $inCircles->add($qb->expr()->isNull('r.circles'));
            foreach ($circles as $counter => $circle) {
                $inCircles->add($qb->expr()->like('r.circles', ':circle'.$counter));
                $parameters['circle'.$counter] = '%'.$circle.'%';
            }
            $and->add($inCircles);
        }
        $qb->where($and);

        $qb->setParameters($parameters);

        return $qb->getQuery()->getResult();
    }

    public function findInProgresByContentType($contentType, $circles, $isAdmin)
    {
        $parameters = [
                'contentType' => $contentType,
                'false' => false,
                'true' => true,
        ];

        $qb = $this->createQueryBuilder('r');

        $draftConditions = $qb->expr()->andX();
        $draftConditions->add($qb->expr()->eq('r.draft', ':true'));
        $draftConditions->add($qb->expr()->isNull('r.endTime'));

        $draftOrAutoSave = $qb->expr()->orX();
        $draftOrAutoSave->add($draftConditions);
        $draftOrAutoSave->add($qb->expr()->isNotNull('r.autoSave'));

        $and = $qb->expr()->andX();
        $and->add($qb->expr()->eq('r.deleted', ':false'));
        $and->add($draftOrAutoSave);

        if (!$isAdmin) {
            $inCircles = $qb->expr()->orX();
            $inCircles->add($qb->expr()->isNull('r.circles'));
            foreach ($circles as $counter => $circle) {
                $inCircles->add($qb->expr()->like('r.circles', ':circle'.$counter));
                $parameters['circle'.$counter] = '%'.$circle.'%';
            }
            $and->add($inCircles);
        }

        $qb->where($and)
            ->andWhere($qb->expr()->eq('r.contentType', ':contentType'));

        $qb->setParameters($parameters);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param string $source
     * @param string $target
     * @param array  $contentTypes
     *
     * @return mixed
     *
     * @throws NonUniqueResultException
     */
    public function countDifferencesBetweenEnvironment($source, $target, $contentTypes = [])
    {
        $sqb = $this->getCompareQueryBuilder($source, $target, $contentTypes);
        $sqb->select('max(r.id)');
//         $subQuery()
        $qb = $this->createQueryBuilder('rev');
        $qb->select('count(rev)');
        $qb->where($qb->expr()->in('rev.id', $sqb->getDQL()));
        $qb->setParameters([
                'false' => false,
                'source' => $source,
                'target' => $target,
        ]);

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param string $source
     * @param string $target
     * @param array  $contentTypes
     *
     * @return QueryBuilder
     */
    private function getCompareQueryBuilder($source, $target, $contentTypes)
    {
        $qb = $this->createQueryBuilder('r');
        $qb->select('c.id', 'c.color', 'c.labelField ct_labelField', 'c.name content_type_name', 'c.icon', 'r.ouuid', 'max(r.labelField) as item_labelField', 'count(c.id) counter', 'min(concat(e.id, \'/\',r.id, \'/\', r.created)) minrevid', 'max(concat(e.id, \'/\',r.id, \'/\', r.created)) maxrevid', 'max(r.id) lastRevId')
        ->join('r.contentType', 'c')
        ->join('r.environments', 'e')
        ->where('e.id in (:source, :target)')
        ->andWhere($qb->expr()->eq('r.deleted', ':false'))
        ->andWhere($qb->expr()->eq('c.deleted', ':false'))
        ->groupBy('c.id', 'c.name', 'c.icon', 'r.ouuid', 'c.orderKey')
        ->orHaving('count(r.id) = 1')
        ->orHaving('max(r.id) <> min(r.id)')
        ->setParameters([
                'source' => $source,
                'target' => $target,
                'false' => false,
        ]);
        if (!empty($contentTypes)) {
            $qb->andWhere('c.name in (\''.\implode("','", $contentTypes).'\')');
        }

        return $qb;
    }

    /**
     * @param string $source
     * @param string $target
     * @param array  $contentTypes
     * @param int    $from
     * @param int    $limit
     * @param string $orderField
     * @param string $orderDirection
     *
     * @return mixed
     */
    public function compareEnvironment($source, $target, $contentTypes, $from, $limit, $orderField = 'contenttype', $orderDirection = 'ASC')
    {
        switch ($orderField) {
            case 'label':
                $orderField = 'item_labelField';
                break;
            default:
                $orderField = 'c.name';
                break;
        }
        $qb = $this->getCompareQueryBuilder($source, $target, $contentTypes);
        $qb->addOrderBy($orderField, $orderDirection)
        ->addOrderBy('r.ouuid')
        ->setFirstResult($from)
        ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return mixed
     *
     * @throws NonUniqueResultException
     */
    public function countByContentType(ContentType $contentType)
    {
        return $this->createQueryBuilder('a')
        ->select('COUNT(a)')
        ->where('a.contentType = :contentType')
        ->setParameter('contentType', $contentType)
        ->getQuery()
        ->getSingleScalarResult();
    }

    /**
     * @param string $ouuid
     *
     * @return mixed
     *
     * @throws NonUniqueResultException
     */
    public function countRevisions($ouuid, ContentType $contentType)
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r)');
        $qb->where($qb->expr()->eq('r.ouuid', ':ouuid'));
        $qb->andWhere($qb->expr()->eq('r.contentType', ':contentType'));
        $qb->setParameter('ouuid', $ouuid);
        $qb->setParameter('contentType', $contentType);

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param string $ouuid
     *
     * @return float|int
     *
     * @throws NonUniqueResultException
     */
    public function revisionsLastPage($ouuid, ContentType $contentType)
    {
        return \floor($this->countRevisions($ouuid, $contentType) / 5.0) + 1;
    }

    /**
     * @param int $page
     *
     * @return float|int
     */
    public function firstElemOfPage($page)
    {
        return ($page - 1) * 5;
    }

    /**
     * @param string $ouuid
     * @param int    $page
     *
     * @return mixed
     */
    public function getAllRevisionsSummary($ouuid, ContentType $contentType, $page = 1)
    {
        $qb = $this->createQueryBuilder('r');
        $qb->select('r', 'e');
        $qb->leftJoin('r.environments', 'e');
        $qb->where($qb->expr()->eq('r.ouuid', ':ouuid'));
        $qb->andWhere($qb->expr()->eq('r.contentType', ':contentType'));
        $qb->setMaxResults(5);
        $qb->setFirstResult(($page - 1) * 5);
        $qb->orderBy('r.created', 'DESC');
        $qb->setParameter('ouuid', $ouuid);
        $qb->setParameter('contentType', $contentType);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Revision|null
     *
     * @throws NonUniqueResultException
     */
    public function findByOuuidContentTypeAndEnvironment(Revision $revision, Environment $env = null)
    {
        if (!$env) {
            $env = $revision->getContentType()->getEnvironment();
        }

        return $this->findByOuuidAndContentTypeAndEnvironment($revision->getContentType(), $revision->getOuuid(), $env);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function findByOuuidAndContentTypeAndEnvironment(ContentType $contentType, $ouuid, Environment $env): ?Revision
    {
        $qb = $this->createQueryBuilder('r');
        $qb
            ->join('r.environments', 'e')
            ->andWhere($qb->expr()->eq('r.ouuid', ':ouuid'))
            ->andWhere($qb->expr()->eq('e.id', ':envId'))
            ->andWhere($qb->expr()->eq('r.contentType', ':contentTypeId'))
            ->setParameters([
                'ouuid' => $ouuid,
                'envId' => $env->getId(),
                'contentTypeId' => $contentType->getId(),
            ]);

        $result = $qb->getQuery()->getResult();

        if (\count($result) > 1) {
            throw new NonUniqueResultException($ouuid.' is publish multiple times in '.$env->getName());
        }

        if (isset($result[0]) && $result[0] instanceof Revision) {
            return $result[0];
        }

        return null;
    }

    /**
     * @throws NonUniqueResultException
     */
    public function findIdByOuuidAndContentTypeAndEnvironment(string $ouuid, int $contentType, int $env): ?array
    {
        $qb = $this->createQueryBuilder('r');
        $qb->join('r.environments', 'e');
        $qb->where('r.ouuid = :ouuid and e.id = :envId and r.contentType = :contentTypeId');
        $qb->setParameters([
            'ouuid' => $ouuid,
            'envId' => $env,
            'contentTypeId' => $contentType,
        ]);

        $out = $qb->getQuery()->getArrayResult();
        if (\count($out) > 1) {
            throw new NonUniqueResultException($ouuid.' is publish multiple times in '.$env);
        }

        return $out[0] ?? null;
    }

    /**
     * @param int $revisionId
     *
     * @return mixed
     */
    public function unlockRevision($revisionId)
    {
        $qb = $this->createQueryBuilder('r')->update()
            ->set('r.lockBy', '?1')
            ->set('r.lockUntil', '?2')
            ->where('r.id = ?3')
            ->setParameter(1, null)
            ->setParameter(2, null)
            ->setParameter(3, $revisionId);

        return $qb->getQuery()->execute();
    }

    /**
     * @param int    $revisionId
     * @param string $username
     *
     * @return mixed
     */
    public function lockRevision($revisionId, $username, \DateTime $lockUntil)
    {
        $qb = $this->createQueryBuilder('r')->update()
            ->set('r.lockBy', '?1')
            ->set('r.lockUntil', '?2')
            ->where('r.id = ?3')
            ->setParameter(1, $username)
            ->setParameter(2, $lockUntil, Type::DATETIME)
            ->setParameter(3, $revisionId);

        return $qb->getQuery()->execute();
    }

    /**
     * @param string $ouuid
     *
     * @return mixed
     */
    public function finaliseRevision(ContentType $contentType, $ouuid, \DateTime $now, string $lockUser)
    {
        $qb = $this->createQueryBuilder('r')->update()
            ->set('r.endTime', '?1')
            ->where('r.contentType = ?2')
            ->andWhere('r.ouuid = ?3')
            ->andWhere('r.endTime is null')
            ->andWhere('r.lockBy  <> ?4 OR r.lockBy is null')
            ->setParameter(1, $now, Type::DATETIME)
            ->setParameter(2, $contentType)
            ->setParameter(3, $ouuid)
            ->setParameter(4, $lockUser);

        return $qb->getQuery()->execute();
    }

    /**
     * @param string $ouuid
     *
     * @return Revision|null
     */
    public function getCurrentRevision(ContentType $contentType, $ouuid)
    {
        $qb = $this->createQueryBuilder('r')->select()
            ->where('r.contentType = ?2')
            ->andWhere('r.ouuid = ?3')
            ->andWhere('r.endTime is null')
            ->setParameter(2, $contentType)
            ->setParameter(3, $ouuid);

        /** @var Revision[] $currentRevision */
        $currentRevision = $qb->getQuery()->execute();
        if (isset($currentRevision[0])) {
            return $currentRevision[0];
        } else {
            return null;
        }
    }

    public function publishRevision(Revision $revision, bool $draft = false)
    {
        $qb = $this->createQueryBuilder('r')->update()
        ->set('r.draft', ':draft')
        ->set('r.lockBy', 'null')
        ->set('r.lockUntil', 'null')
        ->set('r.endTime', 'null')
        ->where('r.id = :id')
        ->setParameters([
                'draft' => $draft,
                'id' => $revision->getId(),
            ]);

        return $qb->getQuery()->execute();
    }

    /**
     * @return mixed
     */
    public function deleteRevision(Revision $revision)
    {
        $qb = $this->createQueryBuilder('r')->update()
        ->set('r.delete', true)
        ->where('r.id = ?1')
        ->setParameter(1, $revision->getId());

        return $qb->getQuery()->execute();
    }

    /**
     * @return mixed
     */
    public function deleteRevisions(ContentType $contentType = null)
    {
        if (null == $contentType) {
            $qb = $this->createQueryBuilder('r');
            $qb->update()
                ->set('r.delete', ':true')
                ->setParameters([
                        'true' => true,
                ]);

            return $qb->getQuery()->execute();
        } else {
            $qb = $this->createQueryBuilder('r')->update();
            $qb->set('r.delete', ':true')
                ->where('r.contentTypeId = :contentTypeId')
                ->setParameters([
                    'true' => true,
                    'contentTypeId' => $contentType->getId(),
                ]);

            return $qb->getQuery()->execute();
        }
    }

    public function lockRevisions(?ContentType $contentType, \DateTime $until, $by, $force = false, ?string $ouuid = null): int
    {
        $qbSelect = $this->createQueryBuilder('s');
        $qbSelect
            ->select('s.id')
            ->andWhere($qbSelect->expr()->isNull('s.endTime'))
            ->andWhere($qbSelect->expr()->eq('s.deleted', $qbSelect->expr()->literal(false)))
            ->andWhere($qbSelect->expr()->eq('s.draft', $qbSelect->expr()->literal(false)))
        ;

        $qbUpdate = $this->createQueryBuilder('r');
        $qbUpdate
            ->update()
            ->set('r.lockBy', ':by')
            ->set('r.lockUntil', ':until')
            ->setParameters(['by' => $by, 'until' => $until]);

        if (null !== $contentType) {
            $qbSelect->andWhere($qbSelect->expr()->eq('s.contentType', ':content_type'));
            $qbUpdate->setParameter('content_type', $contentType);
        }

        if (!$force) {
            $qbSelect->andWhere($qbSelect->expr()->orX(
                $qbSelect->expr()->lt('s.lockUntil', ':now'),
                $qbSelect->expr()->isNull('s.lockUntil')
            ));
            $qbUpdate->setParameter('now', new \DateTime());
        }

        if (null !== $ouuid) {
            $qbSelect->andWhere($qbSelect->expr()->eq('s.ouuid', ':ouuid'));
            $qbUpdate->setParameter('ouuid', $ouuid);
        }

        $qbUpdate->andWhere($qbUpdate->expr()->in('r.id', $qbSelect->getDQL()));

        return $qbUpdate->getQuery()->execute();
    }

    public function lockAllRevisions(\DateTime $until, string $by): int
    {
        return $this->lockRevisions(null, $until, $by, true);
    }

    public function unlockRevisions(?ContentType $contentType, string $by): int
    {
        $qbSelect = $this->createQueryBuilder('s');
        $qbSelect
            ->select('s.id')
            ->andWhere($qbSelect->expr()->eq('s.lockBy', ':by'))
            ->andWhere($qbSelect->expr()->isNull('s.endTime'))
            ->andWhere($qbSelect->expr()->eq('s.deleted', $qbSelect->expr()->literal(false)))
            ->andWhere($qbSelect->expr()->eq('s.draft', $qbSelect->expr()->literal(false)))
        ;

        $qbUpdate = $this->createQueryBuilder('u');
        $qbUpdate
            ->update()
            ->set('u.lockBy', ':null')
            ->set('u.lockUntil', ':null')
            ->setParameters(['by' => $by, 'null' => null])
        ;

        if (null !== $contentType) {
            $qbSelect->andWhere($qbSelect->expr()->eq('s.contentType', ':content_type'));
            $qbUpdate->setParameter('content_type', $contentType);
        }

        $qbUpdate->andWhere($qbUpdate->expr()->in('u.id', $qbSelect->getDQL()));

        return $qbUpdate->getQuery()->execute();
    }

    public function unlockAllRevisions(string $by): int
    {
        return $this->unlockRevisions(null, $by);
    }

    public function findAllLockedRevisions(ContentType $contentType, string $lockBy, int $page = 0, int $limit = 50): Paginator
    {
        /** @var QueryBuilder $qb */
        $qb = $this->createQueryBuilder('r');
        $qb
            ->andWhere($qb->expr()->eq('r.contentType', ':content_type'))
            ->andWhere($qb->expr()->eq('r.lockBy', ':username'))
            ->andWhere($qb->expr()->isNull('r.endTime'))
            ->setMaxResults($limit)
            ->setFirstResult($page * $limit)
            ->orderBy('r.id', 'asc')
            ->setParameters([
                'content_type' => $contentType,
                'username' => $lockBy,
            ])
        ;

        return new Paginator($qb->getQuery());
    }

    public function findDraftsByContentType(ContentType $contentType): array
    {
        $qbSelect = $this->createQueryBuilder('s');
        $qbSelect
            ->andWhere($qbSelect->expr()->eq('s.contentType', ':content_type'))
            ->andWhere($qbSelect->expr()->eq('s.draft', $qbSelect->expr()->literal(true)))
            ->andWhere($qbSelect->expr()->eq('s.deleted', $qbSelect->expr()->literal(false)))
            ->andWhere($qbSelect->expr()->isNull('s.endTime'))
            ->orderBy('s.id', 'asc')
            ->setParameters(['content_type' => $contentType])
        ;

        return $qbSelect->getQuery()->execute();
    }

    public function findAllDrafts(): array
    {
        $qbSelect = $this->createQueryBuilder('s');
        $qbSelect
            ->andWhere($qbSelect->expr()->eq('s.draft', $qbSelect->expr()->literal(true)))
            ->andWhere($qbSelect->expr()->eq('s.deleted', $qbSelect->expr()->literal(false)))
            ->andWhere($qbSelect->expr()->isNull('s.endTime'))
            ->orderBy('s.id', 'asc')
        ;

        return $qbSelect->getQuery()->execute();
    }
}
