<?php
namespace Eccube\Entity;
class BaseInfo extends AbstractEntity {
    private $data = [];
    public function __call($name, $args) {
        if (str_starts_with($name, 'set')) {
            $prop = lcfirst(substr($name, 3));
            $this->data[$prop] = $args[0] ?? null;
            return $this;
        }
        if (str_starts_with($name, 'get')) {
            $prop = lcfirst(substr($name, 3));
            return $this->data[$prop] ?? null;
        }
        if (str_starts_with($name, 'is')) {
            $prop = lcfirst(substr($name, 2));
            return (bool)($this->data[$prop] ?? false);
        }
        return null;
    }
    public function __get($name) { return $this->data[$name] ?? null; }
    public function __set($name, $value) { $this->data[$name] = $value; }
}
