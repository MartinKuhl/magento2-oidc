<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Service;

use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Helper\PasskeyConfig;
use M2Oidc\OAuth\Model\Service\OidcSessionRegistry;
use M2Oidc\OAuth\Model\Service\PasskeySessionService;
use M2Oidc\OAuth\Model\Service\SessionDestructionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PasskeySessionService.
 *
 * Verifies:
 *  - logoutUser() is a full no-op when the "Auto-Logout on Passkey Deletion"
 *    config flag is disabled (the default) — no registry lookup, no session
 *    destruction attempted
 *  - registerSession() delegates to OidcSessionRegistry::register() with the
 *    expected synthetic subject and an empty sid
 *  - logoutUser() destroys every resolved entry, clears its online status,
 *    and revokes the registry entry exactly once, when enabled
 *  - logoutUser() no-ops when resolve() returns null (nothing registered)
 *
 * @covers \M2Oidc\OAuth\Model\Service\PasskeySessionService
 */
class PasskeySessionServiceTest extends TestCase
{
    /** @var OidcSessionRegistry&MockObject */
    private OidcSessionRegistry $sessionRegistry;

    /** @var SessionDestructionService&MockObject */
    private SessionDestructionService $sessionDestructionService;

    /** @var PasskeyConfig&MockObject */
    private PasskeyConfig $passkeyConfig;

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var PasskeySessionService */
    private PasskeySessionService $service;

    protected function setUp(): void
    {
        $this->sessionRegistry = $this->createMock(OidcSessionRegistry::class);
        $this->sessionDestructionService = $this->createMock(SessionDestructionService::class);
        $this->passkeyConfig = $this->createMock(PasskeyConfig::class);
        $this->oauthUtility = $this->createMock(OAuthUtility::class);
        $this->oauthUtility->method('customlog');

        $this->service = new PasskeySessionService(
            $this->sessionRegistry,
            $this->sessionDestructionService,
            $this->passkeyConfig,
            $this->oauthUtility
        );
    }

    public function testRegisterSessionUsesSyntheticSubjectAndEmptySid(): void
    {
        $this->sessionRegistry->expects($this->once())
            ->method('register')
            ->with('m2passkey:admin:7', '', 'php_sess_abc', 'admin', 7);

        $this->service->registerSession('admin', 7, 'php_sess_abc');
    }

    public function testLogoutUserNoOpsWhenFeatureDisabled(): void
    {
        $this->passkeyConfig->method('isAutoLogoutOnDeleteEnabled')->willReturn(false);

        $this->sessionRegistry->expects($this->never())->method('resolve');
        $this->sessionRegistry->expects($this->never())->method('revoke');
        $this->sessionDestructionService->expects($this->never())->method('destroySession');
        $this->sessionDestructionService->expects($this->never())->method('clearOnlineStatus');

        $this->service->logoutUser('customer', 42);
    }

    public function testLogoutUserNoOpsWhenNothingRegistered(): void
    {
        $this->passkeyConfig->method('isAutoLogoutOnDeleteEnabled')->willReturn(true);
        $this->sessionRegistry->method('resolve')->with('m2passkey:customer:42')->willReturn(null);

        $this->sessionRegistry->expects($this->never())->method('revoke');
        $this->sessionDestructionService->expects($this->never())->method('destroySession');

        $this->service->logoutUser('customer', 42);
    }

    public function testLogoutUserDestroysEveryResolvedEntryAndRevokes(): void
    {
        $this->passkeyConfig->method('isAutoLogoutOnDeleteEnabled')->willReturn(true);

        $entries = [
            [
                'php_session_id' => 'sess_1', 'user_type' => 'admin', 'user_id' => 7,
                'sub' => 'm2passkey:admin:7', 'sid' => '',
            ],
            [
                'php_session_id' => 'sess_2', 'user_type' => 'admin', 'user_id' => 7,
                'sub' => 'm2passkey:admin:7', 'sid' => '',
            ],
        ];
        $this->sessionRegistry->method('resolve')->with('m2passkey:admin:7')->willReturn($entries);

        $this->sessionDestructionService->expects($this->exactly(2))
            ->method('destroySession')
            ->willReturnCallback(function (string $id): void {
                $this->assertContains($id, ['sess_1', 'sess_2']);
            });
        $this->sessionDestructionService->expects($this->exactly(2))->method('clearOnlineStatus');

        $this->sessionRegistry->expects($this->once())->method('revoke')->with('m2passkey:admin:7');

        $this->service->logoutUser('admin', 7);
    }
}
