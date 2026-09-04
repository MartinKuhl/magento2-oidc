<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Actions\Passkey;

use Magento\Backend\Model\Auth;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Helper\PasskeySecurityHelper;
use M2Oidc\OAuth\Model\Passkey\PasskeyAuthenticationService;
use M2Oidc\OAuth\Model\Security\OidcRateLimiter;
use M2Oidc\OAuth\Model\Service\PasskeySessionService;

/**
 * Anonymous endpoint: verifies the browser's WebAuthn assertion and bridges
 * into native Magento admin auth. Mirrors Controller/Adminhtml/Actions/Oidccallback.php's
 * bridging logic, but is AJAX-driven (JSON in/out) rather than redirect-driven,
 * and does not extend Backend\App\Action so it can be reached pre-auth.
 */
class LoginVerify implements ActionInterface, HttpPostActionInterface
{
    /**
     * @param RequestInterface              $request
     * @param JsonFactory                   $jsonFactory
     * @param PasskeyAuthenticationService  $authenticationService
     * @param PasskeySecurityHelper         $securityHelper
     * @param UserCollectionFactory         $userCollectionFactory
     * @param Auth                          $auth
     * @param BackendUrlInterface           $backendUrl
     * @param OidcRateLimiter               $rateLimiter
     * @param OAuthUtility                  $oauthUtility
     * @param CookieManagerInterface        $cookieManager
     * @param CookieMetadataFactory         $cookieMetadataFactory
     * @param ScopeConfigInterface          $scopeConfig
     * @param PasskeySessionService         $passkeySessionService
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly PasskeyAuthenticationService $authenticationService,
        private readonly PasskeySecurityHelper $securityHelper,
        private readonly UserCollectionFactory $userCollectionFactory,
        private readonly Auth $auth,
        private readonly BackendUrlInterface $backendUrl,
        private readonly OidcRateLimiter $rateLimiter,
        private readonly OAuthUtility $oauthUtility,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PasskeySessionService $passkeySessionService
    ) {
    }

    /**
     * Verify the browser's WebAuthn assertion and bridge into native Magento admin auth.
     */
    #[\Override]
    public function execute()
    {
        $json = $this->jsonFactory->create();

        $clientIp = $this->request->getClientIp();
        if (!$this->rateLimiter->isAllowed($clientIp)) {
            return $json->setData(['error' => (string) __('Too many attempts. Please try again later.')]);
        }

        $nonce = (string) $this->request->getParam('nonce', '');
        $credentialJson = (string) $this->request->getParam('credential', '');
        if ($nonce === '' || $credentialJson === '') {
            return $json->setData(['error' => (string) __('Invalid passkey login request.')]);
        }

        try {
            $stored = $this->authenticationService->verifyAssertion($nonce, $credentialJson);
        } catch (\Throwable $e) {
            $this->oauthUtility->customlog('Passkey admin LoginVerify: verification failed — ' . $e->getMessage());
            return $json->setData(['error' => (string) __('Passkey verification failed. Please try again.')]);
        }

        if ($stored->userType !== 'admin') {
            $this->oauthUtility->customlog('Passkey admin LoginVerify: credential belongs to a non-admin user');
            return $json->setData(['error' => (string) __('This passkey is not registered for admin login.')]);
        }

        $adminModel = $this->userCollectionFactory->create()
            ->addFieldToFilter('user_id', ['eq' => $stored->userId])
            ->getFirstItem();
        $email = (string) $adminModel->getEmail();
        if ($email === '') {
            $this->oauthUtility->customlog('Passkey admin LoginVerify: admin user not found for id ' . $stored->userId);
            return $json->setData(['error' => (string) __('Admin account not found.')]);
        }

        $token = $this->securityHelper->createPasskeyAuthToken($email);

        try {
            $this->auth->login($email, $token);
        } catch (\Magento\Framework\Exception\AuthenticationException $e) {
            $this->oauthUtility->customlog('Passkey admin LoginVerify: authentication failed — ' . $e->getMessage());
            return $json->setData(['error' => (string) __($e->getMessage())]);
        }

        if (!$this->auth->isLoggedIn()) {
            return $json->setData(['error' => (string) __('Passkey authentication failed. Please try again.')]);
        }

        /** @psalm-suppress UndefinedInterfaceMethod */
        // @phpstan-ignore-next-line
        $this->auth->getAuthStorage()->setData('is_passkey_authenticated', true);

        // Register this session so a later passkey deletion can force-logout it
        // (only takes effect when "Auto-Logout on Passkey Deletion" is enabled).
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $this->passkeySessionService->registerSession('admin', $stored->userId, (string) session_id());

        // Persistent cookie counterpart to the session flag above — read by
        // OidcIdentityVerificationPlugin / OidcIdentityFieldPlugin, which run
        // in contexts (e.g. rendering the account-settings form) where the
        // session flag isn't a convenient signal. Mirrors Oidccallback's
        // 'oidc_authenticated' cookie exactly.
        $adminSessionLifetime = (int) $this->scopeConfig->getValue('admin/security/session_lifetime') ?: 3600;
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata();
        $metadata->setDuration($adminSessionLifetime);
        $metadata->setPath('/');
        $metadata->setHttpOnly(true);
        $metadata->setSameSite('Lax');
        $metadata->setSecure($this->request->isSecure());
        $this->cookieManager->setPublicCookie('passkey_authenticated', '1', $metadata);

        $this->oauthUtility->customlog('Passkey admin LoginVerify: login successful for ' . $email);

        return $json->setData([
            'success' => true,
            'redirectUrl' => $this->backendUrl->getUrl('admin/dashboard'),
        ]);
    }
}
