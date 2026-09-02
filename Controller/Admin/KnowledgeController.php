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
use Plugin\AiChatAssistant42\Entity\Knowledge;
use Plugin\AiChatAssistant42\Repository\KnowledgeRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ナレッジベース管理コントローラ。
 *
 * AI チャットが参照する FAQ や商品知識の CRUD を管理画面で提供する。
 */
class KnowledgeController extends AbstractController
{
    public function __construct(
        private KnowledgeRepository $knowledgeRepository,
    ) {
    }

    /**
     * ナレッジ一覧を表示する。
     */
    public function index(Request $request): Response
    {
        $keyword = $request->query->get('keyword', '');
        $category = $request->query->get('category', '');

        if ($keyword !== '' || $category !== '') {
            $entities = $this->knowledgeRepository->getQueryBuilder();

            if ($category !== '') {
                $entities->andWhere('k.category = :category')
                    ->setParameter('category', $category);
            }

            if ($keyword !== '') {
                $entities->andWhere($entities->expr()->orX(
                    'k.title LIKE :keyword',
                    'k.content LIKE :keyword'
                ))
                    ->setParameter('keyword', '%' . $keyword . '%');
            }

            $entities = $entities->getQuery()->getResult();
        } else {
            $entities = $this->knowledgeRepository->findAll();
        }

        return $this->render('@AiChatAssistant42/admin/knowledge.twig', [
            'entities' => $entities,
            'keyword' => $keyword,
            'category' => $category,
        ]);
    }

    /**
     * 新規作成フォームを表示する。
     */
    public function create(Request $request): Response
    {
        $knowledge = new Knowledge();

        $form = $this->createFormBuilder($knowledge)
            ->add('title', TextType::class, [
                'attr' => ['maxlength' => 255],
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => 'タイトルを入力してください。']),
                    new Assert\Length(['max' => 255, 'maxMessage' => 'タイトルは255文字以内で入力してください。']),
                ],
            ])
            ->add('content', TextareaType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => '内容を入力してください。']),
                ],
            ])
            ->add('category', TextType::class, [
                'required' => false,
                'attr' => ['maxlength' => 64],
                'constraints' => [
                    new Assert\Length(['max' => 64, 'maxMessage' => 'カテゴリは64文字以内で入力してください。']),
                ],
            ])
            ->add('is_active', ChoiceType::class, [
                'choices' => ['有効' => 1, '無効' => 0],
                'expanded' => true,
                'multiple' => false,
            ])
            ->add('display_order', IntegerType::class, [
                'required' => false,
                'constraints' => [
                    new Assert\Range(['min' => 0, 'max' => 99999, 'notInRangeMessage' => '表示順は0〜99999で入力してください。']),
                ],
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $knowledge->setCreateDate($now);
            $knowledge->setUpdateDate($now);

            $this->knowledgeRepository->save($knowledge);

            $this->addSuccess('登録が完了しました。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_knowledge_index');
        }

        return $this->render('@AiChatAssistant42/admin/knowledge_form.twig', [
            'form' => $form->createView(),
            'knowledge' => $knowledge,
        ]);
    }

    /**
     * 編集フォームを表示する。
     */
    public function edit(Request $request, int $id): Response
    {
        $knowledge = $this->knowledgeRepository->find($id);
        if ($knowledge === null) {
            $this->addError('指定されたナレッジが見つかりません。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_knowledge_index');
        }

        $form = $this->createFormBuilder($knowledge)
            ->add('title', TextType::class, [
                'attr' => ['maxlength' => 255],
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => 'タイトルを入力してください。']),
                    new Assert\Length(['max' => 255, 'maxMessage' => 'タイトルは255文字以内で入力してください。']),
                ],
            ])
            ->add('content', TextareaType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => '内容を入力してください。']),
                ],
            ])
            ->add('category', TextType::class, [
                'required' => false,
                'attr' => ['maxlength' => 64],
                'constraints' => [
                    new Assert\Length(['max' => 64, 'maxMessage' => 'カテゴリは64文字以内で入力してください。']),
                ],
            ])
            ->add('is_active', ChoiceType::class, [
                'choices' => ['有効' => 1, '無効' => 0],
                'expanded' => true,
                'multiple' => false,
            ])
            ->add('display_order', IntegerType::class, [
                'required' => false,
                'constraints' => [
                    new Assert\Range(['min' => 0, 'max' => 99999, 'notInRangeMessage' => '表示順は0〜99999で入力してください。']),
                ],
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $knowledge->setUpdateDate(new \DateTimeImmutable());
            $this->knowledgeRepository->save($knowledge);

            $this->addSuccess('更新が完了しました。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_knowledge_index');
        }

        return $this->render('@AiChatAssistant42/admin/knowledge_form.twig', [
            'form' => $form->createView(),
            'knowledge' => $knowledge,
        ]);
    }

    /**
     * ナレッジを削除する。
     */
    public function delete(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('admin_ai_chat_assistant_knowledge_' . $id, $request->request->get('_token'))) {
            $this->addError('不正なリクエストです。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_knowledge_index');
        }

        $knowledge = $this->knowledgeRepository->find($id);
        if ($knowledge !== null) {
            $this->knowledgeRepository->remove($knowledge);
            $this->addSuccess('削除が完了しました。', 'admin');
        }

        return $this->redirectToRoute('admin_ai_chat_assistant_knowledge_index');
    }
}
