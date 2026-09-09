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

/**
 * チャット初回送信時のページコンテキストを扱うサービス。
 *
 * フロントの chat-widget.js が初回メッセージに添付する page_context
 * （閲覧中ページの URL・タイトル・商品ID）を検証し、AI への
 * システムプロンプト用ブロック文字列を組み立てる。
 *
 * プライバシー配慮:
 * - URL の hash 断片（トークンを含みうる）は除去する
 * - 在庫数は出力せず、あり/なしのサマリのみ（詳細は get_stock ツールに委譲）
 */
class PageContextService
{
    public const MAX_URL_LENGTH = 500;
    public const MAX_TITLE_LENGTH = 200;
    public const MAX_DESCRIPTION_LENGTH = 300;

    /**
     * リクエストの page_context を検証・正規化する。
     *
     * 不正・空の場合は null を返す（チャット自体は継続させるため 400 にはしない）。
     *
     * @param mixed $raw page_context の raw 値
     *
     * @return array{url?: string, title?: string, product_id?: int}|null
     */
    public function parse(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $context = [];

        if (isset($raw['url']) && is_string($raw['url'])) {
            $url = trim($raw['url']);
            // hash 断片はセッショントークン等を含みうるため除去
            $hashPos = strpos($url, '#');
            if ($hashPos !== false) {
                $url = substr($url, 0, $hashPos);
            }
            $url = trim($url);
            if ($url !== '') {
                $context['url'] = mb_substr($url, 0, self::MAX_URL_LENGTH);
            }
        }

        if (isset($raw['title']) && is_string($raw['title'])) {
            $title = trim($raw['title']);
            if ($title !== '') {
                $context['title'] = mb_substr($title, 0, self::MAX_TITLE_LENGTH);
            }
        }

        if (isset($raw['product_id'])) {
            $productId = filter_var(
                $raw['product_id'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($productId !== false) {
                $context['product_id'] = $productId;
            }
        }

        return $context === [] ? null : $context;
    }

    /**
     * システムプロンプトに追記するページコンテキストブロックを組み立てる。
     *
     * @param array{url?: string, title?: string, product_id?: int} $context parse() の戻り値
     * @param array<string, mixed>|null $product ProductRepository::getDetail() の戻り値（取得不可時は null）
     */
    public function buildBlock(array $context, ?array $product): string
    {
        $lines = ["\n\n## ユーザーが閲覧中のページ（初回メッセージ送信時）"];

        if (isset($context['url'])) {
            $lines[] = '- URL: ' . $context['url'];
        }
        if (isset($context['title'])) {
            $lines[] = '- タイトル: ' . $context['title'];
        }

        if ($product !== null) {
            $lines[] = '- 閲覧中の商品: ' . ($product['name'] ?? '') . ' (ID: ' . ($product['id'] ?? '') . ')';

            $priceRange = $this->formatPriceRange($product['classes'] ?? []);
            if ($priceRange !== null) {
                $lines[] = '  - 価格: ' . $priceRange;
            }

            $stockRows = $product['stock'] ?? [];
            if (is_array($stockRows) && $stockRows !== []) {
                $lines[] = '  - 在庫: ' . ($this->hasStock($stockRows) ? 'あり' : 'なし');
            }

            $description = trim((string) ($product['description_list'] ?? ''));
            if ($description !== '') {
                $lines[] = '  - 商品説明: ' . mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH);
            }
        } elseif (isset($context['product_id'])) {
            $lines[] = '- 閲覧中の商品ID: ' . $context['product_id'] . '（商品情報は取得できませんでした）';
        }

        $lines[] = '「この商品」「これ」「いくら」などの指示語は、閲覧中のページ・商品を指すものとして回答してください。';

        return implode("\n", $lines);
    }

    /**
     * 規格価格一覧から価格帯文字列を作る（price02 は税込）。
     *
     * @param array<int, array<string, mixed>> $classes
     */
    private function formatPriceRange(array $classes): ?string
    {
        $prices = [];
        foreach ($classes as $class) {
            if (isset($class['price']) && is_numeric($class['price'])) {
                $prices[] = (float) $class['price'];
            }
        }
        if ($prices === []) {
            return null;
        }

        $min = min($prices);
        $max = max($prices);
        if ($min === $max) {
            return $this->formatPrice($min);
        }

        return $this->formatPrice($min) . '〜' . $this->formatPrice($max);
    }

    private function formatPrice(float $price): string
    {
        return number_format($price) . '円（税込）';
    }

    /**
     * 在庫の有無を判定する（正確な数は出力しない）。
     *
     * @param array<int, array<string, mixed>> $stockRows
     */
    private function hasStock(array $stockRows): bool
    {
        foreach ($stockRows as $row) {
            if (!empty($row['stock_unlimited'])) {
                return true;
            }
            if (isset($row['stock']) && $row['stock'] !== null && (int) $row['stock'] > 0) {
                return true;
            }
        }

        return false;
    }
}
