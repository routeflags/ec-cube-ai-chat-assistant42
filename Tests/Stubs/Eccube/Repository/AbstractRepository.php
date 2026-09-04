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
}
