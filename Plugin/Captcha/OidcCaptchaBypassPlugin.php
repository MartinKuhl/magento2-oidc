<?php

declare(strict_types=1);

/**
 * OIDC CAPTCHA Bypass Plugin
 *
 * Bypasses CAPTCHA validation for OIDC-authenticated admin users.
 *
 * OIDC users have already authenticated at the external identity provider (Authelia),
 * so CAPTCHA validation is unnecessary and would block legitimate logins.
 * CAPTCHA is designed to prevent brute-force password attacks, which is not applicable
 * to OIDC authentication flow.
 *
 * This plugin intercepts Magento's CheckUserLoginBackendObserver and skips CAPTCHA
 * validation when it detects the OIDC authentication marker in event data.
 *
 * @package M2Oidc\OAuth\Plugin\Captcha
 */
namespace M2Oidc\OAuth\Plugin\Captcha;

use Magento\Captcha\Observer\CheckUserLoginBackendObserver;
use M2Oidc\OAuth\Helper\OAuthUtility;

class OidcCaptchaBypassPlugin
{
    /** @var \M2Oidc\OAuth\Helper\OAuthUtility */
    protected \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility;

    /**
     * Initialize OIDC CAPTCHA bypass plugin.
     *
     * @param OAuthUtility $oauthUtility
     */
    public function __construct(OAuthUtility $oauthUtility)
    {
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Bypass CAPTCHA validation for OIDC-authenticated users
     *
     * This around plugin intercepts the CAPTCHA observer's execute() method.
     * When it detects OIDC authentication (via 'oidc_auth' marker in event data),
     * it skips CAPTCHA validation entirely. For standard password-based authentication,
     * it proceeds with normal CAPTCHA validation.
     *
     * @param  CheckUserLoginBackendObserver       $subject
     * @param  callable                            $proceed
     * @param  \Magento\Framework\Event\Observer   $observer
     * @return CheckUserLoginBackendObserver
     */
    public function aroundExecute(
        CheckUserLoginBackendObserver $subject,
        callable $proceed,
        \Magento\Framework\Event\Observer $observer
    ) {
        // Check if this is an OIDC or passkey authentication by looking for the marker in event data
        if ($observer->getEvent()->getData('oidc_auth') === true
            || $observer->getEvent()->getData('passkey_auth') === true
        ) {
            $username = $observer->getEvent()->getUsername();
            $this->oauthUtility->customlog(
                "CAPTCHA: Bypassing CAPTCHA validation for OIDC/passkey authentication: " . $username
            );

            // Return the subject without calling proceed() - this skips CAPTCHA validation
            return $subject;
        }

        // Normal CAPTCHA validation for password-based login
        $this->oauthUtility->customlog("CAPTCHA: Performing standard CAPTCHA validation");
        return $proceed($observer);
    }
}
