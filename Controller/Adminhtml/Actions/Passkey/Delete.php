<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Actions\Passkey;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredentialRepository;

/**
 * Self-service: deletes one of the currently authenticated admin's own
 * passkeys. Scoped by user_type/user_id at the repository level, so this
 * cannot delete another admin's credential — see
 * Controller/Adminhtml/Passkeysettings/Delete.php for the ACL-gated,
 * any-user support/lockout-recovery variant.
 */
class Delete extends Action implements HttpPostActionInterface
{
    /** @var string */
    public const ADMIN_RESOURCE = 'Magento_Backend::admin';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly PasskeyCredentialRepository $credentialRepository,
        private readonly OAuthUtility $oauthUtility
    ) {
        parent::__construct($context);
    }

    #[\Override]
    public function execute()
    {
        $json = $this->jsonFactory->create();

        $adminUser = $this->_auth->getUser();
        if (!$adminUser instanceof \Magento\User\Model\User || !$adminUser->getId()) {
            return $json->setData(['error' => (string) __('Not authenticated.')]);
        }

        $credentialId = (int) $this->getRequest()->getParam('credential_id', 0);
        if ($credentialId <= 0) {
            return $json->setData(['error' => (string) __('Invalid credential.')]);
        }

        $deleted = $this->credentialRepository->deleteOwnedCredential($credentialId, 'admin', (int) $adminUser->getId());
        if (!$deleted) {
            return $json->setData(['error' => (string) __('Passkey not found.')]);
        }

        $this->oauthUtility->customlog('Passkey admin Delete: credential #' . $credentialId . ' removed by admin #' . $adminUser->getId());

        return $json->setData(['success' => true]);
    }
}
