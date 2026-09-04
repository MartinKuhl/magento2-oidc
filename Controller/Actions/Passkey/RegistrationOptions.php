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
 * Self-service: an already-logged-in customer starts registering a passkey
 * for their own account, from the "Passkeys" section of My Account.
 */
class RegistrationOptions extends BaseAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly PasskeyRegistrationService $registrationService
    ) {
        parent::__construct($context, $oauthUtility);
    }

    #[\Override]
    public function execute()
    {
        $json = $this->jsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $json->setData(['error' => (string) __('Not authenticated.')]);
        }
        $customer = $this->customerSession->getCustomer();

        try {
            $result = $this->registrationService->buildCreationOptions(
                'customer',
                (int) $customer->getId(),
                (string) $customer->getEmail(),
                trim((string) $customer->getFirstname() . ' ' . (string) $customer->getLastname()) ?: (string) $customer->getEmail()
            );
        } catch (\Throwable $e) {
            $this->oauthUtility->customlog('Passkey customer RegistrationOptions: ' . $e->getMessage());
            return $json->setData(['error' => (string) __('Unable to start passkey registration.')]);
        }

        return $json->setData([
            'success' => true,
            'nonce' => $result['nonce'],
            'options' => json_decode($result['optionsJson'], true),
        ]);
    }
}
