<?php

namespace Eccube\Common;

// 最小スタブ (本物: src/Eccube/Common/EccubeConfig)。単体テスト専用。
class EccubeConfig implements \ArrayAccess
{
    private array $params;
    public function __construct(array $params = [])
    {
        $this->params = $params;
    }
    public function get(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->params);
    }
    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }
    #[\ReturnTypeWillChange]
    public function offsetGet($offset): mixed
    {
        return $this->get($offset);
    }
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        throw new \LogicException();
    }
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        throw new \LogicException();
    }
}
