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
     * @param array<string, mixed> $data
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

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function getRegistrationOptionsUrl(): string
    {
        return $this->getUrl('m2oidc/actions_passkey/registrationoptions');
    }

    public function getRegistrationVerifyUrl(): string
    {
        return $this->getUrl('m2oidc/actions_passkey/registrationverify');
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('m2oidc/actions_passkey/delete');
    }
}
