<?php
namespace Eccube\Event;
class TemplateEvent {
    private $source; private $parameters = [];
    public function __construct($source = '', array $parameters = [], $view = null, $request = null) { $this->source = $source; $this->parameters = $parameters; }
    public function getSource(): string { return $this->source; }
    public function getParameters(): array { return $this->parameters; }
}
