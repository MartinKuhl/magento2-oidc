<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\ResourceModel\PasskeyCredential;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use M2Oidc\OAuth\Model\PasskeyCredential;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredential as PasskeyCredentialResource;

/**
 * Collection for the m2oidc_passkey_credentials table.
 */
class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init(PasskeyCredential::class, PasskeyCredentialResource::class);
    }

    /**
     * Restrict the collection to credentials owned by a single user.
     *
     * @param string $userType 'customer' or 'admin'
     * @param int    $userId
     */
    public function addUserFilter(string $userType, int $userId): self
    {
        $this->addFieldToFilter('user_type', $userType);
        $this->addFieldToFilter('user_id', ['eq' => $userId]);
        return $this;
    }
}
