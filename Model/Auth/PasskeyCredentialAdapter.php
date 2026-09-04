<?php

declare(strict_types=1);

/**
 * Passkey Credential Storage Adapter
 *
 * Implements Magento's credential storage interface to allow passkey
 * (WebAuthn)-authenticated admin users to work with Magento's standard
 * Auth::login() flow. Structurally identical to OidcCredentialAdapter — see
 * that class for the detailed rationale of each pattern — but bridges the
 * passkey ephemeral token instead of the OIDC one, so the two authentication
 * methods remain independent and cannot be confused for one another.
 *
 * @package M2Oidc\OAuth\Model\Auth
 */
namespace M2Oidc\OAuth\Model\Auth;

use Magento\Backend\Model\Auth\Credential\StorageInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\AuthenticationException;
use Magento\User\Model\UserFactory;
use Magento\User\Model\ResourceModel\User as UserResourceModel;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Helper\PasskeySecurityHelper;

class PasskeyCredentialAdapter implements StorageInterface
{
    /** @var UserFactory|null */
    private ?UserFactory $userFactory = null;

    /** @var ManagerInterface|null */
    private ?ManagerInterface $eventManager = null;

    /** @var OAuthUtility|null */
    private ?OAuthUtility $oauthUtility = null;

    /** @var PasskeySecurityHelper|null */
    private ?PasskeySecurityHelper $securityHelper = null;

    /** @var \Magento\User\Model\User|null */
    private ?\Magento\User\Model\User $user = null;

    /** @var bool */
    private bool $hasAvailableResources = false;

    /** @var UserResourceModel|null */
    private ?UserResourceModel $userResource = null;

    /** @var UserCollectionFactory|null */
    private ?UserCollectionFactory $userCollectionFactory = null;

    /**
     * @param UserFactory           $userFactory
     * @param ManagerInterface      $eventManager
     * @param OAuthUtility          $oauthUtility
     * @param UserResourceModel     $userResource
     * @param UserCollectionFactory $userCollectionFactory
     * @param PasskeySecurityHelper $securityHelper
     */
    public function __construct(
        UserFactory $userFactory,
        ManagerInterface $eventManager,
        OAuthUtility $oauthUtility,
        UserResourceModel $userResource,
        UserCollectionFactory $userCollectionFactory,
        PasskeySecurityHelper $securityHelper
    ) {
        $this->userFactory = $userFactory;
        $this->eventManager = $eventManager;
        $this->oauthUtility = $oauthUtility;
        $this->userResource = $userResource;
        $this->userCollectionFactory = $userCollectionFactory;
        $this->securityHelper = $securityHelper;
    }

    /**
     * Restore DI dependencies after session deserialization.
     *
     * @internal
     */
    protected function restoreDependencies(): void
    {
        if ($this->userFactory instanceof UserFactory) {
            return;
        }

        // phpcs:ignore Magento2.Security.InsecureFunction.FoundWithAlternative
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->userFactory           = $objectManager->get(UserFactory::class);
        $this->eventManager          = $objectManager->get(ManagerInterface::class);
        $this->oauthUtility          = $objectManager->get(OAuthUtility::class);
        $this->userResource          = $objectManager->get(UserResourceModel::class);
        $this->userCollectionFactory = $objectManager->get(UserCollectionFactory::class);
        $this->securityHelper        = $objectManager->get(PasskeySecurityHelper::class);
    }

    /**
     * @param  string $message
     */
    protected function log(string $message): void
    {
        $this->oauthUtility?->customlog($message);
    }

    /**
     * Authenticate a passkey-verified admin user.
     *
     * Does NOT verify a password — the WebAuthn assertion was already
     * cryptographically verified in Controller/Adminhtml/Actions/Passkey/LoginVerify.php.
     *
     * @param  string $username User email
     * @param  string $password Ephemeral passkey auth token (see PasskeySecurityHelper::createPasskeyAuthToken)
     * @throws AuthenticationException
     */
    #[\Override]
    public function authenticate($username, $password): bool
    {
        $this->restoreDependencies();

        if (!$this->securityHelper instanceof \M2Oidc\OAuth\Helper\PasskeySecurityHelper
            || !$this->eventManager instanceof \Magento\Framework\Event\ManagerInterface
            || !$this->userCollectionFactory instanceof \Magento\User\Model\ResourceModel\User\CollectionFactory
        ) {
            throw new \RuntimeException('PasskeyCredentialAdapter: dependencies not available');
        }

        $this->log("PasskeyCredentialAdapter: Starting authentication for: " . $username);

        if (!$this->securityHelper->validateAndConsumePasskeyAuthToken($username, $password)) {
            $this->log("ERROR: Invalid or expired passkey auth token");
            throw new AuthenticationException(__('Invalid authentication method'));
        }

        $this->eventManager->dispatch(
            'admin_user_authenticate_before',
            [
            'username' => $username,
            'user' => null,
            'passkey_auth' => true
            ]
        );

        $userCollection = $this->userCollectionFactory->create()
            ->addFieldToFilter('email', $username);

        if ($userCollection->getSize() === 0) {
            $this->log("ERROR: Admin user not found for email: " . $username);
            throw new AuthenticationException(
                __('Admin user not found for email: %1', $username)
            );
        }

        /** @var \Magento\User\Model\User $user */
        $user = $userCollection->getFirstItem();
        $this->user = $user;

        $this->log("User found - ID: " . $user->getId() . ", Username: " . $user->getUsername());

        if (!$user->getIsActive()) {
            $this->log("ERROR: Admin user is inactive (ID: " . $user->getId() . ")");
            throw new AuthenticationException(
                __('Admin account is inactive. Please contact your administrator.')
            );
        }

        $hasRole = $user->hasAssigned2Role($user->getId());
        if (!$hasRole) {
            $this->log("ERROR: Admin user has no assigned role (ID: " . $user->getId() . ")");
            throw new AuthenticationException(
                __('Admin user has no assigned role. Please contact your administrator.')
            );
        }

        $this->eventManager->dispatch(
            'admin_user_authenticate_after',
            [
            'username' => $username,
            'password' => '[PASSKEY]', // never dispatch empty password; avoids security alerts
            'user' => $this->user,
            'result' => true,
            'passkey_auth' => true
            ]
        );

        $this->log("Authentication successful for: " . $username);
        return true;
    }

    /**
     * @param  string $username
     * @param  string $password
     * @throws AuthenticationException
     */
    #[\Override]
    public function login($username, $password): static
    {
        $this->restoreDependencies();

        if (!$this->userResource instanceof \Magento\User\Model\ResourceModel\User) {
            throw new \RuntimeException('PasskeyCredentialAdapter: userResource dependency not available');
        }

        if ($this->authenticate($username, $password)) {
            if (!$this->user instanceof \Magento\User\Model\User) {
                throw new \RuntimeException('PasskeyCredentialAdapter: User not loaded after authentication');
            }
            $this->userResource->recordLogin($this->user);
            $this->log("Login recorded for user ID: " . $this->user->getId());
            $this->reload();
        }

        return $this;
    }

    #[\Override]
    public function reload(): static
    {
        $this->restoreDependencies();

        if (!$this->userFactory instanceof \Magento\User\Model\UserFactory
            || !$this->userResource instanceof \Magento\User\Model\ResourceModel\User
        ) {
            throw new \RuntimeException('PasskeyCredentialAdapter: userFactory or userResource dependency not available');
        }

        /** @psalm-suppress DocblockTypeContradiction */
        if (!$this->user instanceof \Magento\User\Model\User) {
            return $this;
        }

        if ($this->user->getId()) {
            $userId = $this->user->getId();
            $this->user = $this->userFactory->create();
            $this->userResource->load($this->user, $userId);
        }

        return $this;
    }

    #[\Override]
    public function hasAvailableResources(): bool
    {
        return $this->hasAvailableResources;
    }

    /**
     * @param  bool $hasResources
     */
    #[\Override]
    public function setHasAvailableResources($hasResources): static
    {
        $this->hasAvailableResources = (bool)$hasResources;
        return $this;
    }

    public function getUser(): ?\Magento\User\Model\User
    {
        return $this->user;
    }

    public function getId(): ?int
    {
        $id = $this->user?->getId();
        return $id !== null ? (int) $id : null;
    }

    public function getIsActive(): bool
    {
        if (!$this->user instanceof \Magento\User\Model\User) {
            return false;
        }
        return (bool) $this->user->getIsActive();
    }

    /**
     * @return array{userId: int|null, hasAvailableResources: bool}
     */
    public function __serialize(): array
    {
        $userId = $this->user?->getId();
        return [
            'userId'                => $userId !== null ? (int) $userId : null,
            'hasAvailableResources' => $this->hasAvailableResources,
        ];
    }

    /**
     * @param mixed[] $data Serialized state array with userId and hasAvailableResources
     */
    public function __unserialize(array $data): void
    {
        $this->hasAvailableResources = (bool) $data['hasAvailableResources'];
        $this->restoreDependencies();

        $userId = isset($data['userId']) ? $data['userId'] : null;
        if ($userId !== null
            && $this->userFactory instanceof \Magento\User\Model\UserFactory
            && $this->userResource instanceof \Magento\User\Model\ResourceModel\User
        ) {
            $this->user = $this->userFactory->create();
            $this->userResource->load($this->user, $userId);
            if (!$this->user->getId()) {
                $this->user = null;
            }
        }
    }

    /**
     * Proxies to the underlying user model. Magento core (e.g.
     * Auth\Session::refreshAcl()) calls proxied getters unconditionally on
     * every dispatch, even after it has already determined the session is
     * not logged in — so returning null here (rather than throwing) is
     * required for a graceful "not logged in" degrade instead of a fatal
     * error on every request once $user is unset.
     *
     * @param  string  $method Method name to proxy
     * @param  mixed[] $args   Method arguments
     */
    public function __call(string $method, array $args): mixed
    {
        /** @psalm-suppress DocblockTypeContradiction */
        if (!$this->user instanceof \Magento\User\Model\User) {
            return null;
        }
        return $this->user->{$method}(...$args);
    }
}
