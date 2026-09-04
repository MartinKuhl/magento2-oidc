<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions\Passkey;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use M2Oidc\OAuth\Controller\Actions\BaseAction;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Helper\PasskeySecurityHelper;
use M2Oidc\OAuth\Model\Passkey\PasskeyAuthenticationService;
use M2Oidc\OAuth\Model\Security\OidcRateLimiter;

/**
 * Anonymous endpoint: verifies the browser's WebAuthn assertion and, on
 * success, hands the login off to PasskeyCustomerCallback via a one-time
 * nonce cookie — the same clean-HTTP-context handoff CustomerLoginAction /
 * CustomerOidcCallback use for OIDC, so CustomerSession::setCustomerAsLoggedIn()
 * runs outside this AJAX request's context.
 */
class LoginVerify extends BaseAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        private readonly JsonFactory $jsonFactory,
        private readonly PasskeyAuthenticationService $authenticationService,
        private readonly PasskeySecurityHelper $securityHelper,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly OidcRateLimiter $rateLimiter
    ) {
        parent::__construct($context, $oauthUtility);
    }

    #[\Override]
    public function execute()
    {
        $json = $this->jsonFactory->create();

        $clientIp = $this->getRequest()->getClientIp();
        if (!$this->rateLimiter->isAllowed($clientIp)) {
            return $json->setData(['error' => (string) __('Too many attempts. Please try again later.')]);
        }

        $nonce = (string) $this->getRequest()->getParam('nonce', '');
        $credentialJson = (string) $this->getRequest()->getParam('credential', '');
        $relayState = (string) $this->getRequest()->getParam('relay_state', '');
        if ($nonce === '' || $credentialJson === '') {
            return $json->setData(['error' => (string) __('Invalid passkey login request.')]);
        }

        try {
            $stored = $this->authenticationService->verifyAssertion($nonce, $credentialJson);
        } catch (\Throwable $e) {
            $this->oauthUtility->customlog('Passkey customer LoginVerify: verification failed — ' . $e->getMessage());
            return $json->setData(['error' => (string) __('Passkey verification failed. Please try again.')]);
        }

        if ($stored->userType !== 'customer') {
            $this->oauthUtility->customlog('Passkey customer LoginVerify: credential belongs to a non-customer user');
            return $json->setData(['error' => (string) __('This passkey is not registered for customer login.')]);
        }

        try {
            $email = $this->customerRepository->getById($stored->userId)->getEmail();
        } catch (NoSuchEntityException) {
            $email = null;
        }
        if ($email === null || $email === '') {
            $this->oauthUtility->customlog('Passkey customer LoginVerify: customer not found for id ' . $stored->userId);
            return $json->setData(['error' => (string) __('Customer account not found.')]);
        }

        if ($relayState === '') {
            $relayState = $this->oauthUtility->getBaseUrl() . 'customer/account';
        }
        $loginNonce = $this->securityHelper->createCustomerPasskeyLoginNonce($email, $relayState);

        try {
            $cookieMetadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setDuration(120)
                ->setPath('/')
                ->setHttpOnly(true)
                ->setSecure(true)
                ->setSameSite('Lax');
            $this->cookieManager->setPublicCookie('m2passkey_customer_nonce', $loginNonce, $cookieMetadata);
        } catch (\Exception $e) {
            $this->oauthUtility->customlog('Passkey customer LoginVerify: error setting nonce cookie: ' . $e->getMessage());
            return $json->setData(['error' => (string) __('Authentication failed. Please try again.')]);
        }

        return $json->setData([
            'success' => true,
            'redirectUrl' => $this->oauthUtility->getBaseUrl() . 'm2oidc/actions_passkey/passkeycustomercallback',
        ]);
    }
}
