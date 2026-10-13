<?php
namespace Eccube\Plugin;
use Psr\Container\ContainerInterface;
/**
 * EC-CUBE 4.4 (Symfony 7.4) 実型に追従した最小Stub。
 * 本体 AbstractPluginManager の lifecycle は `: void` + Override 前提 (T-11)。
 */
abstract class AbstractPluginManager
{
    public function install(array $meta, ContainerInterface $container): void {}
    public function uninstall(array $meta, ContainerInterface $container): void {}
    public function enable(array $meta, ContainerInterface $container): void {}
    public function disable(array $meta, ContainerInterface $container): void {}
    public function update(array $meta, ContainerInterface $container): void {}
}
