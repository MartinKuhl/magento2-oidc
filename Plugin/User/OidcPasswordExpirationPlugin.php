<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Plugin\User;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\User\Observer\Backend\AuthObserver;
use Magento\Framework\Event\Observer as EventObserver;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * Prevents "time to change your password" warning for OIDC-authenticated users.
 *
 * OIDC users have no Magento password — the expiration check is irrelevant
 * and would block them from using the admin panel.
 */
class OidcPasswordExpirationPlugin
{
    /**
     * Initialize OIDC password expiration plugin.
     *
     * @param AuthSession  $authSession
     * @param OAuthUtility $oauthUtility
     */
    public function __construct(
        private readonly AuthSession $authSession,
        private readonly OAuthUtility $oauthUtility
    ) {
    }

    /**
     * Around plugin for AuthObserver::execute()
     *
     * Skips password expiration check entirely for OIDC users.
     * Event: backend_auth_user_login_success
     *
     * L-08: `around` is required (not `before`) because we need to prevent
     * `$proceed` from being called at all — a `before` plugin cannot stop
     * the original method from executing.
     *
     * @param  AuthObserver   $subject
     * @param  callable       $proceed
     * @param  EventObserver  $observer
     */
    public function aroundExecute(
        AuthObserver $subject,
        callable $proceed,
        EventObserver $observer
    ): void {
        if ($this->isOidcSession()) {
            $this->oauthUtility->customlog(
                'OIDC Password Expiration: Skipping password expiration check for OIDC user'
            );
            return;
        }

        $proceed($observer);
    }

    /**
     * Check if current session is OIDC- or passkey-authenticated.
     */
    private function isOidcSession(): bool
    {
        return (bool) $this->authSession->getIsOidcAuthenticated()
            || (bool) $this->authSession->getIsPasskeyAuthenticated();
    }
}
