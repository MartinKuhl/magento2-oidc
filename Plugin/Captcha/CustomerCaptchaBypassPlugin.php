<?php

declare(strict_types=1);

/**
 * Customer OIDC CAPTCHA Bypass Plugin
 *
 * Bypasses CAPTCHA validation for OIDC-authenticated customers.
 * Mirrors admin OidcCaptchaBypassPlugin for frontend customer login.
 *
 * @pacfinal kage M2Oidc\OAuth\Plugin\Captcha
 */
namespace M2Oidc\OAuth\Plugin\Captcha;

use Magento\Captcha\Observer\CheckUserLoginObserver;
use Magento\Framework\Event\Observer;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * CAPTCHA bypass plugin for customer OIDC authentication.
 *
 * Intercepts the CheckUserLoginObserver to skip CAPTCHA validation
 * when the oidc_auth marker is present in the event data.
 */
class CustomerCaptchaBypassPlugin
{
    /** @var OAuthUtility */
    protected OAuthUtility $oauthUtility;

    /**
     * Initialize customer CAPTCHA bypass plugin.
     *
     * @param OAuthUtility $oauthUtility OAuth utility helper
     */
    public function __construct(OAuthUtility $oauthUtility)
    {
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Bypass CAPTCHA validation for OIDC-authenticated customers.
     *
     * Checks for the oidc_auth marker in the event data. If present,
     * returns the subject without calling proceed(), effectively
     * skipping CAPTCHA validation. Otherwise, proceeds with normal
     * CAPTCHA validation for password-based login.
     *
     * @param CheckUserLoginObserver $subject CAPTCHA observer
     * @param callable $proceed Next plugin in chain
     * @param Observer $observer Event observer
     * @return CheckUserLoginObserver|mixed Subject on bypass, or
     *         proceed result for normal validation
     */
    public function aroundExecute(
        CheckUserLoginObserver $subject,
        callable $proceed,
        Observer $observer
    ) {
        // Check for OIDC or passkey authentication marker in event data
        if ($observer->getEvent()->getData('oidc_auth') === true
            || $observer->getEvent()->getData('passkey_auth') === true
        ) {
            $this->oauthUtility->customlog(
                "Customer CAPTCHA: Bypassing validation for OIDC/passkey"
            );
            // Return subject without calling proceed()
            // This skips CAPTCHA validation
            return $subject;
        }

        // Normal CAPTCHA validation for password-based login
        return $proceed($observer);
    }
}
