<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Passkey;

use Webauthn\CredentialRecord;

/**
 * A single row from m2oidc_passkey_credentials, paired with its deserialized
 * Webauthn\CredentialRecord.
 */
readonly class StoredCredential
{
    /**
     * @param int              $dbId
     * @param string           $userType
     * @param int              $userId
     * @param CredentialRecord $record
     * @param string|null      $nickname
     * @param string           $createdAt
     */
    public function __construct(
        public int $dbId,
        public string $userType,
        public int $userId,
        public CredentialRecord $record,
        public ?string $nickname,
        public string $createdAt = '',
    ) {
    }
}
