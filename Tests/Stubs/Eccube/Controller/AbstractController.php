<?php
namespace Eccube\Controller;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AbstractController {
    protected $eccubeConfig; protected $entityManager; protected $translator; protected $session;
    public function setEccubeConfig($v) { $this->eccubeConfig = $v; }
    public function setEntityManager($v) { $this->entityManager = $v; }
    public function setTranslator($v) { $this->translator = $v; }
    public function setSession($v) { $this->session = $v; }
    protected function render($view, array $parameters = [], $response = null): Response { return new Response(); }
    protected function redirectToRoute($route, $parameters = [], $status = 302): RedirectResponse { return new RedirectResponse('/'); }
    protected function addFlash($type, $message) {}
    protected function isGranted($attribute, $subject = null) { return true; }
    protected function getUser() { return null; }
    protected function isCsrfTokenValid(string $id, ?string $token): bool { return true; }
    protected function isTokenValid(): bool { return true; }
    protected function createForm($type, $data = null, array $options = []): FormInterface { throw new \LogicException('stub'); }
    protected function createFormBuilder($data = null, array $options = []): FormBuilderInterface { throw new \LogicException('stub'); }
    protected function json($data, int $status = 200, array $headers = [], array $context = []): JsonResponse { return new JsonResponse($data, $status, $headers); }
    protected function createNotFoundException(string $message = 'Not Found', ?\Throwable $previous = null): NotFoundHttpException { return new NotFoundHttpException($message, $previous); }
    public function getParameter(string $name): mixed { return null; }
    protected function getSubscribedServices(): array { return []; }
    protected function generateUrl($route, $parameters = [], $referenceType = 0): string { return "/$route"; }
    public function addSuccess($message, $namespace = 'front') {}
    public function addSuccessOnce($message, $namespace = 'front') {}
    public function addError($message, $namespace = 'front') {}
    public function addErrorOnce($message, $namespace = 'front') {}
    public function addDanger($message, $namespace = 'front') {}
    public function addDangerOnce($message, $namespace = 'front') {}
    public function addWarning($message, $namespace = 'front') {}
    public function addWarningOnce($message, $namespace = 'front') {}
    public function addInfo($message, $namespace = 'front') {}
    public function addInfoOnce($message, $namespace = 'front') {}
    public function addRequestError($message, $namespace = 'front') {}
    public function addRequestErrorOnce($message, $namespace = 'front') {}
    public function clearMessage() {}
}
