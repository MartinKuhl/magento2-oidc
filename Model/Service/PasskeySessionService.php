<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Helper\PasskeyConfig;

/**
 * Tracks passkey-authenticated sessions and, when opted in via Passkey
 * Settings, forcibly logs a user out the moment one of their passkeys is
 * deleted.
 *
 * Reuses the OidcSessionRegistry/SessionDestructionService pair the module
 * already relies on for OIDC back-channel/front-channel logout, under a
 * synthetic subject so passkey-login sessions don't collide with real OIDC
 * subjects. Scope: only sessions established via OIDC or Passkey login are
 * trackable at all — Magento no longer stores a real PHP session ID for
 * admin sessions (admin_user_session.session_id is deprecated/unused) and
 * never did for customer sessions, so a plain password-authenticated
 * session cannot be found or killed by this or any other current mechanism.
 */
class PasskeySessionService
{
    /** Prefix distinguishing synthetic passkey-login subjects from real OIDC `sub` claims.
     * @var string */
    private const SUBJECT_PREFIX = 'm2passkey:';

    /**
     * @param OidcSessionRegistry $sessionRegistry
     * @param SessionDestructionService $sessionDestructionService
     * @param PasskeyConfig $passkeyConfig
     * @param OAuthUtility $oauthUtility
     */
    public function __construct(
        private readonly OidcSessionRegistry $sessionRegistry,
        private readonly SessionDestructionService $sessionDestructionService,
        private readonly PasskeyConfig $passkeyConfig,
        private readonly OAuthUtility $oauthUtility
    ) {
    }

    /**
     * Register a just-established passkey-login session for later force-logout.
     *
     * @param string $userType     'admin' or 'customer'
     * @param int    $userId       Admin user_id or customer entity_id
     * @param string $phpSessionId PHP session ID established by this login
     */
    public function registerSession(string $userType, int $userId, string $phpSessionId): void
    {
        $subject = $this->buildSubject($userType, $userId);
        $this->sessionRegistry->register($subject, '', $phpSessionId, $userType, $userId);
        $this->oauthUtility->customlog(
            'PasskeySessionService: registered session for ' . $subject
        );
    }

    /**
     * Force-logout every registered session for a user, if the feature is enabled.
     *
     * No-op when "Auto-Logout on Passkey Deletion" is disabled (the default),
     * and no-op when the user has no registered OIDC/passkey session.
     *
     * @param string $userType 'admin' or 'customer'
     * @param int    $userId   Admin user_id or customer entity_id
     */
    public function logoutUser(string $userType, int $userId): void
    {
        $subject = $this->buildSubject($userType, $userId);

        if (!$this->passkeyConfig->isAutoLogoutOnDeleteEnabled()) {
            $this->oauthUtility->customlog(
                'PasskeySessionService: logoutUser skipped for ' . $subject
                . ' — Auto-Logout on Passkey Deletion is disabled'
            );
            return;
        }

        $entries = $this->sessionRegistry->resolve($subject);
        if ($entries === null) {
            $this->oauthUtility->customlog(
                'PasskeySessionService: logoutUser no-op for ' . $subject
                . ' — no registered OIDC/passkey session found (password-only sessions are not trackable)'
            );
            return;
        }

        $this->oauthUtility->customlog(
            'PasskeySessionService: destroying ' . count($entries) . ' session(s) for ' . $subject
        );
        foreach ($entries as $entry) {
            $this->sessionDestructionService->destroySession((string) $entry['php_session_id']);
            $this->sessionDestructionService->clearOnlineStatus($entry);
        }

        $this->sessionRegistry->revoke($subject);
    }

    /**
     * Build the synthetic OidcSessionRegistry subject for a passkey-authenticated user.
     *
     * @param string $userType
     * @param int    $userId
     */
    private function buildSubject(string $userType, int $userId): string
    {
        return self::SUBJECT_PREFIX . $userType . ':' . $userId;
    }
}
