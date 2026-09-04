<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions\Passkey;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use M2Oidc\OAuth\Controller\Actions\BaseAction;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Passkey\PasskeyRegistrationService;

/**
 * Self-service: verifies the browser's attestation and stores the new
 * passkey against the currently logged-in customer's own account.
 */
class RegistrationVerify extends BaseAction implements HttpPostActionInterface
{
    private const MAX_NICKNAME_LENGTH = 191;

    /**
     * @param Context                     $context
     * @param OAuthUtility                $oauthUtility
     * @param JsonFactory                 $jsonFactory
     * @param CustomerSession             $customerSession
     * @param PasskeyRegistrationService  $registrationService
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly PasskeyRegistrationService $registrationService
    ) {
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Verify the browser's attestation and persist the new passkey for the logged-in customer.
     */
    #[\Override]
    public function execute()
    {
        $json = $this->jsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $json->setData(['error' => (string) __('Not authenticated.')]);
        }
        $customerId = (int) $this->customerSession->getCustomerId();

        $nonce = (string) $this->getRequest()->getParam('nonce', '');
        $credentialJson = (string) $this->getRequest()->getParam('credential', '');
        $nickname = trim((string) $this->getRequest()->getParam('nickname', ''));
        $nickname = $nickname !== '' ? mb_substr($nickname, 0, self::MAX_NICKNAME_LENGTH) : null;

        if ($nonce === '' || $credentialJson === '') {
            return $json->setData(['error' => (string) __('Invalid passkey registration request.')]);
        }

        try {
            $this->registrationService->verifyAndPersist($nonce, $credentialJson, 'customer', $customerId, $nickname);
        } catch (\Throwable $e) {
            $this->oauthUtility->customlog('Passkey customer RegistrationVerify: ' . $e->getMessage());
            return $json->setData(['error' => (string) __('Passkey registration failed. Please try again.')]);
        }

        return $json->setData(['success' => true]);
    }
}
