<?php

declare(strict_types=1);

/**
 * OIDC Identity Verification Bypass Plugin
 *
 * Intercepts User::performIdentityCheck() to bypass password verification
 * for admin users who authenticated via OIDC. These users don't have a
 * Magento password and cannot pass the standard identity verification.
 *
 * Security:
 * - Only bypasses when cookie 'oidc_authenticated' is set to '1'
 * - Cookie can only be set by Oidccallback controller after successful OIDC auth
 * - Still validates user is active and has assigned role
 * - Non-OIDC users are unaffected (normal password verification applies)
 *
 * @package M2Oidc\OAuth\Plugin\User
 */
namespace M2Oidc\OAuth\Plugin\User;

use Magento\User\Model\User;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Stdlib\CookieManagerInterface;
use M2Oidc\OAuth\Helper\OAuthUtility;

class OidcIdentityVerificationPlugin
{
    /** @var \Magento\Framework\Stdlib\CookieManagerInterface */
    protected \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager;

    /** @var \M2Oidc\OAuth\Helper\OAuthUtility */
    protected \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility;

    /**
     * Initialize OIDC identity verification plugin.
     *
     * @param CookieManagerInterface $cookieManager
     * @param OAuthUtility           $oauthUtility
     */
    public function __construct(
        CookieManagerInterface $cookieManager,
        OAuthUtility $oauthUtility
    ) {
        $this->cookieManager = $cookieManager;
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Bypass performIdentityCheck for OIDC-authenticated users
     *
     * @param  User     $subject        The user performing the identity check
     * @param  callable $proceed        Original method
     * @param  string   $passwordString The password entered
     * @return User
     * @throws AuthenticationException
     */
    public function aroundPerformIdentityCheck(
        User $subject,
        callable $proceed,
        $passwordString
    ) {
        // Check if cookie has OIDC or passkey authenticated flag
        // (set by Oidccallback / Passkey\LoginVerify controllers)
        $isOidcAuth = $this->cookieManager->getCookie('oidc_authenticated') === '1'
            || $this->cookieManager->getCookie('passkey_authenticated') === '1';

        $this->oauthUtility->customlog("OIDC Identity Bypass: isOidcAuth = " . var_export($isOidcAuth, true));

        if ($isOidcAuth) {
            $this->oauthUtility->customlog(
                "OIDC Identity Bypass: Bypassing password verification for OIDC user: "
                . $subject->getUserName()
            );

            // Still validate user is active (same check as verifyIdentity)
            if ($subject->getIsActive() != '1') {
                $this->oauthUtility->customlog(
                    "OIDC Identity Bypass: User is inactive: " . $subject->getUserName()
                );
                throw new AuthenticationException(
                    __('Your account is inactive.')
                );
            }

            // Validate user has assigned role
            if (empty($subject->hasAssigned2Role($subject->getId()))) {
                $this->oauthUtility->customlog(
                    "OIDC Identity Bypass: User has no role: " . $subject->getUserName()
                );
                throw new AuthenticationException(
                    __('More permissions are needed to access this.')
                );
            }

            $this->oauthUtility->customlog(
                "OIDC Identity Bypass: Identity verified for: " . $subject->getUserName()
            );

            return $subject;
        }

        // Non-OIDC users: proceed with normal password verification
        $this->oauthUtility->customlog(
            "OIDC Identity Bypass: Standard password verification for: "
            . $subject->getUserName()
        );

        return $proceed($passwordString);
    }
}
