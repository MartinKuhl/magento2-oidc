<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Helper;

use Magento\User\Model\User;
use M2Oidc\OAuth\Helper\AdminAuthHelper;
use M2Oidc\OAuth\Model\Auth\OidcCredentialAdapter;
use M2Oidc\OAuth\Model\Auth\PasskeyCredentialAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminAuthHelper.
 *
 * Verifies:
 *  - A real \Magento\User\Model\User is returned as-is (password-authenticated session)
 *  - A PasskeyCredentialAdapter is unwrapped to its real underlying user
 *    (Passkey-authenticated session — PasskeyCredentialPlugin swaps this in
 *    as the credential storage during Auth::login())
 *  - An OidcCredentialAdapter is unwrapped the same way (OIDC-SSO-authenticated session)
 *  - Anything else (null, an unrelated object) resolves to null
 *
 * @covers \M2Oidc\OAuth\Helper\AdminAuthHelper
 */
class AdminAuthHelperTest extends TestCase
{
    /** @var AdminAuthHelper */
    private AdminAuthHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new AdminAuthHelper();
    }

    public function testRealUserIsReturnedAsIs(): void
    {
        /** @var User&MockObject $user */
        $user = $this->createMock(User::class);

        $this->assertSame($user, $this->helper->resolveAdminUser($user));
    }

    public function testPasskeyCredentialAdapterIsUnwrapped(): void
    {
        /** @var User&MockObject $user */
        $user = $this->createMock(User::class);
        /** @var PasskeyCredentialAdapter&MockObject $adapter */
        $adapter = $this->createMock(PasskeyCredentialAdapter::class);
        $adapter->method('getUser')->willReturn($user);

        $this->assertSame($user, $this->helper->resolveAdminUser($adapter));
    }

    public function testOidcCredentialAdapterIsUnwrapped(): void
    {
        /** @var User&MockObject $user */
        $user = $this->createMock(User::class);
        /** @var OidcCredentialAdapter&MockObject $adapter */
        $adapter = $this->createMock(OidcCredentialAdapter::class);
        $adapter->method('getUser')->willReturn($user);

        $this->assertSame($user, $this->helper->resolveAdminUser($adapter));
    }

    public function testNullResolvesToNull(): void
    {
        $this->assertNull($this->helper->resolveAdminUser(null));
    }

    public function testUnrelatedObjectResolvesToNull(): void
    {
        $this->assertNull($this->helper->resolveAdminUser(new \stdClass()));
    }

    public function testAdapterWithNoUnderlyingUserResolvesToNull(): void
    {
        /** @var PasskeyCredentialAdapter&MockObject $adapter */
        $adapter = $this->createMock(PasskeyCredentialAdapter::class);
        $adapter->method('getUser')->willReturn(null);

        $this->assertNull($this->helper->resolveAdminUser($adapter));
    }
}
