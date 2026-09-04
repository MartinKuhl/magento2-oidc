<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Block;

use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template;
use M2Oidc\OAuth\Helper\PasskeyConfig;

/**
 * Renders the "Login with Passkey" button on both the admin login page and
 * the customer account login page (same class reused in both areas' layout
 * XML, matching Block/OAuth.php's convention — the injected URL builder
 * already resolves to the correct area).
 */
class Passkey extends Template
{
    /**
     * @param Template\Context $context
     * @param PasskeyConfig $passkeyConfig
     * @param FormKey $formKey
     * @param mixed[] $data
     */
    public function __construct(
        Template\Context $context,
        private readonly PasskeyConfig $passkeyConfig,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Whether passkey login is enabled for admin users.
     */
    public function isEnabledForAdmin(): bool
    {
        return $this->passkeyConfig->isEnabledForAdmin();
    }

    /**
     * Whether passkey login is enabled for customers.
     */
    public function isEnabledForCustomer(): bool
    {
        return $this->passkeyConfig->isEnabledForCustomer();
    }

    /**
     * Get the URL for building WebAuthn assertion (request) options.
     */
    public function getLoginOptionsUrl(): string
    {
        return $this->getUrl('m2oidc/actions_passkey/loginoptions');
    }

    /**
     * Get the URL for verifying a WebAuthn assertion.
     */
    public function getLoginVerifyUrl(): string
    {
        return $this->getUrl('m2oidc/actions_passkey/loginverify');
    }

    /**
     * Get the current form key for CSRF-protected AJAX requests.
     */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }
}
