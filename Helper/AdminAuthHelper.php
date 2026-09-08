<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Helper;

use Magento\User\Model\User;
use M2Oidc\OAuth\Model\Auth\OidcCredentialAdapter;
use M2Oidc\OAuth\Model\Auth\PasskeyCredentialAdapter;

/**
 * Resolves the real admin User model behind Auth::getUser(), regardless of
 * which Credential\StorageInterface implementation is currently active on
 * the session.
 *
 * A password-authenticated session holds a real \Magento\User\Model\User
 * directly. A Passkey- or OIDC-SSO-authenticated session instead holds the
 * bridging adapter that PasskeyCredentialPlugin/OidcCredentialPlugin swap in
 * during Auth::login() — PasskeyCredentialAdapter or OidcCredentialAdapter,
 * both StorageInterface implementations, *not* \Magento\User\Model\User —
 * which proxies to the real User model internally via its own getUser().
 * A naive `$auth->getUser() instanceof \Magento\User\Model\User` check
 * therefore misidentifies every Passkey- or OIDC-authenticated admin as "not
 * authenticated" for any self-service action performing that check, even
 * though the session is genuinely logged in.
 */
class AdminAuthHelper
{
    /**
     * Resolve the real admin User model behind whatever Auth::getUser() returned.
     *
     * @param mixed $authUser Return value of Auth::getUser() / Auth\Session::getUser()
     */
    public function resolveAdminUser(mixed $authUser): ?User
    {
        return match (true) {
            $authUser instanceof User => $authUser,
            $authUser instanceof PasskeyCredentialAdapter,
            $authUser instanceof OidcCredentialAdapter => $authUser->getUser(),
            default => null,
        };
    }
}
