<?php
namespace Eccube\Entity;
abstract class AbstractEntity implements \ArrayAccess {
    public function offsetExists($offset): bool { return false; }
    public function offsetGet($offset): mixed { return null; }
    public function offsetSet($offset, $value): void {}
    public function offsetUnset($offset): void {}
    public function setPropertiesFromArray(array $arrProps, array $excludeAttribute = [], \ReflectionClass $parentClass = null) {}
}
