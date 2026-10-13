<?php
namespace Eccube\Event;
class TemplateEvent {
    private $source; private $parameters = []; private $snippets = [];
    private $assets = [];
    public function __construct($source = '', array $parameters = [], $view = null, $request = null) { $this->source = $source; $this->parameters = $parameters; }
    public function getSource(): string { return $this->source; }
    public function getParameters(): array { return $this->parameters; }
    public function getParameter(string $key) { return $this->parameters[$key] ?? null; }
    public function setParameter(string $key, $value): void { $this->parameters[$key] = $value; }
    public function addSnippet(string $snippet): void { $this->snippets[] = $snippet; }
    public function getSnippets(): array { return $this->snippets; }
    public function addAsset(string $asset, bool $include = true)
    {
        $this->assets[$asset] = $include;
        $this->parameters['plugin_assets'] = $this->assets;

        return $this;
    }
    public function getAssets(): array
    {
        return $this->assets;
    }
}
