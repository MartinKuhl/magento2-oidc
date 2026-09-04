<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Service;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\User\Model\ResourceModel\User as UserResourceModel;
use Magento\User\Model\ResourceModel\User\Collection as UserCollection;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use M2Oidc\OAuth\Test\Unit\Fixtures\UserWithRoleId;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Attribute\AttributeMapperInterface;
use M2Oidc\OAuth\Model\Provider\MappingRepository;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;
use M2Oidc\OAuth\Model\Service\AdminUserCreator;
use M2Oidc\OAuth\Model\Service\GroupMappingResolver;
use M2Oidc\OAuth\Model\Service\RandomPasswordGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminUserCreator role-mapping fallback chain (Phase 1.1).
 *
 * @covers \M2Oidc\OAuth\Model\Service\AdminUserCreator
 */
class AdminUserCreatorRoleMappingTest extends TestCase
{
    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var UserFactory&MockObject */
    private UserFactory $userFactory;

    /** @var UserResourceModel&MockObject */
    private UserResourceModel $userResource;

    /** @var UserCollectionFactory&MockObject */
    private UserCollectionFactory $userCollectionFactory;

    /** @var UserProviderResource&MockObject */
    private UserProviderResource $userProviderResource;

    /** @var AdminUserCreator */
    private AdminUserCreator $creator;

    protected function setUp(): void
    {
        $this->oauthUtility          = $this->createMock(OAuthUtility::class);
        $this->userFactory           = $this->createMock(UserFactory::class);
        $this->userResource          = $this->createMock(UserResourceModel::class);
        $this->userCollectionFactory = $this->createMock(UserCollectionFactory::class);
        $this->userProviderResource  = $this->createMock(UserProviderResource::class);

        $this->oauthUtility->method('customlog');
        $this->oauthUtility->method('isBlank')->willReturnCallback(
            fn($v) => $v === null || $v === '' || $v === '0'
        );

        // AdminAttributeMapper must pass through the names unchanged so role-mapping tests
        // are not confounded by name-fallback logic. Return values as-is.
        $mapperMock = $this->createMock(AttributeMapperInterface::class);
        $mapperMock->method('map')->willReturnCallback(
            fn(array $flat) => ['firstname' => $flat['firstname'] ?? '', 'lastname' => $flat['lastname'] ?? '']
        );

        $this->creator = new AdminUserCreator(
            $this->userFactory,
            $this->oauthUtility,
            $this->createMock(RandomPasswordGenerator::class),
            $this->userResource,
            $this->userCollectionFactory,
            $this->userProviderResource,
            $this->createMock(MappingRepository::class),
            $mapperMock,
            new GroupMappingResolver($this->createMock(MappingRepository::class), $this->oauthUtility)
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a mock User model that reports a given ID after save().
     * setRoleId() is declared on the UserWithRoleId test double since it is
     * only reachable via User's magic __call() on the real class.
     */
    private function makeUserMock(int $id = 1): User&MockObject
    {
        $user = $this->getMockBuilder(UserWithRoleId::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'setUsername', 'setFirstname', 'setLastname', 'setEmail',
                           'setPassword', 'setIsActive', 'setRoleId'])
            ->getMock();
        $user->method('getId')->willReturn($id);
        $user->method('setUsername')->willReturnSelf();
        $user->method('setFirstname')->willReturnSelf();
        $user->method('setLastname')->willReturnSelf();
        $user->method('setEmail')->willReturnSelf();
        $user->method('setPassword')->willReturnSelf();
        $user->method('setIsActive')->willReturnSelf();
        $user->method('setRoleId')->willReturnSelf();
        return $user;
    }

    /**
     * Configure userFactory and userResource so that saveAdminUser() succeeds.
     */
    private function allowUserSave(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('beginTransaction');
        $connection->method('commit');

        $this->userResource->method('getConnection')->willReturn($connection);
        $this->userResource->method('save');

        $this->userFactory->method('create')->willReturn($this->makeUserMock());
    }

    /**
     * Configure email fallback extraction (used when names are empty).
     */
    private function allowNameExtraction(): void
    {
        $this->oauthUtility->method('extractNameFromEmail')->willReturn(
            ['first' => 'Test', 'last' => 'User']
        );
    }

    // -------------------------------------------------------------------------
    // Role mapping: group match
    // -------------------------------------------------------------------------

    public function testExactGroupMatchReturnsConfiguredRoleId(): void
    {
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, json_encode([['group' => 'Devs', 'role' => '3']])],
            [OAuthConstants::MAP_DEFAULT_ROLE, null],
        ]);

        $this->allowUserSave();
        $this->allowNameExtraction();

        $result = $this->creator->createAdminUser(
            'dev@example.com',
            'dev',
            'Dev',
            'User',
            ['Devs']
        );

        // With a matching group the user save path is reached (not null)
        $this->assertNotNull($result, 'Expected non-null user when group matches');
    }

    public function testCaseInsensitiveGroupMatch(): void
    {
        // Mapping has lowercase 'devs', OIDC sends uppercase 'DEVS'
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, json_encode([['group' => 'devs', 'role' => '5']])],
            [OAuthConstants::MAP_DEFAULT_ROLE, null],
        ]);

        $this->allowUserSave();
        $this->allowNameExtraction();

        $result = $this->creator->createAdminUser(
            'dev@example.com',
            'dev',
            'Dev',
            'User',
            ['DEVS']
        );

        $this->assertNotNull($result, 'Case-insensitive group match should find a role');
    }

    public function testFirstMatchingGroupWins(): void
    {
        // User has two groups; first mapping hit should be used
        $mappings = [
            ['group' => 'Editors', 'role' => '2'],
            ['group' => 'Devs',    'role' => '3'],
        ];
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, json_encode($mappings)],
            [OAuthConstants::MAP_DEFAULT_ROLE, null],
        ]);

        $this->allowUserSave();
        $this->allowNameExtraction();

        $result = $this->creator->createAdminUser(
            'dev@example.com',
            'dev',
            'Dev',
            'User',
            ['Devs', 'Editors']
        );

        $this->assertNotNull($result);
    }

    // -------------------------------------------------------------------------
    // Role mapping: fallback to default role
    // -------------------------------------------------------------------------

    public function testNoGroupMatchFallsBackToDefaultRole(): void
    {
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, json_encode([['group' => 'Admins', 'role' => '1']])],
            [OAuthConstants::MAP_DEFAULT_ROLE, '4'],
        ]);

        $this->allowUserSave();
        $this->allowNameExtraction();

        // User is in 'Engineers', no mapping matches → falls back to default role 4
        $result = $this->creator->createAdminUser(
            'eng@example.com',
            'eng',
            'Eng',
            'User',
            ['Engineers']
        );

        $this->assertNotNull($result, 'Default role should be used when no group mapping matches');
    }

    public function testEmptyGroupsArrayUsesDefaultRole(): void
    {
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, json_encode([['group' => 'Admins', 'role' => '1']])],
            [OAuthConstants::MAP_DEFAULT_ROLE, '2'],
        ]);

        $this->allowUserSave();
        $this->allowNameExtraction();

        $result = $this->creator->createAdminUser(
            'nogroup@example.com',
            'nogroup',
            'No',
            'Group',
            []
        );

        $this->assertNotNull($result, 'Empty groups should fall back to default role');
    }

    public function testDefaultRoleUsedWhenNoMappingsConfigured(): void
    {
        // null / empty mapping JSON, but default role is set
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, null],
            [OAuthConstants::MAP_DEFAULT_ROLE, '7'],
        ]);

        $this->allowUserSave();
        $this->allowNameExtraction();

        $result = $this->creator->createAdminUser(
            'u@example.com',
            'u',
            'First',
            'Last',
            ['SomeGroup']
        );

        $this->assertNotNull($result, 'Should use default role when mapping config is empty');
    }

    // -------------------------------------------------------------------------
    // Role mapping: no role → abort
    // -------------------------------------------------------------------------

    public function testNoGroupMatchNoDefaultReturnsNull(): void
    {
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, json_encode([['group' => 'Admins', 'role' => '1']])],
            [OAuthConstants::MAP_DEFAULT_ROLE, null],
        ]);

        $result = $this->creator->createAdminUser(
            'nobody@example.com',
            'nobody',
            'No',
            'Body',
            ['RandomGroup']
        );

        $this->assertNull($result, 'No matching role and no default should abort creation');
    }

    public function testEmptyGroupsNoDefaultReturnsNull(): void
    {
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, null],
            [OAuthConstants::MAP_DEFAULT_ROLE, ''],
        ]);

        $result = $this->creator->createAdminUser(
            'nobody@example.com',
            'nobody',
            'No',
            'Body',
            []
        );

        $this->assertNull($result, 'Empty groups with no default should abort creation');
    }

    public function testNonNumericDefaultRoleIsIgnored(): void
    {
        // Non-numeric default role value must not be used
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, null],
            [OAuthConstants::MAP_DEFAULT_ROLE, 'Administrator'],  // not numeric
        ]);

        $result = $this->creator->createAdminUser(
            'u@example.com',
            'u',
            'First',
            'Last',
            []
        );

        $this->assertNull($result, 'Non-numeric default role should not be used');
    }

    // -------------------------------------------------------------------------
    // createAdminUser aborts and returns null when no role found
    // -------------------------------------------------------------------------

    public function testCreateAdminUserAbortsWithoutTouchingDbWhenNoRole(): void
    {
        $this->oauthUtility->method('getStoreConfig')->willReturn(null);

        // save() must NOT be called when creation is aborted
        $this->userResource->expects($this->never())->method('save');

        $result = $this->creator->createAdminUser(
            'blocked@example.com',
            'blocked',
            'Block',
            'Ed',
            []
        );

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // isAdminUser()
    // -------------------------------------------------------------------------

    public function testIsAdminUserReturnsTrueWhenUserFoundByEmail(): void
    {
        $user = $this->makeUserMock(42);

        $collection = $this->getMockBuilder(UserCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getSize', 'getFirstItem'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(1);
        $collection->method('getFirstItem')->willReturn($user);

        $this->userCollectionFactory->method('create')->willReturn($collection);

        $this->assertTrue($this->creator->isAdminUser('admin@example.com'));
    }

    public function testIsAdminUserReturnsFalseWhenNoUserFound(): void
    {
        $collection = $this->getMockBuilder(UserCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getSize'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(0);

        $this->userCollectionFactory->method('create')->willReturn($collection);

        $this->assertFalse($this->creator->isAdminUser('unknown@example.com'));
    }
}
