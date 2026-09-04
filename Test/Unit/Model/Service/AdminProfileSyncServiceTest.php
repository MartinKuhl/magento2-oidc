<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Service;

use Magento\Authorization\Model\ResourceModel\Role\Collection as RoleCollection;
use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Authorization\Model\Role;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use M2Oidc\OAuth\Test\Unit\Fixtures\UserWithRoleId;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Provider\MappingRepository;
use M2Oidc\OAuth\Model\Service\AdminProfileSyncService;
use M2Oidc\OAuth\Model\Service\GroupMappingResolver;
use M2Oidc\OAuth\Model\Service\OidcAuthenticationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminProfileSyncService.
 *
 * syncProfile()/syncRole() now accept an already-loaded \Magento\User\Model\User
 * instead of an email string (the caller, CheckAttributeMappingAction, loads the user
 * once and reuses it). Tests below pass a pre-built user mock directly.
 *
 * Verifies:
 *  - syncProfile() updates fields on change and skips when unchanged
 *  - syncProfile() respects per-attribute sync_on_sso flag (normalized mode)
 *  - syncProfile() uses legacy mode (always sync) when no normalized row exists
 *  - syncProfile() enforces username uniqueness
 *  - syncRole() assigns role from normalized mappings, legacy JSON, default role
 *  - syncRole() skips when user already has the target role
 *  - assignRoleByName() logs and skips when role not found
 *
 * @covers \M2Oidc\OAuth\Model\Service\AdminProfileSyncService
 */
class AdminProfileSyncServiceTest extends TestCase
{
    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var UserFactory&MockObject */
    private UserFactory $userFactory;

    /** @var UserResource&MockObject */
    private UserResource $userResource;

    /** @var RoleCollectionFactory&MockObject */
    private RoleCollectionFactory $roleCollectionFactory;

    /** @var OidcAuthenticationService&MockObject */
    private OidcAuthenticationService $oidcAuthenticationService;

    /** @var MappingRepository&MockObject */
    private MappingRepository $mappingRepository;

    /** @var AdminProfileSyncService */
    private AdminProfileSyncService $service;

    protected function setUp(): void
    {
        $this->oauthUtility              = $this->createMock(OAuthUtility::class);
        $this->userFactory               = $this->createMock(UserFactory::class);
        $this->userResource              = $this->createMock(UserResource::class);
        $this->roleCollectionFactory     = $this->createMock(RoleCollectionFactory::class);
        $this->oidcAuthenticationService = $this->createMock(OidcAuthenticationService::class);
        $this->mappingRepository         = $this->createMock(MappingRepository::class);

        $this->oauthUtility->method('customlog');

        $this->service = new AdminProfileSyncService(
            $this->userFactory,
            $this->userResource,
            $this->roleCollectionFactory,
            $this->oauthUtility,
            $this->oidcAuthenticationService,
            $this->mappingRepository,
            new GroupMappingResolver($this->mappingRepository, $this->oauthUtility)
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a User mock with preset getter return values.
     * setRoleId() is declared on the UserWithRoleId test double since it is
     * only reachable via User's magic __call() on the real class.
     *
     * @param array<string> $roles
     */
    private function makeUserMock(
        int $id = 1,
        string $firstName = 'Alice',
        string $lastName = 'Smith',
        string $username = 'asmith',
        string $email = 'a@example.com',
        array  $roles = []
    ): User&MockObject {
        $user = $this->getMockBuilder(UserWithRoleId::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId', 'getFirstName', 'getLastName', 'getUserName', 'getEmail', 'getRoles',
                'setFirstName', 'setLastName', 'setUserName', 'setEmail', 'setHasDataChanges',
                'setRoleId',
            ])
            ->getMock();

        $user->method('getId')->willReturn($id);
        $user->method('getFirstName')->willReturn($firstName);
        $user->method('getLastName')->willReturn($lastName);
        $user->method('getUserName')->willReturn($username);
        $user->method('getEmail')->willReturn($email);
        $user->method('getRoles')->willReturn($roles);

        return $user;
    }

    /**
     * Build a User mock that represents "not found" (getId returns 0/null).
     */
    private function makeNotFoundUserMock(): User&MockObject
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $user->method('getId')->willReturn(0);
        return $user;
    }

    /**
     * Build a stubbed Role mock with a given ID.
     */
    private function makeRoleMock(?int $id): Role&MockObject
    {
        $role = $this->createMock(Role::class);
        $role->method('getId')->willReturn($id);
        return $role;
    }

    /**
     * Build a fluent RoleCollection mock returning the given role as first item.
     */
    private function makeRoleCollectionMock(Role&MockObject $role): RoleCollection&MockObject
    {
        $collection = $this->createMock(RoleCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($role);
        return $collection;
    }

    // -------------------------------------------------------------------------
    // syncProfile – user not found (caller passes an unloaded User)
    // -------------------------------------------------------------------------

    public function testSyncProfileDoesNothingWhenUserNotFound(): void
    {
        $this->userResource->expects($this->never())->method('save');

        $this->service->syncProfile(
            $this->makeNotFoundUserMock(),
            ['given_name' => 'Alice'],
            [],
            'given_name',
            'family_name',
            'preferred_username'
        );
    }

    // -------------------------------------------------------------------------
    // syncProfile – firstname
    // -------------------------------------------------------------------------

    public function testSyncProfileUpdatesFirstnameWhenChanged(): void
    {
        $user = $this->makeUserMock(firstName: 'OldFirst');

        $user->expects($this->once())->method('setFirstName')->with('NewFirst');
        $this->userResource->expects($this->once())->method('save');

        $this->service->syncProfile(
            $user,
            ['given_name' => 'NewFirst'],
            [],
            'given_name',
            'family_name',
            'preferred_username'
        );
    }

    public function testSyncProfileSkipsFirstnameWhenUnchanged(): void
    {
        $user = $this->makeUserMock(firstName: 'Alice');

        $user->expects($this->never())->method('setFirstName');
        $this->userResource->expects($this->never())->method('save');

        $this->service->syncProfile(
            $user,
            ['given_name' => 'Alice'],
            [],
            'given_name',
            'family_name',
            'preferred_username'
        );
    }

    public function testSyncProfileSkipsFirstnameWhenKeyNotInAttrs(): void
    {
        $user = $this->makeUserMock(firstName: 'OldFirst');

        // 'given_name' not present in flat or raw → extract returns null → skip
        $user->expects($this->never())->method('setFirstName');
        $this->userResource->expects($this->never())->method('save');

        $this->service->syncProfile($user, [], [], 'given_name', 'family_name', 'preferred_username');
    }

    // -------------------------------------------------------------------------
    // syncProfile – lastname
    // -------------------------------------------------------------------------

    public function testSyncProfileUpdatesLastnameWhenChanged(): void
    {
        $user = $this->makeUserMock(lastName: 'OldLast');

        $user->expects($this->once())->method('setLastName')->with('Jones');
        $this->userResource->expects($this->once())->method('save');

        $this->service->syncProfile(
            $user,
            ['family_name' => 'Jones'],
            [],
            'given_name',
            'family_name',
            'preferred_username'
        );
    }

    public function testSyncProfileSkipsLastnameWhenUnchanged(): void
    {
        $user = $this->makeUserMock(lastName: 'Smith');

        $user->expects($this->never())->method('setLastName');
        $this->userResource->expects($this->never())->method('save');

        $this->service->syncProfile($user, ['family_name' => 'Smith'], [], 'gn', 'family_name', 'un');
    }

    // -------------------------------------------------------------------------
    // syncProfile – username uniqueness
    // -------------------------------------------------------------------------

    public function testSyncProfileUpdatesUsernameWhenAvailable(): void
    {
        $user         = $this->makeUserMock(id: 1, username: 'old_user');
        $notFoundUser = $this->makeNotFoundUserMock(); // username check: no conflict

        // Only the uniqueness check now goes through the factory (the primary
        // user is passed in directly, no longer loaded via userFactory->create()).
        $this->userFactory->expects($this->once())->method('create')->willReturn($notFoundUser);

        $user->expects($this->once())->method('setUserName')->with('new_user');
        $this->userResource->expects($this->once())->method('save');

        $this->service->syncProfile(
            $user,
            ['preferred_username' => 'new_user'],
            [],
            'gn',
            'ln',
            'preferred_username'
        );
    }

    public function testSyncProfileSkipsUsernameWhenTakenByAnotherUser(): void
    {
        $user        = $this->makeUserMock(id: 1, username: 'old_user');
        $conflicting = $this->makeUserMock(id: 99); // different user owns the new username

        $this->userFactory->expects($this->once())->method('create')->willReturn($conflicting);

        $user->expects($this->never())->method('setUserName');
        $this->userResource->expects($this->never())->method('save');

        $this->service->syncProfile(
            $user,
            ['preferred_username' => 'taken'],
            [],
            'gn',
            'ln',
            'preferred_username'
        );
    }

    public function testSyncProfileAllowsUsernameUpdateWhenSameUserOwnsIt(): void
    {
        // The username already "belongs" to the same user ID — still update it
        $user     = $this->makeUserMock(id: 5, username: 'old_user');
        $sameUser = $this->makeUserMock(id: 5); // same ID as $user

        $this->userFactory->expects($this->once())->method('create')->willReturn($sameUser);

        $user->expects($this->once())->method('setUserName')->with('new_user');
        $this->userResource->expects($this->once())->method('save');

        $this->service->syncProfile(
            $user,
            ['preferred_username' => 'new_user'],
            [],
            'gn',
            'ln',
            'preferred_username'
        );
    }

    public function testSyncProfileSkipsUsernameWhenUnchanged(): void
    {
        $user = $this->makeUserMock(username: 'asmith');

        // Uniqueness check is only performed when the username actually changes.
        $this->userFactory->expects($this->never())->method('create');
        $user->expects($this->never())->method('setUserName');

        $this->service->syncProfile(
            $user,
            ['preferred_username' => 'asmith'],
            [],
            'gn',
            'ln',
            'preferred_username'
        );
    }

    // -------------------------------------------------------------------------
    // syncProfile – email
    // -------------------------------------------------------------------------

    public function testSyncProfileUpdatesEmailWhenChanged(): void
    {
        $user = $this->makeUserMock(email: 'old@example.com');

        $user->expects($this->once())->method('setEmail')->with('new@example.com');
        $this->userResource->expects($this->once())->method('save');

        $this->service->syncProfile($user, ['email' => 'new@example.com'], [], 'gn', 'ln', 'un', 'email');
    }

    public function testSyncProfileSkipsEmailWhenEmailKeyIsEmpty(): void
    {
        $user = $this->makeUserMock(email: 'a@example.com');

        $user->expects($this->never())->method('setEmail');

        // emailKey='' → extract returns null → skip
        $this->service->syncProfile($user, ['email' => 'new@example.com'], [], 'gn', 'ln', 'un', '');
    }

    public function testSyncProfileSkipsEmailWhenUnchanged(): void
    {
        $user = $this->makeUserMock(email: 'same@example.com');

        $user->expects($this->never())->method('setEmail');
        $this->userResource->expects($this->never())->method('save');

        $this->service->syncProfile($user, ['email' => 'same@example.com'], [], 'gn', 'ln', 'un', 'email');
    }

    // -------------------------------------------------------------------------
    // syncProfile – per-attribute sync_on_sso=0 (normalized mode)
    // -------------------------------------------------------------------------

    public function testSyncProfileSkipsFirstnameWhenSyncOnSsoIsZero(): void
    {
        $user = $this->makeUserMock(id: 1, firstName: 'OldFirst');

        $this->mappingRepository->method('getFullAttributeMap')->with(1)->willReturn([
            'firstname' => ['sync_on_sso' => 0],
        ]);

        $user->expects($this->never())->method('setFirstName');
        $this->userResource->expects($this->never())->method('save');

        $this->service->syncProfile($user, ['given_name' => 'NewFirst'], [], 'given_name', 'ln', 'un', '', 1);
    }

    public function testSyncProfileSyncsFirstnameWhenSyncOnSsoIsOne(): void
    {
        $user = $this->makeUserMock(id: 1, firstName: 'OldFirst');

        $this->mappingRepository->method('getFullAttributeMap')->with(2)->willReturn([
            'firstname' => ['sync_on_sso' => 1],
        ]);

        $user->expects($this->once())->method('setFirstName')->with('NewFirst');
        $this->userResource->expects($this->once())->method('save');

        $this->service->syncProfile($user, ['given_name' => 'NewFirst'], [], 'given_name', 'ln', 'un', '', 2);
    }

    public function testSyncProfileSkipsLastnameWhenSyncOnSsoIsZero(): void
    {
        $user = $this->makeUserMock(id: 1, lastName: 'OldLast');

        $this->mappingRepository->method('getFullAttributeMap')->with(3)->willReturn([
            'lastname' => ['sync_on_sso' => 0],
        ]);

        $user->expects($this->never())->method('setLastName');
        $this->userResource->expects($this->never())->method('save');

        $this->service->syncProfile(
            $user,
            ['family_name' => 'NewLast'],
            [],
            'gn',
            'family_name',
            'un',
            '',
            3
        );
    }

    public function testSyncProfileSkipsUsernameWhenSyncOnSsoIsZero(): void
    {
        $user = $this->makeUserMock(id: 1, username: 'old_user');

        $this->mappingRepository->method('getFullAttributeMap')->with(4)->willReturn([
            'username' => ['sync_on_sso' => 0],
        ]);

        $user->expects($this->never())->method('setUserName');
        $this->userResource->expects($this->never())->method('save');

        $this->service->syncProfile(
            $user,
            ['preferred_username' => 'new_user'],
            [],
            'gn',
            'ln',
            'preferred_username',
            '',
            4
        );
    }

    public function testSyncProfileSkipsEmailWhenSyncOnSsoIsZero(): void
    {
        $user = $this->makeUserMock(id: 1, email: 'old@example.com');

        $this->mappingRepository->method('getFullAttributeMap')->with(5)->willReturn([
            'email' => ['sync_on_sso' => 0],
        ]);

        $user->expects($this->never())->method('setEmail');
        $this->userResource->expects($this->never())->method('save');

        $this->service->syncProfile(
            $user,
            ['email' => 'new@example.com'],
            [],
            'gn',
            'ln',
            'un',
            'email',
            5
        );
    }

    // -------------------------------------------------------------------------
    // syncProfile – legacy mode: no normalized row → always sync
    // -------------------------------------------------------------------------

    public function testSyncProfileLegacyModeAlwaysSyncsWhenNoNormalizedRow(): void
    {
        $user = $this->makeUserMock(id: 1, firstName: 'OldFirst');

        // Empty attrMap = legacy mode, attribute IS synced
        $this->mappingRepository->method('getFullAttributeMap')->with(6)->willReturn([]);

        $user->expects($this->once())->method('setFirstName');
        $this->userResource->expects($this->once())->method('save');

        $this->service->syncProfile($user, ['given_name' => 'NewFirst'], [], 'given_name', 'ln', 'un', '', 6);
    }

    public function testSyncProfileLegacyModeWithProviderIdZeroNeverCallsMappingRepository(): void
    {
        $user = $this->makeUserMock(id: 1, firstName: 'OldFirst');

        $this->mappingRepository->expects($this->never())->method('getFullAttributeMap');

        $this->service->syncProfile($user, ['given_name' => 'NewFirst'], [], 'given_name', 'ln', 'un', '', 0);
    }

    // -------------------------------------------------------------------------
    // syncProfile – no changes → no save
    // -------------------------------------------------------------------------

    public function testSyncProfileDoesNotSaveWhenNothingChanged(): void
    {
        $user = $this->makeUserMock(firstName: 'Alice', lastName: 'Smith', username: 'asmith', email: 'a@example.com');

        $this->userResource->expects($this->never())->method('save');

        // All attrs are empty → extract returns null → no changes
        $this->service->syncProfile($user, [], [], 'given_name', 'family_name', 'preferred_username');
    }

    public function testSyncProfileDoesNotSaveWhenAllValuesMatch(): void
    {
        $user = $this->makeUserMock(firstName: 'Alice', lastName: 'Smith');

        $this->userResource->expects($this->never())->method('save');

        $this->service->syncProfile(
            $user,
            ['given_name' => 'Alice', 'family_name' => 'Smith'],
            [],
            'given_name',
            'family_name',
            'preferred_username'
        );
    }

    // -------------------------------------------------------------------------
    // syncProfile – extract from raw attrs fallback
    // -------------------------------------------------------------------------

    public function testSyncProfileExtractsFromRawAttrsWhenNotInFlat(): void
    {
        $user = $this->makeUserMock(firstName: 'OldFirst');

        // Flat is empty; value is in raw
        $user->expects($this->once())->method('setFirstName')->with('RawFirst');
        $this->userResource->expects($this->once())->method('save');

        $this->service->syncProfile($user, [], ['given_name' => 'RawFirst'], 'given_name', 'ln', 'un');
    }

    public function testSyncProfileFlatAttrsTakePriorityOverRaw(): void
    {
        $user = $this->makeUserMock(firstName: 'OldFirst');

        // Both flat and raw have 'given_name' — flat wins
        $user->expects($this->once())->method('setFirstName')->with('FlatFirst');
        $this->userResource->expects($this->once())->method('save');

        $this->service->syncProfile(
            $user,
            ['given_name' => 'FlatFirst'],
            ['given_name' => 'RawFirst'],
            'given_name',
            'ln',
            'un'
        );
    }

    // -------------------------------------------------------------------------
    // syncProfile – multiple fields changed → single save
    // -------------------------------------------------------------------------

    public function testSyncProfileSavesOnceWhenMultipleFieldsChange(): void
    {
        $user         = $this->makeUserMock(id: 1, firstName: 'OldFirst', lastName: 'OldLast', username: 'old_user');
        $notFoundUser = $this->makeNotFoundUserMock();

        $this->userFactory->expects($this->once())->method('create')->willReturn($notFoundUser);

        $user->expects($this->once())->method('setFirstName');
        $user->expects($this->once())->method('setLastName');
        $user->expects($this->once())->method('setUserName');
        $this->userResource->expects($this->once())->method('save'); // single save

        $this->service->syncProfile(
            $user,
            ['given_name' => 'NewFirst', 'family_name' => 'NewLast', 'preferred_username' => 'new_user'],
            [],
            'given_name',
            'family_name',
            'preferred_username'
        );
    }

    // -------------------------------------------------------------------------
    // syncProfile – array value in attr is collapsed to first element
    // -------------------------------------------------------------------------

    public function testSyncProfileExtractsFirstValueFromArrayAttr(): void
    {
        $user = $this->makeUserMock(firstName: 'OldFirst');

        $user->expects($this->once())->method('setFirstName')->with('ArrayFirst');
        $this->userResource->expects($this->once())->method('save');

        // Array value: service collapses to first element
        $this->service->syncProfile(
            $user,
            ['given_name' => ['ArrayFirst', 'Extra']],
            [],
            'given_name',
            'ln',
            'un'
        );
    }

    // -------------------------------------------------------------------------
    // syncRole – user not found (caller passes an unloaded User)
    // -------------------------------------------------------------------------

    public function testSyncRoleDoesNothingWhenUserNotFound(): void
    {
        $this->userResource->expects($this->never())->method('save');

        $this->service->syncRole($this->makeNotFoundUserMock(), [], [], 'groups', [], '');
    }

    // -------------------------------------------------------------------------
    // syncRole – no groups in OIDC response
    // -------------------------------------------------------------------------

    public function testSyncRoleSkipsWhenNoGroupsAndNoDefaultRole(): void
    {
        $user = $this->makeUserMock();
        $this->oidcAuthenticationService->method('normalizeGroups')->willReturn([]);

        $this->userResource->expects($this->never())->method('save');

        $this->service->syncRole($user, [], [], 'groups', [], '');
    }

    public function testSyncRoleSkipsWhenNoGroupsAndNoRoleMappings(): void
    {
        $user = $this->makeUserMock();
        $this->oidcAuthenticationService->method('normalizeGroups')->willReturn([]);

        $this->userResource->expects($this->never())->method('save');

        // No role mappings configured AND no default role
        $this->service->syncRole($user, [], [], 'groups', [], '');
    }

    public function testSyncRoleAssignsDefaultRoleWhenNoGroupsInResponse(): void
    {
        $user = $this->makeUserMock(roles: []);
        $this->oidcAuthenticationService->method('normalizeGroups')->willReturn([]);

        $role = $this->makeRoleMock(3);
        $this->roleCollectionFactory->method('create')->willReturn($this->makeRoleCollectionMock($role));

        $user->expects($this->once())->method('setRoleId')->with(3);
        $this->userResource->expects($this->once())->method('save')->with($user);

        // Groups claim is empty but role mappings exist; default role is configured
        $this->service->syncRole(
            $user,
            [],
            [],
            'groups',
            [['group' => 'admins', 'role' => '1']],
            'FallbackRole'
        );
    }

    // -------------------------------------------------------------------------
    // syncRole – group matching
    // -------------------------------------------------------------------------

    public function testSyncRoleAssignsFirstMatchingRole(): void
    {
        $user = $this->makeUserMock(roles: []);
        $this->oidcAuthenticationService->method('normalizeGroups')->willReturn(['engineers', 'staff']);

        $user->expects($this->once())->method('setRoleId')->with(7);
        $this->userResource->expects($this->once())->method('save');

        $roleMappings = [
            ['group' => 'engineers', 'role' => '7'],
            ['group' => 'staff',     'role' => '2'],
        ];

        $this->service->syncRole(
            $user,
            ['groups' => ['engineers', 'staff']],
            [],
            'groups',
            $roleMappings,
            ''
        );
    }

    public function testSyncRoleAssignsSecondMappingWhenFirstGroupDoesNotMatch(): void
    {
        $user = $this->makeUserMock(roles: []);
        $this->oidcAuthenticationService->method('normalizeGroups')->willReturn(['staff']);

        $user->expects($this->once())->method('setRoleId')->with(2);
        $this->userResource->expects($this->once())->method('save');

        $roleMappings = [
            ['group' => 'engineers', 'role' => '7'],
            ['group' => 'staff',     'role' => '2'],
        ];

        $this->service->syncRole($user, ['groups' => ['staff']], [], 'groups', $roleMappings, '');
    }

    public function testSyncRoleSkipsWhenNoMappingMatchesAndNoDefaultRole(): void
    {
        $user = $this->makeUserMock(roles: []);
        $this->oidcAuthenticationService->method('normalizeGroups')->willReturn(['unknown_group']);

        $this->userResource->expects($this->never())->method('save');

        $roleMappings = [['group' => 'engineers', 'role' => '7']];

        $this->service->syncRole($user, ['groups' => ['unknown_group']], [], 'groups', $roleMappings, '');
    }

    public function testSyncRoleUsesDefaultRoleWhenNoMappingMatches(): void
    {
        $user = $this->makeUserMock(roles: []);
        $this->oidcAuthenticationService->method('normalizeGroups')->willReturn(['unknown']);

        $role = $this->makeRoleMock(5);
        $this->roleCollectionFactory->method('create')->willReturn($this->makeRoleCollectionMock($role));

        $user->expects($this->once())->method('setRoleId')->with(5);
        $this->userResource->expects($this->once())->method('save');

        $this->service->syncRole(
            $user,
            [],
            [],
            'groups',
            [['group' => 'admins', 'role' => '1']],
            'FallbackRole'
        );
    }

    // -------------------------------------------------------------------------
    // syncRole – already has the target role → no save
    // -------------------------------------------------------------------------

    public function testSyncRoleSkipsWhenUserAlreadyHasTargetRole(): void
    {
        $user = $this->makeUserMock(roles: ['7']); // role 7 already assigned (string)
        $this->oidcAuthenticationService->method('normalizeGroups')->willReturn(['engineers']);

        $this->userResource->expects($this->never())->method('save');

        $roleMappings = [['group' => 'engineers', 'role' => '7']];

        $this->service->syncRole($user, ['groups' => ['engineers']], [], 'groups', $roleMappings, '');
    }

    public function testSyncRoleSkipsWhenUserAlreadyHasTargetRoleAsInt(): void
    {
        $user = $this->makeUserMock(roles: ['7']); // role 7 as integer
        $this->oidcAuthenticationService->method('normalizeGroups')->willReturn(['engineers']);

        $this->userResource->expects($this->never())->method('save');

        $roleMappings = [['group' => 'engineers', 'role' => '7']];

        $this->service->syncRole($user, ['groups' => ['engineers']], [], 'groups', $roleMappings, '');
    }

    // -------------------------------------------------------------------------
    // syncRole – assignRoleByName: role not found → log and skip
    // -------------------------------------------------------------------------

    public function testSyncRoleLogsAndSkipsWhenDefaultRoleNotFound(): void
    {
        $user = $this->makeUserMock(roles: []);
        $this->oidcAuthenticationService->method('normalizeGroups')->willReturn([]);

        $emptyRole = $this->makeRoleMock(null); // no ID — not found
        $this->roleCollectionFactory->method('create')->willReturn($this->makeRoleCollectionMock($emptyRole));

        $this->oauthUtility->expects($this->atLeastOnce())->method('customlog');
        $this->userResource->expects($this->never())->method('save');

        $this->service->syncRole(
            $user,
            [],
            [],
            'groups',
            [['group' => 'admins', 'role' => '1']],
            'NonExistentRole'
        );
    }

    // -------------------------------------------------------------------------
    // syncRole – assignRoleByName: role already assigned → no save
    // -------------------------------------------------------------------------

    public function testSyncRoleAssignByNameSkipsWhenRoleAlreadyAssigned(): void
    {
        $user = $this->makeUserMock(roles: ['5']); // already has role 5
        $this->oidcAuthenticationService->method('normalizeGroups')->willReturn([]);

        $role = $this->makeRoleMock(5);
        $this->roleCollectionFactory->method('create')->willReturn($this->makeRoleCollectionMock($role));

        $this->userResource->expects($this->never())->method('save');

        $this->service->syncRole(
            $user,
            [],
            [],
            'groups',
            [['group' => 'admins', 'role' => '1']],
            'DefaultRole'
        );
    }

    // -------------------------------------------------------------------------
    // syncRole – empty group attribute key → extractGroups returns []
    // -------------------------------------------------------------------------

    public function testSyncRoleSkipsWhenGroupAttributeKeyIsEmpty(): void
    {
        $user = $this->makeUserMock(roles: []);

        // normalizeGroups returns empty when key is ''
        $this->oidcAuthenticationService->method('normalizeGroups')->willReturn([]);

        $this->userResource->expects($this->never())->method('save');

        $this->service->syncRole($user, [], [], '', [['group' => 'admins', 'role' => '1']], '');
    }

    // -------------------------------------------------------------------------
    // syncRole – malformed role mappings (missing keys) are skipped gracefully
    // -------------------------------------------------------------------------

    public function testSyncRoleSkipsMalformedMappingRows(): void
    {
        $user = $this->makeUserMock(roles: []);
        $this->oidcAuthenticationService->method('normalizeGroups')->willReturn(['engineers']);

        // Rows with empty group or role keys
        $roleMappings = [
            ['group' => '',          'role' => '7'],  // empty group → skip
            ['group' => 'engineers', 'role' => ''],   // empty role  → would match but role '' is cast to 0
        ];

        // No valid match → no save (even though 'engineers' appears in user groups,
        // the matching row has an empty role and the service casts it to 0)
        $this->userResource->expects($this->never())->method('save');

        $this->service->syncRole($user, ['groups' => ['engineers']], [], 'groups', $roleMappings, '');
    }
}
