<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Observer;

use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Event\Observer;
use Magento\User\Model\User;
use M2Oidc\OAuth\Model\Service\PasskeySessionService;
use M2Oidc\OAuth\Observer\PasskeySessionRefreshObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PasskeySessionRefreshObserver.
 *
 * Verifies:
 *  - registerSession() is re-called with the current session_id() on every
 *    request where the admin is logged in via passkey
 *  - No-op when the admin isn't logged in
 *  - No-op when logged in but not passkey-authenticated (e.g. plain password login)
 *  - No-op when logged in + passkey flag set but no resolvable user ID
 *
 * @covers \M2Oidc\OAuth\Observer\PasskeySessionRefreshObserver
 */
class PasskeySessionRefreshObserverTest extends TestCase
{
    /** @var Auth&MockObject */
    private Auth $auth;

    /** @var AuthSession&MockObject */
    private AuthSession $authStorage;

    /** @var PasskeySessionService&MockObject */
    private PasskeySessionService $passkeySessionService;

    /** @var PasskeySessionRefreshObserver */
    private PasskeySessionRefreshObserver $observer;

    protected function setUp(): void
    {
        $this->auth = $this->createMock(Auth::class);
        $this->authStorage = $this->createMock(AuthSession::class);
        $this->auth->method('getAuthStorage')->willReturn($this->authStorage);

        $this->passkeySessionService = $this->createMock(PasskeySessionService::class);
        $this->observer = new PasskeySessionRefreshObserver(
            $this->auth,
            $this->passkeySessionService
        );
    }

    public function testRegistersSessionForLoggedInPasskeyAdmin(): void
    {
        $this->stub(isLoggedIn: true, isPasskeyAuthenticated: true, userId: 7);

        $this->passkeySessionService->expects($this->once())
            ->method('registerSession')
            ->with('admin', 7, (string) session_id());

        $this->observer->execute(new Observer([]));
    }

    public function testNoOpWhenNotLoggedIn(): void
    {
        $this->stub(isLoggedIn: false, isPasskeyAuthenticated: true, userId: 7);

        $this->passkeySessionService->expects($this->never())->method('registerSession');

        $this->observer->execute(new Observer([]));
    }

    public function testNoOpWhenLoggedInButNotPasskeyAuthenticated(): void
    {
        $this->stub(isLoggedIn: true, isPasskeyAuthenticated: false, userId: 7);

        $this->passkeySessionService->expects($this->never())->method('registerSession');

        $this->observer->execute(new Observer([]));
    }

    public function testNoOpWhenNoUserResolvable(): void
    {
        $this->stub(isLoggedIn: true, isPasskeyAuthenticated: true, userId: 0);

        $this->passkeySessionService->expects($this->never())->method('registerSession');

        $this->observer->execute(new Observer([]));
    }

    /**
     * @param bool $isLoggedIn
     * @param bool $isPasskeyAuthenticated
     * @param int  $userId 0 means "no user resolvable"
     */
    private function stub(bool $isLoggedIn, bool $isPasskeyAuthenticated, int $userId): void
    {
        $this->auth->method('isLoggedIn')->willReturn($isLoggedIn);
        $this->authStorage->method('getData')
            ->with('is_passkey_authenticated')
            ->willReturn($isPasskeyAuthenticated);

        if ($userId > 0) {
            $user = $this->createMock(User::class);
            $user->method('getId')->willReturn($userId);
            $this->auth->method('getUser')->willReturn($user);
        } else {
            $this->auth->method('getUser')->willReturn(null);
        }
    }
}
