<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Plugin\User;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\User\Observer\Backend\ForceAdminPasswordChangeObserver;
use Magento\Framework\Event\Observer as EventObserver;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * Prevents forced password change redirect for OIDC-authenticated users.
 *
 * Without this plugin, OIDC users would be stuck in a redirect loop
 * to the "change password" page they cannot use.
 */
class OidcForcePasswordChangePlugin
{
    /**
     * Initialize OIDC force password change plugin.
     *
     * @param AuthSession $authSession
     */
    public function __construct(
        private readonly AuthSession $authSession
    ) {
    }

    /**
     * Around plugin for ForceAdminPasswordChangeObserver::execute()
     *
     * Skips forced password change redirect for OIDC users.
     * Event: controller_action_predispatch
     *
     * @param  ForceAdminPasswordChangeObserver $subject
     * @param  callable                         $proceed
     * @param  EventObserver                    $observer
     */
    public function aroundExecute(
        ForceAdminPasswordChangeObserver $subject,
        callable $proceed,
        EventObserver $observer
    ): void {
        if ($this->isOidcSession()) {
            // Clear any password-expired flag that may have been set
            $this->authSession->unsPciAdminUserIsPasswordExpired();
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
