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
use Plugin\AiChatAssistant42\Entity\Scenario;
use Plugin\AiChatAssistant42\Repository\ScenarioRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Validator\Constraints as Assert;
use DateTimeImmutable;

/**
 * 自動応答シナリオ管理コントローラ。
 *
 * ユーザー入力に対する定型応答の CRUD を管理画面で提供する。
 */
class ScenarioController extends AbstractController
{
    public function __construct(
        private ScenarioRepository $scenarioRepository,
    ) {
    }

    /**
     * シナリオ一覧を表示する。
     */
    public function index(Request $request): Response
    {
        $keyword = $request->query->get('keyword', '');

        if ($keyword !== '') {
            $entities = $this->scenarioRepository->getQueryBuilder()
                ->andWhere($this->scenarioRepository->getQueryBuilder()->expr()->orX(
                    's.trigger_keyword LIKE :keyword',
                    's.response_text LIKE :keyword'
                ))
                ->setParameter('keyword', '%' . $keyword . '%')
                ->getQuery()
                ->getResult();
        } else {
            $entities = $this->scenarioRepository->findAll();
        }

        return $this->render('@AiChatAssistant42/admin/scenario.twig', [
            'entities' => $entities,
            'keyword' => $keyword,
        ]);
    }

    /**
     * 新規作成フォームを表示する。
     */
    public function create(Request $request): Response
    {
        $scenario = new Scenario();

        $form = $this->createFormBuilder($scenario)
            ->add('trigger_keyword', TextType::class, [
                'attr' => ['maxlength' => 128],
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => 'キーワードを入力してください。']),
                    new Assert\Length(['max' => 128, 'maxMessage' => 'キーワードは128文字以内で入力してください。']),
                ],
            ])
            ->add('trigger_type', ChoiceType::class, [
                'choices' => [
                    '完全一致 (exact)' => 'exact',
                    '部分一致 (contains)' => 'contains',
                    '正規表現 (regex)' => 'regex',
                ],
            ])
            ->add('response_text', TextareaType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => '応答テキストを入力してください。']),
                    new Assert\Length(['max' => 2000, 'maxMessage' => '応答テキストは2000文字以内で入力してください。']),
                ],
            ])
            ->add('response_type', ChoiceType::class, [
                'choices' => [
                    'テキスト (text)' => 'text',
                    '商品一覧 (product_list)' => 'product_list',
                    'URL (url)' => 'url',
                ],
            ])
            ->add('priority', IntegerType::class, [
                'required' => false,
                'constraints' => [
                    new Assert\Range(['min' => 0, 'max' => 99999, 'notInRangeMessage' => '優先度は0〜99999で入力してください。']),
                ],
            ])
            ->add('is_active', ChoiceType::class, [
                'choices' => ['有効' => 1, '無効' => 0],
                'expanded' => true,
                'multiple' => false,
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new DateTimeImmutable();
            $scenario->setCreateDate($now);
            $scenario->setUpdateDate($now);

            $this->scenarioRepository->save($scenario);

            $this->addSuccess('登録が完了しました。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_scenario_index');
        }

        return $this->render('@AiChatAssistant42/admin/scenario_form.twig', [
            'form' => $form->createView(),
            'scenario' => $scenario,
        ]);
    }

    /**
     * 編集フォームを表示する。
     */
    public function edit(Request $request, int $id): Response
    {
        $scenario = $this->scenarioRepository->find($id);
        if ($scenario === null) {
            $this->addError('指定されたシナリオが見つかりません。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_scenario_index');
        }

        $form = $this->createFormBuilder($scenario)
            ->add('trigger_keyword', TextType::class, [
                'attr' => ['maxlength' => 128],
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => 'キーワードを入力してください。']),
                    new Assert\Length(['max' => 128, 'maxMessage' => 'キーワードは128文字以内で入力してください。']),
                ],
            ])
            ->add('trigger_type', ChoiceType::class, [
                'choices' => [
                    '完全一致 (exact)' => 'exact',
                    '部分一致 (contains)' => 'contains',
                    '正規表現 (regex)' => 'regex',
                ],
            ])
            ->add('response_text', TextareaType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => '応答テキストを入力してください。']),
                    new Assert\Length(['max' => 2000, 'maxMessage' => '応答テキストは2000文字以内で入力してください。']),
                ],
            ])
            ->add('response_type', ChoiceType::class, [
                'choices' => [
                    'テキスト (text)' => 'text',
                    '商品一覧 (product_list)' => 'product_list',
                    'URL (url)' => 'url',
                ],
            ])
            ->add('priority', IntegerType::class, [
                'required' => false,
                'constraints' => [
                    new Assert\Range(['min' => 0, 'max' => 99999, 'notInRangeMessage' => '優先度は0〜99999で入力してください。']),
                ],
            ])
            ->add('is_active', ChoiceType::class, [
                'choices' => ['有効' => 1, '無効' => 0],
                'expanded' => true,
                'multiple' => false,
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $scenario->setUpdateDate(new DateTimeImmutable());
            $this->scenarioRepository->save($scenario);

            $this->addSuccess('更新が完了しました。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_scenario_index');
        }

        return $this->render('@AiChatAssistant42/admin/scenario_form.twig', [
            'form' => $form->createView(),
            'scenario' => $scenario,
        ]);
    }

    /**
     * シナリオを削除する。
     */
    public function delete(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('admin_ai_chat_assistant_scenario_' . $id, $request->request->get('_token'))) {
            $this->addError('不正なリクエストです。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_scenario_index');
        }

        $scenario = $this->scenarioRepository->find($id);
        if ($scenario !== null) {
            $this->scenarioRepository->remove($scenario);
            $this->addSuccess('削除が完了しました。', 'admin');
        }

        return $this->redirectToRoute('admin_ai_chat_assistant_scenario_index');
    }
}
