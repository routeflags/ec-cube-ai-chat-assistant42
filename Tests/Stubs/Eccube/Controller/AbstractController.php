<?php
namespace Eccube\Controller;
class AbstractController {
    protected $eccubeConfig; protected $entityManager; protected $translator; protected $session;
    public function setEccubeConfig($v) { $this->eccubeConfig = $v; }
    public function setEntityManager($v) { $this->entityManager = $v; }
    public function setTranslator($v) { $this->translator = $v; }
    public function setSession($v) { $this->session = $v; }
    protected function render($view, array $parameters = [], $response = null) { return null; }
    protected function redirectToRoute($route, $parameters = [], $status = 302) { return null; }
    protected function addFlash($type, $message) {}
    protected function isGranted($attribute, $subject = null) { return true; }
    protected function getUser() { return null; }
    protected function isCsrfTokenValid(string $id, ?string $token): bool { return true; }
    protected function isTokenValid(): bool { return true; }
    protected function createForm($type, $data = null, array $options = []) { return null; }
    public function getParameter(string $name) { return null; }
    protected function getSubscribedServices(): array { return []; }
    protected function generateUrl($route, $parameters = [], $referenceType = 0) { return "/$route"; }
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
