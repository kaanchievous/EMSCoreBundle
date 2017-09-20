<?php

namespace EMS\CoreBundle\Repository;

use EMS\CoreBundle\Entity\ContentType;
use EMS\CoreBundle\Entity\Environment;
use EMS\CoreBundle\Entity\Revision;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

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
		
		$draftOrAutosave = $qb->expr()->orX();
		$draftOrAutosave->add($draftConditions);
		$draftOrAutosave->add($qb->expr()->isNotNull('r.autoSave'));
		
		$and = $qb->expr()->andX();
		$and->add($qb->expr()->eq('r.deleted', ':false'));
		$and->add($draftOrAutosave);
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
		
		$draftOrAutosave = $qb->expr()->orX();
		$draftOrAutosave->add($draftConditions);
		$draftOrAutosave->add($qb->expr()->isNotNull('r.autoSave'));
		
		$and = $qb->expr()->andX();
		$and->add($qb->expr()->eq('r.deleted', ':false'));
		$and->add($draftOrAutosave);
		
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
	 * @return \Doctrine\ORM\QueryBuilder
	 */
	private function getCompareQueryBuilder($source, $target, $contentypes){
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
		],[
				\Doctrine\DBAL\Types\Type::INTEGER,
				\Doctrine\DBAL\Types\Type::INTEGER,
				\Doctrine\DBAL\Types\Type::BOOLEAN,
		]);
		if(!empty($contentypes)){
			$qb->andWhere('c.name in (\''.implode("','", $contentypes).'\')');
		}
		return $qb;
	}
	
	public function compareEnvironment($source, $target, $contentypes = [], $from, $limit, $orderField = "contenttype", $orderDirection = 'ASC') {
		switch ($orderField){
			case "label":
				$orderField = 'item_labelField';
				break;
			default:
				$orderField = 'c.name';
				break;
		}	
		$qb = $this->getCompareQueryBuilder($source, $target, $contentypes);
		$qb->addOrderBy($orderField, $orderDirection)
		->addOrderBy('r.ouuid')
		->setFirstResult($from)
		->setMaxResults($limit);

		return $qb->getQuery()->getResult();
	}
	
	public function countByContentType(ContentType $contentType) {
		return $this->createQueryBuilder('a')
		->select('COUNT(a)')
		->where('a.contentType = :contentType')
		->setParameter('contentType', $contentType)
		->getQuery()
		->getSingleScalarResult();
	}

	public function countRevisions($ouuid, ContentType $contentType) {
		$qb = $this->createQueryBuilder('r')
			->select('COUNT(r)');
		$qb->where($qb->expr()->eq('r.ouuid', ':ouuid'));
		$qb->andWhere($qb->expr()->eq('r.contentType', ':contentType'));
		$qb->setParameter('ouuid', $ouuid);
		$qb->setParameter('contentType', $contentType);

		return $qb->getQuery()->getSingleScalarResult();
	}
	
	public function revisionsLastPage($ouuid, ContentType $contentType) {
		return floor($this->countRevisions($ouuid, $contentType)/5.0)+1;
	}
	
	public function firstElemOfPage($page) {
		return ($page-1)*5;
	}
	
	
	
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

	public function findByOuuidContentTypeAndEnvironnement(Revision $revision, Environment $env=null) {
		if(!isset($env)){
			$env = $revision->getContentType()->getEnvironment();
		}
		
		return $this->findByOuuidAndContentTypeAndEnvironnement($revision->getContentType(), $revision->getOuuid(), $env);
	}
	
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
	
	public function getCurrentRevision(ContentType $contentType, $ouuid)
	{
		$em = $this->getEntityManager();
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
	
	public function deleteRevision(Revision $revision) {
		$qb = $this->createQueryBuilder('r')->update()
		->set('r.delete', true)
		->where('r.id = ?1')
		->setParameter(1, $revision->getId());
			
		return $qb->getQuery()->execute();
	}
	
	public function deleteRevisions(ContentType $contentType=null) {
		if($contentType == null) {
			$qb = $this->createQueryBuilder('r');
			$qb->update()
			->set($qb->expr()->eq('r.delete', ':true'))
					->setParameters([
							'true' => true,
					]);
			
			return $qb->getQuery()->execute();
		} else {
			$qb = $this->createQueryBuilder('r')->update()
			->set($qb->expr()->eq('r.delete', ':true'))
			->where('r.contentTypeId = :contentTypeId')
			->setParameters([
					'true' => true,
					'contentTypeId' => $contentType->getId()
			]);
			
			return $qb->getQuery()->execute();
		}
	}
}
