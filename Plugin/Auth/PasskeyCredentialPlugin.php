<?php

declare(strict_types=1);

/**
 * Passkey Credential Plugin
 *
 * Intercepts Auth credential storage retrieval to inject PasskeyCredentialAdapter
 * when a passkey login is detected. Structurally identical to OidcCredentialPlugin —
 * see that class for the detailed rationale of each step.
 *
 * @package M2Oidc\OAuth\Plugin\Auth
 */
namespace M2Oidc\OAuth\Plugin\Auth;

use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Auth\Credential\StorageInterface;
use M2Oidc\OAuth\Model\Auth\PasskeyCredentialAdapter;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Helper\PasskeySecurityHelper;

class PasskeyCredentialPlugin
{
    /** @var PasskeyCredentialAdapter */
    private readonly PasskeyCredentialAdapter $passkeyCredentialAdapter;

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var PasskeySecurityHelper */
    private readonly PasskeySecurityHelper $securityHelper;

    /**
     * @var bool Flag indicating passkey authentication is in progress
     */
    private bool $isPasskeyAuth = false;

    /**
     * @var bool Guard flag to prevent duplicate log entries.
     *
     * getCredentialStorage() is called multiple times during a single login
     * flow (login method, observers, session init). We only log once.
     */
    private bool $adapterLogged = false;

    /**
     * @param PasskeyCredentialAdapter $passkeyCredentialAdapter
     * @param OAuthUtility             $oauthUtility
     * @param PasskeySecurityHelper    $securityHelper
     */
    public function __construct(
        PasskeyCredentialAdapter $passkeyCredentialAdapter,
        OAuthUtility $oauthUtility,
        PasskeySecurityHelper $securityHelper
    ) {
        $this->passkeyCredentialAdapter = $passkeyCredentialAdapter;
        $this->oauthUtility = $oauthUtility;
        $this->securityHelper = $securityHelper;
    }

    /**
     * Before plugin for Auth::login()
     *
     * @param  Auth   $subject
     * @param  string $username
     * @param  string $password
     * @return array{0: string, 1: string}
     */
    public function beforeLogin(
        Auth $subject,
        string $username,
        string $password
    ): array {
        // Always unconditionally reset both flags at the start of every login
        // attempt — guards against a prior Auth::login() call throwing before
        // afterLogin() could execute, in a recycled PHP-FPM worker.
        $this->isPasskeyAuth = false;
        $this->adapterLogged = false;

        // Detect passkey login by checking for the ephemeral token format (non-consuming)
        if ($this->securityHelper->isPasskeyAuthToken($password)) {
            $this->oauthUtility->customlog(
                "PasskeyCredentialPlugin: Passkey authentication detected for: " . $username
            );
            $this->isPasskeyAuth = true;
        }

        return [$username, $password];
    }

    /**
     * Around plugin for Auth::getCredentialStorage()
     *
     * @param  Auth     $subject
     * @param  callable $proceed
     */
    public function aroundGetCredentialStorage(
        Auth $subject,
        callable $proceed
    ): StorageInterface {
        if ($this->isPasskeyAuth) {
            if (!$this->adapterLogged) {
                $this->oauthUtility->customlog(
                    "PasskeyCredentialPlugin: Returning passkey credential adapter"
                );
                $this->adapterLogged = true;
            }

            return $this->passkeyCredentialAdapter;
        }

        return $proceed();
    }

    /**
     * After plugin for Auth::login()
     *
     * @param  Auth $subject
     * @param  null $result  Result is always null (Auth::login() returns void)
     */
    public function afterLogin(
        Auth $subject,
        $result
    ): void {
        if ($this->isPasskeyAuth) {
            $this->oauthUtility->customlog(
                "PasskeyCredentialPlugin: Cleaning up passkey flag after login"
            );
            $this->isPasskeyAuth = false;
            $this->adapterLogged = false;
        }
    }
}
