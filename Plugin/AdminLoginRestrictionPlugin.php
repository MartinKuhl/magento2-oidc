<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Plugin;

use Magento\Backend\Model\Auth;
use Magento\Framework\Exception\AuthenticationException;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Helper\OAuthSecurityHelper;
use M2Oidc\OAuth\Helper\PasskeySecurityHelper;
use Psr\Log\LoggerInterface;

/**
 * Restricts non-OIDC admin logins when the setting is enabled.
 *
 * Reads per-provider `m2oidc_disable_non_oidc_admin_login` from the
 * m2oidc_oauth_client_apps table. No core_config_data fallbacks.
 */
class AdminLoginRestrictionPlugin
{
    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var LoggerInterface */
    private readonly LoggerInterface $logger;

    /** @var OAuthSecurityHelper */
    private readonly OAuthSecurityHelper $securityHelper;

    /** @var PasskeySecurityHelper */
    private readonly PasskeySecurityHelper $passkeySecurityHelper;

    /**
     * Constructor.
     *
     * @param OAuthUtility          $oauthUtility
     * @param LoggerInterface       $logger
     * @param OAuthSecurityHelper   $securityHelper
     * @param PasskeySecurityHelper $passkeySecurityHelper
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        LoggerInterface $logger,
        OAuthSecurityHelper $securityHelper,
        PasskeySecurityHelper $passkeySecurityHelper
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->logger = $logger;
        $this->securityHelper = $securityHelper;
        $this->passkeySecurityHelper = $passkeySecurityHelper;
    }

    /**
     * Block non-OIDC authentication attempts when the restriction is enabled.
     *
     * Safety net: if OIDC-only is enabled but the OIDC button is NOT shown
     * on any provider, allow normal login to prevent complete lockout.
     *
     * @param  Auth   $subject
     * @param  string $username
     * @param  string $password
     * @throws AuthenticationException
     */
    public function beforeLogin(Auth $subject, string $username, $password): null
    {
        // Allow OIDC- or passkey-authenticated logins — detect by ephemeral token format (non-consuming)
        if ($this->securityHelper->isOidcAuthToken($password)
            || $this->passkeySecurityHelper->isPasskeyAuthToken($password)
        ) {
            return null;
        }

        $adminProviders = $this->oauthUtility->getAllActiveProviders('admin');

        $anyRestricted = false;
        $anyButtonShown = false;
        foreach ($adminProviders as $provider) {
            if (!empty($provider['m2oidc_disable_non_oidc_admin_login'])) {
                $anyRestricted = true;
            }
            if (!empty($provider['show_admin_link'])) {
                $anyButtonShown = true;
            }
        }

        if (!$anyRestricted) {
            return null;
        }

        // Safety net: if no provider shows the OIDC button, allow login
        if (!$anyButtonShown) {
            $this->logger->warning(
                'M2Oidc OIDC: OIDC-only admin login is enabled but no OIDC '
                . 'button is shown. Allowing normal login to prevent lockout. '
                . 'User: ' . $username
            );
            return null;
        }

        $this->oauthUtility->customlog(
            'AdminLoginRestriction: Blocked non-OIDC login attempt for user: ' . $username
        );

        throw new AuthenticationException(
            __('Non-OIDC admin login is disabled. Please use OIDC authentication.')
        );
    }
}
