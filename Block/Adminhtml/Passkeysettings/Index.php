<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Block\Adminhtml\Passkeysettings;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use M2Oidc\OAuth\Helper\PasskeyConfig;

class Index extends Template
{
    /**
     * @param Context $context
     * @param PasskeyConfig $passkeyConfig
     * @param mixed[] $data
     */
    public function __construct(
        Context $context,
        private readonly PasskeyConfig $passkeyConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    // getFormKey() is already provided by the parent Magento\Backend\Block\Template.

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
     * Whether an active OIDC/passkey session is force-logged-out on passkey delete.
     */
    public function isAutoLogoutOnDeleteEnabled(): bool
    {
        return $this->passkeyConfig->isAutoLogoutOnDeleteEnabled();
    }

    /**
     * Get the configured (or default) Relying Party name.
     */
    public function getRpName(): string
    {
        return $this->passkeyConfig->getRpName();
    }

    /**
     * Get the configured (or default) Relying Party ID.
     */
    public function getConfiguredRpId(): string
    {
        return $this->passkeyConfig->getRpId();
    }

    /**
     * Get the URL the Passkey Settings form posts to.
     */
    public function getSaveUrl(): string
    {
        return $this->getUrl('m2oidc/passkeysettings/save');
    }
}
