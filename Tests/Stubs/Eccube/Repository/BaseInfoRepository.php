<?php
namespace Eccube\Repository;
use Eccube\Entity\BaseInfo;
/**
 * EC-CUBE の BaseInfo Repository。
 *
 * @extends AbstractRepository<BaseInfo>
 */
class BaseInfoRepository extends AbstractRepository {
    public function __construct($registry = null) { parent::__construct($registry, BaseInfo::class); }
    public function get($id = 1): BaseInfo {
        $baseInfo = $this->find($id);
        return $baseInfo instanceof BaseInfo ? $baseInfo : new BaseInfo();
    }
}