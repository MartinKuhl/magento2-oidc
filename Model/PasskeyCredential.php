<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Model for the m2oidc_passkey_credentials table.
 * A single registered WebAuthn credential for an admin or customer user.
 */
class PasskeyCredential extends AbstractModel
{
    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init(\M2Oidc\OAuth\Model\ResourceModel\PasskeyCredential::class);
    }
}
