<?php

declare(strict_types=1);

namespace Plugin\AiChatAssistant42;

use Eccube\Common\EccubeNav;

class Nav implements EccubeNav
{
    public static function getNav()
    {
        return [
            'setting' => [
                'children' => [
                    'ai_chat_assistant' => [
                        'name' => 'AI チャットアシスタント',
                        'icon' => 'fa-comments',
                        'children' => [
                            'ai_chat_assistant_dashboard' => [
                                'name' => 'ダッシュボード',
                                'url' => 'admin_ai_chat_assistant_dashboard',
                            ],
                            'ai_chat_assistant_settings' => [
                                'name' => 'プラグイン設定',
                                'url' => 'admin_ai_chat_assistant_settings',
                            ],
                            'ai_chat_assistant_history' => [
                                'name' => 'チャット履歴',
                                'url' => 'admin_ai_chat_assistant_history',
                            ],
                            'ai_chat_assistant_report' => [
                                'name' => '統計・レポート',
                                'url' => 'admin_ai_chat_assistant_report',
                            ],
                            'ai_chat_assistant_knowledge' => [
                                'name' => 'ナレッジ管理',
                                'url' => 'admin_ai_chat_assistant_knowledge_index',
                            ],
                            'ai_chat_assistant_scenario' => [
                                'name' => 'シナリオ管理',
                                'url' => 'admin_ai_chat_assistant_scenario_index',
                            ],
                            'ai_chat_assistant_access' => [
                                'name' => 'アクセスルール',
                                'url' => 'admin_ai_chat_assistant_access_index',
                            ],
                            'ai_chat_assistant_design' => [
                                'name' => 'デザイン設定',
                                'url' => 'admin_ai_chat_assistant_design_index',
                            ],
                            'ai_chat_assistant_notification' => [
                                'name' => '通知ルール',
                                'url' => 'admin_ai_chat_assistant_notification_index',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
