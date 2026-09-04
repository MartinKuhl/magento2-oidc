<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Block\Adminhtml\Passkeysettings;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use M2Oidc\OAuth\Helper\PasskeyConfig;

class Index extends Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly PasskeyConfig $passkeyConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    // getFormKey() is already provided by the parent Magento\Backend\Block\Template.

    public function isEnabledForAdmin(): bool
    {
        return $this->passkeyConfig->isEnabledForAdmin();
    }

    public function isEnabledForCustomer(): bool
    {
        return $this->passkeyConfig->isEnabledForCustomer();
    }

    public function getRpName(): string
    {
        return $this->passkeyConfig->getRpName();
    }

    public function getConfiguredRpId(): string
    {
        return $this->passkeyConfig->getRpId();
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('m2oidc/passkeysettings/save');
    }
}
