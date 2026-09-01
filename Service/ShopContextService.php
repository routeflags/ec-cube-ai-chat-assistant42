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

namespace Plugin\AiChatAssistant42\Service;

use Eccube\Repository\BaseInfoRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * ショップ固有情報（URL/名称）を解決する。
 *
 * 固定ドメイン文字列への依存を避け、実行環境ごとの URL を安全に生成する。
 */
class ShopContextService
{
    private const DEFAULT_SHOP_NAME = 'このショップ';

    public function __construct(
        private BaseInfoRepository $baseInfoRepository,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getShopName(): string
    {
        $shopName = $this->baseInfoRepository->get()->getShopName() ?? '';

        return trim($shopName) !== '' ? $shopName : self::DEFAULT_SHOP_NAME;
    }

    public function getProductDetailUrl(int $productId): string
    {
        try {
            return $this->urlGenerator->generate('product_detail', ['id' => $productId], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            return $this->buildAbsolutePath('/products/detail/' . $productId);
        }
    }

    public function getHelpGuideUrl(): string
    {
        return $this->buildAbsolutePath('/help_guide');
    }

    public function getHelpGuideFaqUrl(): string
    {
        return $this->buildAbsolutePath('/help_guide#faq');
    }

    public function getGuideArticleUrl(string $slug, ?string $categorySlug): string
    {
        if ($slug === '') {
            return $this->buildAbsolutePath('/guide');
        }

        $path = $categorySlug !== null && $categorySlug !== ''
            ? '/guide/' . $categorySlug . '/' . $slug
            : '/guide/' . $slug;

        return $this->buildAbsolutePath($path);
    }

    public function getAdminHistoryUrl(): string
    {
        try {
            return $this->urlGenerator->generate('admin_ai_chat_assistant_history', [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            return $this->buildAbsolutePath('/ai-chat-assistant/history');
        }
    }

    /**
     * 絶対URLのベースを返す。
     */
    public function getBaseUrl(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            return rtrim($request->getSchemeAndHttpHost() . $request->getBaseUrl(), '/');
        }

        $context = $this->urlGenerator->getContext();
        if ($context->getHost() === '') {
            return '';
        }

        return rtrim($context->getScheme() . '://' . $context->getHost() . $context->getBaseUrl(), '/');
    }

    public function buildAbsolutePath(string $path): string
    {
        $normalizedPath = '/' . ltrim($path, '/');
        $baseUrl = $this->getBaseUrl();

        if ($baseUrl === '') {
            return $normalizedPath;
        }

        return $baseUrl . $normalizedPath;
    }
}
