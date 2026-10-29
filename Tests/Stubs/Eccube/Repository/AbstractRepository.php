<?php
namespace Eccube\Repository;
use Eccube\Entity\AbstractEntity;
/**
 * EC-CUBE の Repository 基底クラス（Doctrine\ORM\EntityRepository 相当）。
 *
 * 取り扱う Entity を T で表す。継承側は `@extends AbstractRepository<Entity>` を付ける。
 *
 * @template T of object
 */
abstract class AbstractRepository {
    protected $eccubeConfig;
    public function __construct($registry = null, $entityClass = null) {}
    public function delete(AbstractEntity $entity): void {}
    public function save(AbstractEntity $entity): void {}
    /**
     * @param mixed $id
     * @param int|null $lockMode
     * @param int|null $lockVersion
     * @return T|null
     */
    public function find($id, $lockMode = null, $lockVersion = null) { return null; }
    /**
     * @return T[]
     */
    public function findAll(): array { return []; }
    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return T[]
     */
    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array { return []; }
    /**
     * @param array<string, mixed> $criteria
     * @return T|null
     */
    public function findOneBy(array $criteria): ?object { return null; }
    /**
     * @throws \LogicException スタブのため未実装（静的解析での型解決用）
     */
    public function createQueryBuilder(string $alias, ?string $indexBy = null): \Doctrine\ORM\QueryBuilder { throw new \LogicException('stub'); }
    public function getEntityManager(): object { return new class() {
        public function __call(string $name, array $args) { return null; }
    }; }
}
