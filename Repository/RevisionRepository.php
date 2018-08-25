<?php

namespace EMS\CoreBundle\Repository;

use EMS\CoreBundle\Entity\ContentType;
use EMS\CoreBundle\Entity\Environment;
use EMS\CoreBundle\Entity\Revision;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use function intval;

/**
 * RevisionRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class RevisionRepository extends \Doctrine\ORM\EntityRepository
{
	

	
	
	/**
	 *
	 * @param Environment $env
	 * @param int $page
	 * @return \Doctrine\ORM\Tools\Pagination\Paginator
	 */
	public function getRevisionsPaginatorPerEnvironment(Environment $env, $page=0) {
		/** @var QueryBuilder $qb */
		$qb = $this->createQueryBuilder('r');
		$qb->join('r.environments', 'e')
		->where('e.id = :eid')
		//->andWhere($qb->expr()->eq('r.deleted', ':false')
		->setMaxResults(50)
		->setFirstResult($page*50)
		->orderBy('r.id', 'asc')
		->setParameters(['eid'=> $env->getId()]);
		
		$paginator = new Paginator($qb->getQuery());
		
		return $paginator;
	}

    /**
     * @param $hash
     * @return int
     * @throws NonUniqueResultException
     * @throws \Doctrine\DBAL\DBALException
     */
	public function hashReferenced($hash)
    {
        if($this->getEntityManager()->getConnection()->getDatabasePlatform()->getName() === 'postgresql')
        {
            $result = $this->getEntityManager()->getConnection()->fetchAll("select count(*) as counter FROM public.revision where raw_data::text like '%$hash%'");
            return intval($result[0]['counter']);
        }

        $qb = $this->createQueryBuilder('r')
            ->select('count(r)')
            ->where('r.rawData like :hash')
            ->setParameter('hash', "%$hash%");
        $query = $qb->getQuery();
        return intval($query->getSingleScalarResult());
    }


    /**
     *
     * @param Environment $env
     * @param ContentType $contentType
     * @param int $page
     * @return \Doctrine\ORM\Tools\Pagination\Paginator
     */
	public function getRevisionsPaginatorPerEnvironmentAndContentType(Environment $env, ContentType $contentType, $page=0) {
		/** @var QueryBuilder $qb */
		$qb = $this->createQueryBuilder('r');
		$qb->join('r.environments', 'e')
		->where('e.id = :eid')
		->andWhere('r.contentType = :ct')
		->setMaxResults(50)
		->setFirstResult($page*50)
		->orderBy('r.id', 'asc')
		->setParameters(['eid'=> $env->getId(), 'ct' => $contentType]);
		
		$paginator = new Paginator($qb->getQuery());
		
		return $paginator;
	}

    /**
     * @param $ouuid
     * @param ContentType $contentType
     * @param Environment $environment
     * @return mixed
     * @throws NonUniqueResultException
     * @throws \Doctrine\ORM\NoResultException
     */
	public function findByEnvironment($ouuid, ContentType $contentType, Environment $environment){
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
	
	public function draftCounterGroupedByContentType($circles, $isAdmin) {
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
		if(!$isAdmin){
			$inCircles = $qb->expr()->orX();
			$inCircles->add($qb->expr()->isNull('r.circles'));
			foreach ($circles as $counter => $circle){				
				$inCircles->add($qb->expr()->like('r.circles', ':circle'.$counter));
				$parameters['circle'.$counter] = '%'.$circle.'%';
			}
			$and->add($inCircles);
		}
		$qb->where($and);

		$qb->setParameters($parameters);
		return $qb->getQuery()->getResult();
	}
	
	public function findInProgresByContentType($contentType, $circles, $isAdmin) {

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
		
		if(!$isAdmin){
			$inCircles = $qb->expr()->orX();
			$inCircles->add($qb->expr()->isNull('r.circles'));
			foreach ($circles as $counter => $circle){				
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
     * @param $source
     * @param $target
     * @param array $contentTypes
     * @return mixed
     * @throws NonUniqueResultException
     */
	public function countDifferencesBetweenEnvironment($source, $target, $contentTypes = []) {
				
		$sqb = $this->getCompareQueryBuilder($source, $target, $contentTypes);
		$sqb->select('max(r.id)');
// 		$subQuery()
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
     *
     * @param $source
     * @param $target
     * @param $contentTypes
     * @return \Doctrine\ORM\QueryBuilder
     */
	private function getCompareQueryBuilder($source, $target, $contentTypes){
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
				'source'=>$source,
				'target'=>$target,
				'false'=>false,
		]);
		if(!empty($contentTypes)){
			$qb->andWhere('c.name in (\''.implode("','", $contentTypes).'\')');
		}
		return $qb;
	}

    /**
     * @param $source
     * @param $target
     * @param array $contentTypes
     * @param $from
     * @param $limit
     * @param string $orderField
     * @param string $orderDirection
     * @return mixed
     */
	public function compareEnvironment($source, $target, $contentTypes, $from, $limit, $orderField = "contenttype", $orderDirection = 'ASC') {
		switch ($orderField){
			case "label":
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
     * @param ContentType $contentType
     * @return mixed
     * @throws NonUniqueResultException
     */
	public function countByContentType(ContentType $contentType) {
		return $this->createQueryBuilder('a')
		->select('COUNT(a)')
		->where('a.contentType = :contentType')
		->setParameter('contentType', $contentType)
		->getQuery()
		->getSingleScalarResult();
	}

    /**
     * @param $ouuid
     * @param ContentType $contentType
     * @return mixed
     * @throws NonUniqueResultException
     */
	public function countRevisions($ouuid, ContentType $contentType) {
		$qb = $this->createQueryBuilder('r')
			->select('COUNT(r)');
		$qb->where($qb->expr()->eq('r.ouuid', ':ouuid'));
		$qb->andWhere($qb->expr()->eq('r.contentType', ':contentType'));
		$qb->setParameter('ouuid', $ouuid);
		$qb->setParameter('contentType', $contentType);

		return $qb->getQuery()->getSingleScalarResult();
	}

    /**
     * @param $ouuid
     * @param ContentType $contentType
     * @return float|int
     * @throws NonUniqueResultException
     */
	public function revisionsLastPage($ouuid, ContentType $contentType) {
		return floor($this->countRevisions($ouuid, $contentType)/5.0)+1;
	}

    /**
     * @param $page
     * @return float|int
     */
	public function firstElemOfPage($page) {
		return ($page-1)*5;
	}


    /**
     * @param $ouuid
     * @param ContentType $contentType
     * @param int $page
     * @return mixed
     */
	public function getAllRevisionsSummary($ouuid, ContentType $contentType, $page=1) {
		$qb = $this->createQueryBuilder('r');
		$qb->select('r', 'e');
		$qb->leftJoin('r.environments', 'e');
		$qb->where($qb->expr()->eq('r.ouuid', ':ouuid'));
		$qb->andWhere($qb->expr()->eq('r.contentType', ':contentType'));
		$qb->setMaxResults(5);
		$qb->setFirstResult(($page-1)*5);
		$qb->orderBy('r.created', 'DESC');
		$qb->setParameter('ouuid', $ouuid);
		$qb->setParameter('contentType', $contentType);
	
		return $qb->getQuery()->getResult();
	}

    /**
     * @param Revision $revision
     * @param Environment|null $env
     * @return null
     * @throws NonUniqueResultException
     */
	public function findByOuuidContentTypeAndEnvironnement(Revision $revision, Environment $env=null) {
		if(!isset($env)){
			$env = $revision->getContentType()->getEnvironment();
		}
		
		return $this->findByOuuidAndContentTypeAndEnvironnement($revision->getContentType(), $revision->getOuuid(), $env);
	}

    /**
     * @param ContentType $contentType
     * @param $ouuid
     * @param Environment $env
     * @return null
     * @throws NonUniqueResultException
     */
	public function findByOuuidAndContentTypeAndEnvironnement(ContentType $contentType, $ouuid, Environment $env) {
	
		
		$qb = $this->createQueryBuilder('r');
		$qb->join('r.environments', 'e');
		$qb->where('r.ouuid = :ouuid and e.id = :envId and r.contentType = :contentTypeId');
		$qb->setParameters([
				'ouuid' => $ouuid,
				'envId' => $env->getId(),
				'contentTypeId' => $contentType->getId()
		]);
		
		$out = $qb->getQuery()->getResult();
		if(count($out) > 1){
			throw new NonUniqueResultException($ouuid.' is publish multiple times in '.$env->getName());
		}
		if(empty($out)){
			return NULL;
		}
		return $out[0];
	}

    /**
     * @param $revisionId
     * @return mixed
     */
	public function unlockRevision($revisionId) {
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
     * @param $revisionId
     * @param $username
     * @param \DateTime $lockUntil
     * @return mixed
     */
	public function lockRevision($revisionId, $username,\DateTime $lockUntil) {
		$qb = $this->createQueryBuilder('r')->update() 
			->set('r.lockBy', '?1') 
			->set('r.lockUntil', '?2') 
			->where('r.id = ?3')
			->setParameter(1, $username)
			->setParameter(2, $lockUntil, \Doctrine\DBAL\Types\Type::DATETIME)
			->setParameter(3, $revisionId);
		return $qb->getQuery()->execute();
	}

    /**
     * @param ContentType $contentType
     * @param $ouuid
     * @param \DateTime $now
     * @return mixed
     */
	public function finaliseRevision(ContentType $contentType, $ouuid,\DateTime $now) {
		$qb = $this->createQueryBuilder('r')->update()
			->set('r.endTime', '?1')
			->where('r.contentType = ?2')
			->andWhere('r.ouuid = ?3')
			->andWhere('r.endTime is null')
			->andWhere('r.lockBy  <> ?4 OR r.lockBy is null')
			->setParameter(1, $now, \Doctrine\DBAL\Types\Type::DATETIME)
			->setParameter(2, $contentType)
			->setParameter(3, $ouuid)
			->setParameter(4, "SYSTEM_MIGRATE");
			return $qb->getQuery()->execute();
	
	}

    /**
     * @param ContentType $contentType
     * @param $ouuid
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
		
		/**@var Revision[] $currentRevision*/
		$currentRevision = $qb->getQuery()->execute();
		if(isset($currentRevision[0])) {
			return $currentRevision[0];
		} else {
			return null;
		}
	}

    /**
     * @param Revision $revision
     * @return mixed
     */
	public function publishRevision(Revision $revision) {
		$qb = $this->createQueryBuilder('r')->update()
		->set('r.draft', ':false')
		->set('r.lockBy', "null")
		->set('r.lockUntil', "null")
		->set('r.endTime', "null")
		->where('r.id = :id')
		->setParameters([
				'false' => false,
				'id' => $revision->getId()
			]);
		
		return $qb->getQuery()->execute();
		
	}

    /**
     * @param Revision $revision
     * @return mixed
     */
	public function deleteRevision(Revision $revision) {
		$qb = $this->createQueryBuilder('r')->update()
		->set('r.delete', true)
		->where('r.id = ?1')
		->setParameter(1, $revision->getId());
			
		return $qb->getQuery()->execute();
	}

    /**
     * @param ContentType|null $contentType
     * @return mixed
     */
	public function deleteRevisions(ContentType $contentType=null) {
		if($contentType == null) {
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
					'contentTypeId' => $contentType->getId()
			]);
			
			return $qb->getQuery()->execute();
		}
	}

    /**
     * @param ContentType $contentType
     * @param \DateTime $until
     * @param string $by
     * @param bool $force
     *
     * @param bool $id
     * @return int affected rows
     */
	public function lockRevisions(ContentType $contentType, \DateTime $until, $by, $force = false, $id = false)
    {
        $params = ['by' => $by, 'until' => $until, 'content_type' => $contentType];

        $qbSelect = $this->createQueryBuilder('s');
        $qbSelect
            ->select('s.id')
            ->andWhere($qbSelect->expr()->eq('s.contentType', ':content_type'))
            ->andWhere($qbSelect->expr()->isNull('s.endTime'))
            ->andWhere($qbSelect->expr()->eq('s.deleted', $qbSelect->expr()->literal(false)))
            ->andWhere($qbSelect->expr()->eq('s.draft', $qbSelect->expr()->literal(false)))
        ;

        if (!$force) {
            $qbSelect->andWhere($qbSelect->expr()->orX(
                $qbSelect->expr()->lt('s.lockUntil', ':now'),
                $qbSelect->expr()->isNull('s.lockUntil')
            ));

            $params['now'] = new \DateTime();
        }

        if ($id) {
            $qbSelect->andWhere(
                $qbSelect->expr()->eq('s.ouuid', ':content_id')
            );
            $params['content_id'] = $id;
        }

        $qbUpdate = $this->createQueryBuilder('r');
        $qbUpdate
            ->update()
            ->set('r.lockBy', ':by')
            ->set('r.lockUntil', ':until')
            ->andWhere($qbUpdate->expr()->in('r.id', $qbSelect->getDQL()))
            ->setParameters($params);

        return $qbUpdate->getQuery()->execute();
    }

    /**
     * @param ContentType $contentType
     * @param string      $lockBy
     * @param int         $page
     * @param int         $limit
     *
     * @return Paginator
     */
    public function findAllLockedRevisions(ContentType $contentType, $lockBy, $page = 0, $limit = 50)
    {
        $qb = $this->createQueryBuilder('r');
        $qb
            ->andWhere($qb->expr()->eq('r.contentType', ':content_type'))
            ->andWhere($qb->expr()->eq('r.lockBy', ':username'))
            ->andWhere($qb->expr()->isNull('r.endTime'))
            ->setMaxResults($limit)
            ->setFirstResult($page*$limit)
            ->orderBy('r.id', 'asc')
            ->setParameters([
                'content_type' => $contentType,
                'username' => $lockBy
            ])
        ;

        return new Paginator($qb->getQuery());
    }
}
