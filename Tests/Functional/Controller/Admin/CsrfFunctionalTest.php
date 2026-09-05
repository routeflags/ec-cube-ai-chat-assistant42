<?php

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\Tests\Functional\Controller\Admin;

use PHPUnit\Framework\TestCase;

/**
 * CSRF 機能テスト（テンプレート + コントローラー結合）。
 *
 * GET で描画されたトークンが POST で検証されることを保証する。
 * 従来のモックによる単体テストでは検出できない「トークンID不整合」を
 * テンプレートの静的検査で検出する。
 */
class CsrfFunctionalTest extends TestCase
{
    private const TEMPLATE_DIR = __DIR__ . '/../../../../Resource/template/admin';
    private const TEMPLATE_DIR_FALLBACK = __DIR__ . '/../../../../../../../app/Plugin/AiChatAssistant42/Resource/template/admin';

    /**
     * 全管理画面 Twig が正しい CSRF トークンIDを使用していることを検証。
     *
     * 誤り例: <input name="_token" value="{{ csrf_token('admin') }}">
     * 正しい例: <input name="_token" value="{{ csrf_token(constant('Eccube\\Common\\Constant::TOKEN_NAME')) }}">
     * または csrf_token_for_anchor() を使用。
     */
    public function testAllAdminTemplatesUseCorrectCsrfTokenId(): void
    {
        $templates = glob(self::TEMPLATE_DIR . '/*.twig');
        if (empty($templates)) {
            $templates = glob(self::TEMPLATE_DIR_FALLBACK . '/*.twig');
        }
        $this->assertNotEmpty($templates, 'admin templates not found');

        $errors = [];

        foreach ($templates as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);

            // _token という name で csrf_token('admin') を使っている箇所を検出
            // これは Constant::TOKEN_NAME ('_token') と不一致で必ず 403 になる
            if (preg_match_all('/name="_token"\s+value="\{\{\s*csrf_token\(\'admin\'\)/', $content, $matches)) {
                $errors[] = sprintf('%s: %d 箇所で csrf_token(\'admin\') を使用（_token と不一致）', $basename, count($matches[0]));
            }

            // 正しいパターンの存在確認（_token を使う場合は Constant::TOKEN_NAME）
            if (strpos($content, 'name="_token"') !== false) {
                $hasCorrect = strpos($content, 'Constant::TOKEN_NAME') !== false
                    || strpos($content, "csrf_token('_token')") !== false;
                if (!$hasCorrect) {
                    // _token を使っているのに正しいトークン生成がない場合は警告（ただし isCsrfTokenValid 方式は除く）
                    if (!preg_match('/csrf_token\(\'admin_ai_chat_assistant_/', $content)) {
                        // 知識/シナリオの delete は独自 token ID を使用するため除外済み
                        $errors[] = sprintf('%s: name="_token" に対して正しい csrf_token ID が見つからない', $basename);
                    }
                }
            }
        }

        $this->assertEmpty($errors, "CSRF トークンID不整合を検出:\n" . implode("\n", $errors));
    }

    /**
     * settings.twig が正しいトークンを生成することを個別に検証。
     */
    public function testSettingsTemplateUsesCorrectToken(): void
    {
        $path = is_file(self::TEMPLATE_DIR . '/settings.twig') ? self::TEMPLATE_DIR . '/settings.twig' : self::TEMPLATE_DIR_FALLBACK . '/settings.twig';
        $content = file_get_contents($path);
        $this->assertStringContainsString(
            'Constant::TOKEN_NAME',
            $content,
            'settings.twig は _token に対して Constant::TOKEN_NAME を使用すべき'
        );
        $this->assertStringNotContainsString(
            "csrf_token('admin')",
            $content,
            'settings.twig で csrf_token(\'admin\') は _token と不一致で 403 になる'
        );
    }

    /**
     * design.twig が正しいトークンを生成することを検証。
     */
    public function testDesignTemplateUsesCorrectToken(): void
    {
        $path = is_file(self::TEMPLATE_DIR . '/design.twig') ? self::TEMPLATE_DIR . '/design.twig' : self::TEMPLATE_DIR_FALLBACK . '/design.twig';
        $content = file_get_contents($path);
        $this->assertStringContainsString(
            'Constant::TOKEN_NAME',
            $content
        );
        $this->assertStringNotContainsString(
            "csrf_token('admin')",
            $content
        );
    }

    /**
     * コントローラーが isTokenValid() の例外を適切に捕捉することを検証。
     *
     * Dashboard/AccessRule/Design/Notification は try/catch で AccessDeniedHttpException を
     * 捕捉し addError + redirect する。例外がそのまま 403 にならないことを保証。
     */
    public function testControllersCatchCsrfException(): void
    {
        $controllers = [
            'DashboardController.php' => 'admin_ai_chat_assistant_settings',
            'AccessRuleController.php' => 'admin_ai_chat_assistant_access_index',
            'DesignController.php' => 'admin_ai_chat_assistant_design_index',
            'NotificationController.php' => 'admin_ai_chat_assistant_notification_index',
        ];

        $errors = [];
        foreach ($controllers as $file => $route) {
            $base = __DIR__ . '/../../../../Controller/Admin/' . $file;
            $path = is_file($base) ? $base : __DIR__ . '/../../../../../../../app/Plugin/AiChatAssistant42/Controller/Admin/' . $file;
            $content = file_get_contents($path);
            $hasTryCatch = strpos($content, 'try {') !== false
                && strpos($content, 'isTokenValid()') !== false
                && strpos($content, 'catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException') !== false;
            if (!$hasTryCatch) {
                $errors[] = sprintf('%s: isTokenValid() を try/catch で捕捉していない（403 直結）', $file);
            }
        }

        $this->assertEmpty($errors, implode("\n", $errors));
    }

    /**
     * Knowledge/Scenario の delete が isCsrfTokenValid で正しく保護されていることを検証。
     */
    public function testKnowledgeAndScenarioDeleteUseIsCsrfTokenValid(): void
    {
        foreach (['KnowledgeController.php', 'ScenarioController.php'] as $file) {
            $base = __DIR__ . '/../../../../Controller/Admin/' . $file;
            $path = is_file($base) ? $base : __DIR__ . '/../../../../../../../app/Plugin/AiChatAssistant42/Controller/Admin/' . $file;
            $content = file_get_contents($path);
            $this->assertStringContainsString(
                'isCsrfTokenValid',
                $content,
                $file . ' は isCsrfTokenValid で保護すべき'
            );
            // isTokenValid を使っている場合は誤り（独自 token ID を使うため）
            $this->assertStringNotContainsString(
                'isTokenValid()',
                $content,
                $file . ' は isCsrfTokenValid を使用すべき（独自 token ID のため isTokenValid は不適切）'
            );
        }
    }
}
