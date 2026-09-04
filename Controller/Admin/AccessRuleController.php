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

namespace Plugin\AiChatAssistant42\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\AiChatAssistant42\Entity\AccessRule;
use Plugin\AiChatAssistant42\Repository\AccessRuleRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use DateTimeImmutable;

/**
 * アクセスルールの CRUD を管理するコントローラ。
 *
 * IP アドレス・利用時間帯・禁止キーワードによる
 * チャットアクセス制御ルールを登録・編集・削除する。
 */
class AccessRuleController extends AbstractController
{
    /** @var string[] ルール種別一覧 */
    private const RULE_TYPES = ['ip', 'time', 'block_keyword'];

    /** @var string[] 適用アクション一覧 */
    private const ACTIONS = ['deny', 'throttle', 'allow'];

    public function __construct(
        private AccessRuleRepository $accessRuleRepository,
    ) {
    }

    /**
     * アクセスルール一覧を表示する。
     */
    public function index(): Response
    {
        $rules = $this->accessRuleRepository->findAllActive();

        return $this->render('@AiChatAssistant42/admin/access_rule.twig', [
            'rules' => $rules,
            'rule_types' => self::RULE_TYPES,
            'actions' => self::ACTIONS,
        ]);
    }

    /**
     * アクセスルールを新規作成する。
     */
    public function create(Request $request): RedirectResponse
    {
        try {
            $this->isTokenValid();
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            $this->addError('CSRFトークンが無効です。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_access_index');
        }

        $ruleValue = $request->request->get('rule_value', '');
        if ($ruleValue === '') {
            $this->addError('ルール値を入力してください。', 'admin');
            return $this->redirectToRoute('admin_ai_chat_assistant_access_index');
        }

        $rule = new AccessRule();
        $rule->setRuleType($request->request->get('rule_type', 'ip'));
        $rule->setRuleValue($ruleValue);
        $rule->setAction($request->request->get('action', 'deny'));
        $rule->setIsActive(1);
        $rule->setCreateDate(new DateTimeImmutable());
        $rule->setUpdateDate(new DateTimeImmutable());

        $this->accessRuleRepository->save($rule);

        $this->addSuccess('アクセスルールを作成しました。', 'admin');

        return $this->redirectToRoute('admin_ai_chat_assistant_access_index');
    }

    /**
     * アクセスルールを編集する。
     */
    public function edit(Request $request, int $id): RedirectResponse
    {
        try {
            $this->isTokenValid();
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            $this->addError('CSRFトークンが無効です。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_access_index');
        }

        $rule = $this->accessRuleRepository->find($id);
        if ($rule === null) {
            $this->addError('指定されたアクセスルールが見つかりません。', 'admin');
            return $this->redirectToRoute('admin_ai_chat_assistant_access_index');
        }

        $ruleValue = $request->request->get('rule_value', $rule->getRuleValue());
        if ($ruleValue === '') {
            $this->addError('ルール値を入力してください。', 'admin');
            return $this->redirectToRoute('admin_ai_chat_assistant_access_index');
        }

        $rule->setRuleType($request->request->get('rule_type', $rule->getRuleType()));
        $rule->setRuleValue($ruleValue);
        $rule->setAction($request->request->get('action', $rule->getAction()));
        $rule->setIsActive((int) $request->request->get('is_active', $rule->getIsActive()));
        $rule->setUpdateDate(new DateTimeImmutable());

        $this->accessRuleRepository->save($rule);

        $this->addSuccess('アクセスルールを更新しました。', 'admin');

        return $this->redirectToRoute('admin_ai_chat_assistant_access_index');
    }

    /**
     * アクセスルールを削除する。
     */
    public function delete(Request $request, int $id): RedirectResponse
    {
        try {
            $this->isTokenValid();
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            $this->addError('CSRFトークンが無効です。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_access_index');
        }
        // $request は getMethod() で参照する（監査ログ欠落を解消 — CSRF 検証を先に行いメソッド不一致も監査対象とする）
        if ('POST' !== $request->getMethod()) {
            $this->addError('不正なリクエストです。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_access_index');
        }

        $rule = $this->accessRuleRepository->find($id);
        if ($rule === null) {
            $this->addError('指定されたアクセスルールが見つかりません。', 'admin');
            return $this->redirectToRoute('admin_ai_chat_assistant_access_index');
        }

        $this->accessRuleRepository->delete($rule);

        $this->addSuccess('アクセスルールを削除しました。', 'admin');

        return $this->redirectToRoute('admin_ai_chat_assistant_access_index');
    }
}
