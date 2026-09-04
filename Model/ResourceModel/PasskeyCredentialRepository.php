<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\ResourceModel;

use M2Oidc\OAuth\Model\Passkey\StoredCredential;
use M2Oidc\OAuth\Model\Passkey\WebauthnCeremonyFactory;
use M2Oidc\OAuth\Model\PasskeyCredential as PasskeyCredentialModel;
use M2Oidc\OAuth\Model\PasskeyCredentialFactory;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredential\CollectionFactory as PasskeyCredentialCollectionFactory;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;

/**
 * App-level repository for m2oidc_passkey_credentials.
 *
 * Owns the seam between webauthn-lib's Webauthn\CredentialRecord (which
 * carries a TrustPath object and a Uuid, so it can't be persisted as plain
 * columns) and our storage: the record is serialized as-is via
 * WebauthnCeremonyFactory::getSerializer() and stored in the credential_record
 * text column, alongside a base64url-encoded lookup key and a denormalized
 * copy of the fields the management UI needs to display without deserializing.
 */
class PasskeyCredentialRepository
{
    /**
     * @param PasskeyCredential $resource
     * @param PasskeyCredentialFactory $modelFactory
     * @param PasskeyCredentialCollectionFactory $collectionFactory
     * @param WebauthnCeremonyFactory $ceremonyFactory
     */
    public function __construct(
        private readonly PasskeyCredential $resource,
        private readonly PasskeyCredentialFactory $modelFactory,
        private readonly PasskeyCredentialCollectionFactory $collectionFactory,
        private readonly WebauthnCeremonyFactory $ceremonyFactory
    ) {
    }

    /**
     * Persist a newly-verified credential (registration flow).
     *
     * @param CredentialRecord $record
     * @param string $userType
     * @param int $userId
     * @param string|null $nickname
     */
    public function saveNewCredential(
        CredentialRecord $record,
        string $userType,
        int $userId,
        ?string $nickname
    ): void {
        /** @var PasskeyCredentialModel $model */
        $model = $this->modelFactory->create();
        $model->setData([
            'user_type' => $userType,
            'user_id' => $userId,
            'public_key_credential_id' => Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId),
            'credential_record' => $this->ceremonyFactory->getSerializer()->serialize($record, 'json'),
            'nickname' => $nickname,
            'transports' => implode(',', $record->transports),
        ]);
        $this->resource->save($model);
    }

    /**
     * Look up a stored credential by the raw (binary) credential ID reported
     * by the authenticator — used during login to resolve which credential
     * (and therefore which user) the browser's assertion belongs to.
     *
     * @param string $rawCredentialId
     */
    public function findByRawCredentialId(string $rawCredentialId): ?StoredCredential
    {
        $row = $this->resource->getByPublicKeyCredentialId(Base64UrlSafe::encodeUnpadded($rawCredentialId));
        if ($row === null) {
            return null;
        }
        return $this->rowToStoredCredential($row);
    }

    /**
     * All credentials registered for a user, most recently created first —
     * used to build excludeCredentials at registration and to power the
     * self-service "manage passkeys" list.
     *
     * @param string $userType
     * @param int $userId
     * @return StoredCredential[]
     */
    public function findAllForUser(string $userType, int $userId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addUserFilter($userType, $userId);
        $collection->setOrder('created_at', 'DESC');

        $result = [];
        foreach ($collection as $item) {
            $result[] = $this->rowToStoredCredential($item->getData());
        }
        return $result;
    }

    /**
     * Credential descriptors for a user, for use as excludeCredentials
     * (registration) or as a scoped allowCredentials list (admin's
     * email-first login, which — unlike the customer's usernameless flow —
     * knows the account before the ceremony starts).
     *
     * @param string $userType
     * @param int $userId
     * @return PublicKeyCredentialDescriptor[]
     */
    public function getDescriptorsForUser(string $userType, int $userId): array
    {
        $descriptors = [];
        foreach ($this->findAllForUser($userType, $userId) as $stored) {
            $descriptors[] = $stored->record->getPublicKeyCredentialDescriptor();
        }
        return $descriptors;
    }

    /**
     * Persist the updated counter/backup flags after a successful authentication.
     *
     * @param StoredCredential $stored
     * @param CredentialRecord $updatedRecord
     */
    public function updateAfterAuthentication(StoredCredential $stored, CredentialRecord $updatedRecord): void
    {
        $this->resource->updateAfterAuthentication(
            $stored->dbId,
            $this->ceremonyFactory->getSerializer()->serialize($updatedRecord, 'json')
        );
    }

    /**
     * Delete every credential belonging to a user — called on account deletion.
     *
     * @param string $userType
     * @param int $userId
     */
    public function deleteAllForUser(string $userType, int $userId): void
    {
        $this->resource->deleteAllForUser($userType, $userId);
    }

    /**
     * Self-service delete: only succeeds if the credential belongs to $userType/$userId.
     *
     * @param int $credentialId
     * @param string $userType
     * @param int $userId
     */
    public function deleteOwnedCredential(int $credentialId, string $userType, int $userId): bool
    {
        return $this->resource->deleteOwnedCredential($credentialId, $userType, $userId);
    }

    /**
     * Support/lockout-recovery delete: removes any credential by its row ID,
     * regardless of owner. Gated by the M2Oidc_OAuth::passkey_settings ACL
     * resource at the controller level.
     *
     * @param int $credentialId
     */
    public function deleteById(int $credentialId): void
    {
        $model = $this->modelFactory->create();
        $this->resource->load($model, $credentialId);
        if ($model->getId()) {
            $this->resource->delete($model);
        }
    }

    /**
     * Map a raw database row to a StoredCredential DTO.
     *
     * @param mixed[] $row
     */
    private function rowToStoredCredential(array $row): StoredCredential
    {
        /** @var CredentialRecord $record */
        $record = $this->ceremonyFactory->getSerializer()->deserialize(
            (string) $row['credential_record'],
            CredentialRecord::class,
            'json'
        );
        return new StoredCredential(
            (int) $row['credential_id'],
            (string) $row['user_type'],
            (int) $row['user_id'],
            $record,
            isset($row['nickname']) ? (string) $row['nickname'] : null,
            isset($row['created_at']) ? (string) $row['created_at'] : ''
        );
    }
}
