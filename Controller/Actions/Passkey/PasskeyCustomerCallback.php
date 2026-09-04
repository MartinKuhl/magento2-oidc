<?php

declare(strict_types=1);

/**
 * Passkey Customer Callback Controller
 *
 * Finishes a customer passkey login in a clean HTTP context. Mirrors
 * Controller/Actions/CustomerOidcCallback.php — see that class for the
 * rationale of the nonce-cookie handoff and the regenerateId() caveat.
 *
 * @package M2Oidc\OAuth\Controller\Actions\Passkey
 */
namespace M2Oidc\OAuth\Controller\Actions\Passkey;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use M2Oidc\OAuth\Controller\Actions\BaseAction;
use M2Oidc\OAuth\Helper\OAuthSecurityHelper;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Helper\PasskeySecurityHelper;

/**
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class PasskeyCustomerCallback extends BaseAction
{
    /**
     * @param Context                     $context
     * @param OAuthUtility                $oauthUtility
     * @param CustomerFactory             $customerFactory
     * @param CustomerSession             $customerSession
     * @param StoreManagerInterface       $storeManager
     * @param CookieManagerInterface      $cookieManager
     * @param CookieMetadataFactory       $cookieMetadataFactory
     * @param PasskeySecurityHelper       $securityHelper
     * @param CustomerRepositoryInterface $customerRepository
     * @param OAuthSecurityHelper         $oauthSecurityHelper
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        private readonly CustomerFactory $customerFactory,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly PasskeySecurityHelper $securityHelper,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly OAuthSecurityHelper $oauthSecurityHelper
    ) {
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Redeem the passkey login nonce in a clean HTTP context and establish the customer session.
     */
    #[\Override]
    public function execute(): Redirect
    {
        $this->oauthUtility->customlog("PasskeyCustomerCallback: Starting customer authentication");

        $nonce = $this->cookieManager->getCookie('m2passkey_customer_nonce');
        if ($nonce !== null) {
            try {
                $cookieMetadata = $this->cookieMetadataFactory->createCookieMetadata()->setPath('/');
                $this->cookieManager->deleteCookie('m2passkey_customer_nonce', $cookieMetadata);
            } catch (\Exception $e) {
                $this->oauthUtility->customlog("PasskeyCustomerCallback: Error deleting nonce: " . $e->getMessage());
            }
        }

        if (empty($nonce)) {
            return $this->redirectToLoginWithError('Authentication failed. Please try again.');
        }

        $nonceData = $this->securityHelper->redeemCustomerPasskeyLoginNonce($nonce);
        if ($nonceData === null) {
            return $this->redirectToLoginWithError('Authentication session expired. Please try again.');
        }

        $email = $nonceData['email'];
        $relayState = $nonceData['relayState'];

        $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
        try {
            $customerData = $this->customerRepository->get($email, $websiteId);
            $customerId = $customerData->getId();
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            return $this->redirectToLoginWithError('Authentication failed. Please try again.');
        }

        /** @phpstan-ignore-next-line */
        $customerModel = $this->customerFactory->create()->load((int) $customerId);
        if (!$customerModel->getId()) {
            return $this->redirectToLoginWithError('Authentication failed. Please try again.');
        }

        if ((int) $customerModel->getWebsiteId() !== $websiteId) {
            $this->oauthUtility->customlog(
                "PasskeyCustomerCallback: Blocked cross-website login. Customer website: "
                . $customerModel->getWebsiteId() . ", Current store website: " . $websiteId
            );
            return $this->redirectToLoginWithError(
                'Authentication failed: This account is not registered on this website.'
            );
        }

        $this->customerSession->setCustomerAsLoggedIn($customerModel);
        // NOTE: Do NOT call regenerateId() here — setCustomerAsLoggedIn() already
        // regenerates the session internally; calling it again causes a session ID
        // mismatch between server and browser (same caveat as CustomerOidcCallback).

        $this->oauthUtility->customlog(
            "PasskeyCustomerCallback: Login " . ($this->customerSession->isLoggedIn() ? "successful" : "FAILED")
            . " for customer ID: " . $customerModel->getId()
        );

        $defaultRedirect = $this->oauthUtility->getBaseUrl() . 'customer/account';
        $safeRedirect = (empty($relayState) || $relayState === '/')
            ? $defaultRedirect
            : $this->oauthSecurityHelper->validateRedirectUrl($relayState, $defaultRedirect);

        if (str_starts_with($safeRedirect, '/')) {
            $safeRedirect = rtrim($this->oauthUtility->getBaseUrl(), '/') . $safeRedirect;
        }

        return $this->resultRedirectFactory->create()->setUrl($safeRedirect);
    }

    /**
     * Redirect to the customer login page with a Base64-encoded error message.
     *
     * @param string $message
     */
    private function redirectToLoginWithError(string $message): Redirect
    {
        $encodedError = base64_encode($message);
        $loginUrl = $this->oauthUtility->getCustomerLoginUrl() . '?oidc_error=' . $encodedError;
        return $this->resultRedirectFactory->create()->setUrl($loginUrl);
    }
}
