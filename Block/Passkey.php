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
     * @param array<string, mixed> $data
     */
    public function __construct(
        Template\Context $context,
        private readonly PasskeyConfig $passkeyConfig,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabledForAdmin(): bool
    {
        return $this->passkeyConfig->isEnabledForAdmin();
    }

    public function isEnabledForCustomer(): bool
    {
        return $this->passkeyConfig->isEnabledForCustomer();
    }

    public function getLoginOptionsUrl(): string
    {
        return $this->getUrl('m2oidc/actions_passkey/loginoptions');
    }

    public function getLoginVerifyUrl(): string
    {
        return $this->getUrl('m2oidc/actions_passkey/loginverify');
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }
}
