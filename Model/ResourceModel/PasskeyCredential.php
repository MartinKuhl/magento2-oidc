<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for the m2oidc_passkey_credentials table.
 */
class PasskeyCredential extends AbstractDb
{
    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('m2oidc_passkey_credentials', 'credential_id');
    }

    /**
     * Find a credential row by its base64url public key credential ID.
     *
     * @param  string $publicKeyCredentialId Base64url-encoded credential ID
     * @return array<string, mixed>|null
     */
    public function getByPublicKeyCredentialId(string $publicKeyCredentialId): ?array
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return null;
        }
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('public_key_credential_id = ?', $publicKeyCredentialId)
            ->limit(1);
        $row = $connection->fetchRow($select);
        return $row ?: null;
    }

    /**
     * Update the persisted counter and last_used_at after a successful authentication.
     *
     * @param int    $credentialId    m2oidc_passkey_credentials.credential_id
     * @param string $credentialRecord Newly serialized Webauthn\CredentialRecord (updated counter/backup flags)
     */
    public function updateAfterAuthentication(int $credentialId, string $credentialRecord): void
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return;
        }
        $connection->update(
            $this->getMainTable(),
            [
                'credential_record' => $credentialRecord,
                'last_used_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            ],
            ['credential_id = ?' => $credentialId]
        );
    }

    /**
     * Delete all credentials owned by a user (e.g. on account deletion).
     *
     * @param string $userType 'customer' or 'admin'
     * @param int    $userId
     */
    public function deleteAllForUser(string $userType, int $userId): void
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return;
        }
        $connection->delete(
            $this->getMainTable(),
            ['user_type = ?' => $userType, 'user_id = ?' => $userId]
        );
    }

    /**
     * Delete a single credential, scoped to its owner (self-service delete).
     *
     * @param int    $credentialId
     * @param string $userType
     * @param int    $userId
     * @return bool True if a row was deleted
     */
    public function deleteOwnedCredential(int $credentialId, string $userType, int $userId): bool
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return false;
        }
        $affected = $connection->delete(
            $this->getMainTable(),
            [
                'credential_id = ?' => $credentialId,
                'user_type = ?' => $userType,
                'user_id = ?' => $userId,
            ]
        );
        return $affected > 0;
    }
}
