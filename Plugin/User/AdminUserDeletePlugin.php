<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Plugin\User;

use Magento\User\Model\User;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredentialRepository;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider;

/**
 * Belt-and-suspenders fallback: removes the OIDC session activity row and any
 * registered passkeys when an admin user model is deleted, regardless of area
 * context or event system state.
 *
 * Works alongside AdminUserDeleteObserver. UserProvider::deleteMapping() is
 * idempotent (DELETE WHERE...), so calling it from both is safe.
 */
class AdminUserDeletePlugin
{
    /**
     * Constructor.
     *
     * @param UserProvider                $userProviderResource
     * @param PasskeyCredentialRepository $passkeyCredentialRepository
     */
    public function __construct(
        private readonly UserProvider $userProviderResource,
        private readonly PasskeyCredentialRepository $passkeyCredentialRepository
    ) {
    }

    /**
     * After Magento deletes the admin_user row, remove the OIDC mapping row
     * and any passkey credentials.
     *
     * @param  User $subject
     * @param  User $result
     */
    public function afterDelete(User $subject, User $result): User
    {
        $userId = (int) $subject->getId();
        if ($userId > 0) {
            $this->userProviderResource->deleteMapping('admin', $userId);
            $this->passkeyCredentialRepository->deleteAllForUser('admin', $userId);
        }
        return $result;
    }
}
