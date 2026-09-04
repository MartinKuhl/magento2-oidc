<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Passkeysettings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredentialRepository;

/**
 * Support/lockout-recovery delete: removes any registered credential by its
 * row ID, regardless of owner. Gated by M2Oidc_OAuth::passkey_settings —
 * unlike Controller/Adminhtml/Actions/Passkey/Delete.php (self-service only).
 *
 * Route: POST /admin/m2oidc/passkeysettings/delete — invoked by the
 * Registered Passkeys grid's row-level Delete action (Ui/Component/Listing/
 * Column/PasskeyActions.php), same redirect-based pattern as
 * Controller/Adminhtml/Sessions/Delete.php.
 */
class Delete extends Action implements HttpPostActionInterface
{
    /** @var string */
    public const ADMIN_RESOURCE = 'M2Oidc_OAuth::passkey_settings';

    public function __construct(
        Context $context,
        private readonly PasskeyCredentialRepository $credentialRepository
    ) {
        parent::__construct($context);
    }

    #[\Override]
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $credentialId = (int) $this->getRequest()->getParam('credential_id', 0);

        if ($credentialId <= 0) {
            $this->messageManager->addErrorMessage((string) __('Invalid credential.'));
            return $redirect;
        }

        $this->credentialRepository->deleteById($credentialId);
        $this->messageManager->addSuccessMessage((string) __('Passkey #%1 has been deleted.', $credentialId));

        return $redirect;
    }
}
