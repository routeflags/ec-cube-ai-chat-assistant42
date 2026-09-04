<?php
namespace Eccube\Repository;
use Eccube\Entity\BaseInfo;
class BaseInfoRepository extends AbstractRepository {
    public function __construct($registry = null) { parent::__construct($registry, BaseInfo::class); }
    public function get($id = 1) { return $this->find($id); }
}
