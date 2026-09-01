<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\Controller\Api;

use Eccube\Controller\AbstractController;
use Plugin\AiChatAssistant42\Service\AiModelRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * AI モデル一覧を返す API。
 *
 * フロントエンドのチャット設定 UI から呼び出され、
 * 利用可能なプロバイダとモデルの一覧を返す。
 */
class ModelApiController extends AbstractController
{
    public function __construct(
        private AiModelRegistry $aiModelRegistry,
    ) {
    }

    /**
     * 利用可能なプロバイダとモデルの一覧を返す。
     *
     * @return JsonResponse
     */
    public function list(): JsonResponse
    {
        $providers = $this->aiModelRegistry->getProviders();

        // 各プロバイダにモデル一覧を付与
        $providersWithModels = array_map(
            fn (array $provider): array => array_merge($provider, [
                'models' => $this->aiModelRegistry->getModels($provider['key']),
            ]),
            $providers,
        );

        return $this->json([
            'success' => true,
            'version' => $this->aiModelRegistry->getVersion(),
            'providers' => $providersWithModels,
        ]);
    }
}
