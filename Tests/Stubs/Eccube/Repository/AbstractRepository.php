<?php
namespace Eccube\Repository;
abstract class AbstractRepository {
    protected $eccubeConfig;
    public function __construct($registry = null, $entityClass = null) {}
    public function delete($entity) {}
    public function save($entity) {}
    public function find($id, $lockMode = null, $lockVersion = null) { return null; }
    public function findAll(): array { return []; }
    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array { return []; }
    public function findOneBy(array $criteria): ?object { return null; }
    public function createQueryBuilder(string $alias, ?string $indexBy = null): object { return new class() {
        public function __call(string $name, array $args): object { return $this; }
    }; }
    public function getEntityManager(): object { return new class() {
        public function __call(string $name, array $args) { return null; }
    }; }
}
