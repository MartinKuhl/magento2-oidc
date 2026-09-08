<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Observer;

use Magento\Backend\Model\Auth;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use M2Oidc\OAuth\Model\Service\PasskeySessionService;

/**
 * Keeps OidcSessionRegistry's record of the admin's passkey-login session
 * pointed at the *current* PHP session ID, on every admin request.
 *
 * PasskeySessionService::registerSession() is otherwise only called once, at
 * the moment of passkey login (Controller/Adminhtml/Actions/Passkey/LoginVerify.php).
 * If the PHP session ID changes afterwards for any reason, that one-time
 * snapshot goes stale and "Auto-Logout on Passkey Deletion" ends up destroying
 * an abandoned session instead of the browser's real one — the deletion still
 * logs as successful, but the user is never actually logged out.
 *
 * Re-registering on every request (mirroring AdminTokenAutoRefreshObserver's
 * predispatch pattern) keeps the mapping accurate for whichever request most
 * recently ran before a deletion occurs. OidcSessionRegistry::register()
 * already replaces any existing entry for the same php_session_id, so this is
 * a cheap, idempotent no-op when the ID hasn't changed.
 */
class PasskeySessionRefreshObserver implements ObserverInterface
{
    /**
     * @param Auth                  $auth
     * @param PasskeySessionService $passkeySessionService
     */
    public function __construct(
        private readonly Auth $auth,
        private readonly PasskeySessionService $passkeySessionService
    ) {
    }

    /**
     * Execute observer.
     *
     * @param Observer $observer
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        if (!$this->auth->isLoggedIn()) {
            return;
        }

        /** @psalm-suppress UndefinedInterfaceMethod */
        // @phpstan-ignore-next-line
        $isPasskeyAuthenticated = (bool) $this->auth->getAuthStorage()->getData('is_passkey_authenticated');
        if (!$isPasskeyAuthenticated) {
            return;
        }

        $user = $this->auth->getUser();
        if (!$user instanceof \Magento\User\Model\User || !$user->getId()) {
            return;
        }
        $userId = (int) $user->getId();

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $this->passkeySessionService->registerSession('admin', $userId, (string) session_id());
    }
}
