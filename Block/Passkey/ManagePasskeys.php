<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Block\Passkey;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template;
use M2Oidc\OAuth\Model\Passkey\StoredCredential;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredentialRepository;

/**
 * Renders the customer-facing "My Account > Passkeys" list/register/delete UI.
 */
class ManagePasskeys extends Template
{
    /**
     * @param Template\Context $context
     * @param CustomerSession $customerSession
     * @param PasskeyCredentialRepository $credentialRepository
     * @param FormKey $formKey
     * @param mixed[] $data
     */
    public function __construct(
        Template\Context $context,
        private readonly CustomerSession $customerSession,
        private readonly PasskeyCredentialRepository $credentialRepository,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get the logged-in customer's registered passkeys.
     *
     * @return StoredCredential[]
     */
    public function getCredentials(): array
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        if ($customerId <= 0) {
            return [];
        }
        return $this->credentialRepository->findAllForUser('customer', $customerId);
    }

    /**
     * Get the current form key for CSRF-protected AJAX requests.
     */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * Get the URL for building WebAuthn attestation (creation) options.
     */
    public function getRegistrationOptionsUrl(): string
    {
        return $this->getUrl('m2oidc/actions_passkey/registrationoptions');
    }

    /**
     * Get the URL for verifying a WebAuthn attestation and persisting the credential.
     */
    public function getRegistrationVerifyUrl(): string
    {
        return $this->getUrl('m2oidc/actions_passkey/registrationverify');
    }

    /**
     * Get the URL for self-service passkey deletion.
     */
    public function getDeleteUrl(): string
    {
        return $this->getUrl('m2oidc/actions_passkey/delete');
    }
}
