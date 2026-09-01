<?php

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CacheBustExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cache_bust_version', [$this, 'getCacheBustVersion']),
        ];
    }

    /**
     * Return cache bust versions for css/js. Returns array with ->css and ->js accessible via dot.
     * Twig allows array access via dot, so returning array is fine.
     */
    public function getCacheBustVersion(): array
    {
        // Use plugin version or filemtime for cache busting. Simple static for now.
        $version = '1.0.0';
        // Try to get plugin version from eccube-plugin.yaml if available
        $projectDir = __DIR__ . '/../../..';
        // Actually plugin dir is /var/www/html/app/Plugin/AiChatAssistant42, so we can try to read version
        $yamlFile = dirname(__DIR__, 3) . '/eccube-plugin.yaml';
        if (is_file($yamlFile)) {
            $content = @file_get_contents($yamlFile);
            if ($content !== false && preg_match('/version:\s*([0-9\.]+)/', $content, $m)) {
                $version = $m[1];
            }
        }
        return ['css' => $version, 'js' => $version];
    }
}
