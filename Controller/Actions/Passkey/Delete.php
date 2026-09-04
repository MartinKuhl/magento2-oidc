<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions\Passkey;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use M2Oidc\OAuth\Controller\Actions\BaseAction;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredentialRepository;

/**
 * Self-service: deletes one of the currently logged-in customer's own
 * passkeys. Scoped by user_type/user_id at the repository level.
 */
class Delete extends BaseAction implements HttpPostActionInterface
{
    /**
     * @param Context                      $context
     * @param OAuthUtility                 $oauthUtility
     * @param JsonFactory                  $jsonFactory
     * @param CustomerSession              $customerSession
     * @param PasskeyCredentialRepository  $credentialRepository
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly PasskeyCredentialRepository $credentialRepository
    ) {
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Delete the requesting customer's own passkey by credential ID.
     */
    #[\Override]
    public function execute()
    {
        $json = $this->jsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $json->setData(['error' => (string) __('Not authenticated.')]);
        }
        $customerId = (int) $this->customerSession->getCustomerId();

        $credentialId = (int) $this->getRequest()->getParam('credential_id', 0);
        if ($credentialId <= 0) {
            return $json->setData(['error' => (string) __('Invalid credential.')]);
        }

        $deleted = $this->credentialRepository->deleteOwnedCredential($credentialId, 'customer', $customerId);
        if (!$deleted) {
            return $json->setData(['error' => (string) __('Passkey not found.')]);
        }

        $this->oauthUtility->customlog(
            'Passkey customer Delete: credential #' . $credentialId . ' removed by customer #' . $customerId
        );

        return $json->setData(['success' => true]);
    }
}
