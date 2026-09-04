<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions;

use M2Oidc\OAuth\Helper\Exception\MissingAttributesException;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthMessages;
use M2Oidc\OAuth\Model\Data\OidcAttributeMappingContext;
use M2Oidc\OAuth\Model\Data\OidcUserProvisioningContext;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;
use M2Oidc\OAuth\Helper\OAuthSecurityHelper;
use M2Oidc\OAuth\Model\Service\AdminProfileSyncService;
use M2Oidc\OAuth\Model\Service\AdminUserCreator;
use M2Oidc\OAuth\Model\Service\OidcAuthenticationService;
use M2Oidc\OAuth\Model\Service\UserProvisioningService;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;

/**
 * Check and process OAuth/OIDC attribute mapping
 *
 * This controller handles attribute mapping after successful authentication.
 * Admin users are redirected to a separate login endpoint that runs in the
 * adminhtml area context. Customer users proceed with the normal login flow.
 *
 * All logging respects the plugin's logging configuration and writes to
 * var/log/M2Oidc.log when enabled.
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class CheckAttributeMappingAction extends BaseAction
{
    /**
     * @var string|null User email extracted from attributes
     */
    private ?string $userEmail = null;

    /**
     * @var string|null Login type (admin|customer)
     */
    private ?string $loginType = null;

    /**
     * @var string Email attribute mapping key
     */
    private $emailAttribute;

    /**
     * @var string Username attribute mapping key
     */
    private $usernameAttribute;

    /**
     * @var string First name attribute mapping key
     */
    private $firstName;

    /**
     * @var string Last name attribute mapping key
     */
    private $lastName;

    /**
     * @var string Group attribute mapping key
     */
    private $groupName;

    /**
     * @var mixed[]|null Decoded access_control_rules from the provider row (FEAT-04)
     */
    private ?array $accessControlRules = null;

    /**
     * @var int|null Per-provider auto-create admin flag (null = fall back to global config)
     */
    private ?int $providerAutoCreateAdmin = null;

    /**
     * @var int|null Per-provider auto-create customer flag (null = fall back to global config)
     */
    private ?int $providerAutoCreateCustomer = null;

    /**
     * @var int OIDC provider ID (from m2oidc_oauth_client_apps.id) used to track user creation
     */
    private int $providerId = 0;

    /**
     * @var bool Headless PWA mode flag (FEAT-09): when true, CustomerLoginAction redirects
     *           to HeadlessOidcCallback which returns a customer token via postMessage.
     */
    private bool $headless = false;

    /** @var \M2Oidc\OAuth\Controller\Actions\ShowTestResults */
    private readonly \M2Oidc\OAuth\Controller\Actions\ShowTestResults $testAction;

    /** @var \M2Oidc\OAuth\Controller\Actions\ProcessUserAction */
    private readonly \M2Oidc\OAuth\Controller\Actions\ProcessUserAction $processUserAction;

    /** @var \Magento\User\Model\UserFactory */
    protected \Magento\User\Model\UserFactory $userFactory;

    /** @var \Magento\Backend\Model\UrlInterface */
    protected \Magento\Backend\Model\UrlInterface $backendUrl;

    /** @var \M2Oidc\OAuth\Model\Service\AdminUserCreator */
    protected \M2Oidc\OAuth\Model\Service\AdminUserCreator $adminUserCreator;

    /** @var \M2Oidc\OAuth\Model\Service\UserProvisioningService */
    private readonly UserProvisioningService $userProvisioningService;

    /** @var \Magento\Customer\Model\Session */
    protected \Magento\Customer\Model\Session $customerSession;

    /** @var \Magento\Framework\Stdlib\CookieManagerInterface */
    protected \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager;

    /** @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory */
    protected \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory;

    /** @var \M2Oidc\OAuth\Helper\OAuthSecurityHelper */
    private readonly \M2Oidc\OAuth\Helper\OAuthSecurityHelper $securityHelper;

    /** @var \M2Oidc\OAuth\Model\Service\AdminProfileSyncService */
    private readonly AdminProfileSyncService $adminProfileSyncService;

    /** @var \M2Oidc\OAuth\Model\Service\OidcAuthenticationService */
    private readonly OidcAuthenticationService $oidcAuthenticationService;

    /** @var \M2Oidc\OAuth\Model\ResourceModel\UserProvider */
    private readonly UserProviderResource $userProviderResource;

    /**
     * Constructor with dependency injection
     *
     * @param \Magento\Framework\App\Action\Context              $context
     * @param \M2Oidc\OAuth\Helper\OAuthUtility                  $oauthUtility
     * @param \M2Oidc\OAuth\Controller\Actions\ProcessUserAction $processUserAction
     * @param \Magento\User\Model\UserFactory                    $userFactory
     * @param \Magento\Backend\Model\UrlInterface                $backendUrl
     * @param AdminUserCreator                                   $adminUserCreator
     * @param \Magento\Customer\Model\Session                    $customerSession
     * @param \M2Oidc\OAuth\Controller\Actions\ShowTestResults   $testAction
     * @param OAuthSecurityHelper                                $securityHelper
     * @param CookieManagerInterface                             $cookieManager
     * @param CookieMetadataFactory                              $cookieMetadataFactory
     * @param UserProvisioningService                            $userProvisioningService
     * @param AdminProfileSyncService                            $adminProfileSyncService
     * @param OidcAuthenticationService                          $oidcAuthenticationService
     * @param UserProviderResource                               $userProviderResource
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility,
        \M2Oidc\OAuth\Controller\Actions\ProcessUserAction $processUserAction,
        \Magento\User\Model\UserFactory $userFactory,
        \Magento\Backend\Model\UrlInterface $backendUrl,
        AdminUserCreator $adminUserCreator,
        \Magento\Customer\Model\Session $customerSession,
        \M2Oidc\OAuth\Controller\Actions\ShowTestResults $testAction,
        OAuthSecurityHelper $securityHelper,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        UserProvisioningService $userProvisioningService,
        AdminProfileSyncService $adminProfileSyncService,
        OidcAuthenticationService $oidcAuthenticationService,
        UserProviderResource $userProviderResource
    ) {
        $this->testAction = $testAction;
        $this->processUserAction = $processUserAction;
        $this->userFactory = $userFactory;
        $this->backendUrl = $backendUrl;
        $this->adminUserCreator = $adminUserCreator;
        $this->customerSession = $customerSession;
        $this->securityHelper = $securityHelper;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->userProvisioningService = $userProvisioningService;
        $this->adminProfileSyncService = $adminProfileSyncService;
        $this->oidcAuthenticationService = $oidcAuthenticationService;
        $this->userProviderResource = $userProviderResource;
        parent::__construct($context, $oauthUtility);
    }

    /** @var bool Whether attribute mappings have been initialized for this request. */
    private bool $attributesInitialized = false;

    /**
     * Lazy-initialize attribute mappings from the active provider context.
     *
     * Called at the start of handle() after setActiveProviderId() has been
     * set on oauthUtility. Ensures mappings come from the correct provider row.
     */
    private function initAttributeMappings(): void
    {
        if ($this->attributesInitialized) {
            return;
        }
        $this->attributesInitialized = true;

        $this->emailAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL)
            ?: OAuthConstants::DEFAULT_MAP_EMAIL;

        $this->usernameAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME)
            ?: OAuthConstants::DEFAULT_MAP_USERN;

        $this->firstName = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_FIRSTNAME)
            ?: OAuthConstants::DEFAULT_MAP_FN;

        $this->lastName = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_LASTNAME)
            ?: OAuthConstants::DEFAULT_MAP_LN;

        $this->groupName = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_GROUP)
            ?: 'groups';
    }

    /**
     * Not dispatched via routing.
     *
     * CheckAttributeMappingAction is only ever constructed as a per-request collaborator
     * (see etc/di.xml, shared="false") and invoked via {@see handle()} — it has no entry
     * in any routes.xml. This override exists solely to satisfy the abstract execute()
     * contract inherited from BaseAction/\Magento\Framework\App\Action\Action.
     *
     * @throws \LogicException Always.
     */
    #[\Override]
    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        throw new \LogicException(
            'CheckAttributeMappingAction is not dispatched via routing; call handle() with a '
            . 'OidcAttributeMappingContext instead.'
        );
    }

    /**
     * Execute attribute mapping and route users accordingly
     *
     * Admin users are redirected to a separate callback endpoint that handles
     * admin authentication. Regular users proceed with the normal customer login flow.
     *
     * @param OidcAttributeMappingContext $context Immutable input replacing the former setter chain
     */
    public function handle(OidcAttributeMappingContext $context): \Magento\Framework\Controller\ResultInterface
    {
        // Apply per-provider attribute mappings and metadata from the client details row
        $this->applyClientDetails($context->clientDetails);
        $this->userEmail = $context->userEmail;
        $this->loginType = $context->loginType;
        $this->headless  = $context->headless;

        // Initialize attribute mappings from active provider context
        $this->initAttributeMappings();
        $attrs = $context->userInfoResponse ?? [];
        $flattenedAttrs = $context->flattenedUserInfoResponse;
        $userEmail = $this->userEmail;

        // Detect test mode from relay state embedded in userInfoResponse — avoids persisting
        // IS_TEST in core_config_data (which leaks if the IdP never calls back).
        $relayStateUrl = (string) ($attrs['relayState'] ?? '');
        $isTest = str_contains($relayStateUrl, OAuthConstants::TEST_RELAYSTATE);

        // Test configuration: Do not redirect to backend!
        if ($isTest) {
            $this->testAction->setAttrs($flattenedAttrs);
            $this->testAction->setUserEmail($userEmail);
            return $this->testAction->execute();
        }

        // Only execute admin logic and redirect when NOT in test mode:
        $this->oauthUtility->customlog(
            "=== CheckAttributeMappingAction: Processing authentication for: " . ($userEmail ?? '')
        );

        // Use explicit loginType for routing decision instead of just checking admin_user table
        $isAdminLoginIntent = ($this->loginType === OAuthConstants::LOGIN_TYPE_ADMIN);
        $logMsg = "Login type: " . ($this->loginType ?? 'not set');
        $logMsg .= ", Admin intent: " . ($isAdminLoginIntent ? 'YES' : 'NO');
        $this->oauthUtility->customlog($logMsg);

        // FEAT-04: Claims-based access control — evaluate per-provider rules before routing
        if ($this->accessControlRules !== null) {
            $denialMessage = $this->evaluateAccessControlRules($flattenedAttrs);
            if ($denialMessage !== null) {
                $this->oauthUtility->customlog(
                    "CheckAttributeMappingAction: Access denied for {$userEmail}: {$denialMessage}"
                );
                if ($isAdminLoginIntent) {
                    $this->messageManager->addErrorMessage((string) __($denialMessage));
                    $adminLoginUrl = $this->backendUrl->getUrl('admin');
                    return $this->resultRedirectFactory->create()->setUrl($adminLoginUrl);
                }
                $encodedError = urlencode(base64_encode($denialMessage));
                $loginUrl = $this->oauthUtility->getCustomerLoginUrl() . '?oidc_error=' . $encodedError;
                return $this->resultRedirectFactory->create()->setUrl($loginUrl);
            }
        }

        if ($isAdminLoginIntent) {
            // User initiated login from admin page - verify they have admin account
            if ($userEmail === null) {
                return $this->resultRedirectFactory->create()->setPath('customer/account/login');
            }
            // Single load reused for both the existence check and the user object
            // (previously isAdminUser() + getAdminUserByEmail() each queried admin_user separately)
            $adminUser = $this->adminUserCreator->getAdminUserByEmail($userEmail);
            $hasAdminAccount = $adminUser instanceof \Magento\User\Model\User && $adminUser->getId();
            $hasAccountMsg = "Admin login intent detected. Has admin account: ";
            $hasAccountMsg .= ($hasAdminAccount ? 'YES' : 'NO');
            $this->oauthUtility->customlog($hasAccountMsg);

            if ($hasAdminAccount) {
                // Provider binding check: reject if account is bound to a different IdP
                // ($adminUser->getId() is already known truthy here — $hasAdminAccount requires it.)
                $boundProvider = $this->userProviderResource->getBoundProviderId(
                    'admin',
                    (int) $adminUser->getId()
                );
                if ($boundProvider !== null && $boundProvider !== $this->providerId) {
                    $this->oauthUtility->customlog(
                        "Provider mismatch for admin " . $userEmail
                        . " (bound=" . $boundProvider . ", current=" . $this->providerId . ")"
                    );
                    $errorMessage = OAuthMessages::parse('PROVIDER_MISMATCH', ['email' => $userEmail]);
                    $this->messageManager->addErrorMessage((string) __($errorMessage));
                    return $this->resultRedirectFactory->create()->setUrl($this->backendUrl->getUrl('admin'));
                }
                // First OIDC login of a pre-existing admin account — claim the binding
                if ($boundProvider === null && $this->providerId > 0) {
                    $this->userProviderResource->saveMapping('admin', (int) $adminUser->getId(), $this->providerId);
                    $this->oauthUtility->customlog(
                        "Provider binding claimed for existing admin " . $userEmail
                        . " → provider " . $this->providerId
                    );
                }

                // Redirect admin users to dedicated admin login endpoint
                $this->oauthUtility->customlog("Routing admin user to admin callback endpoint");

                // Sync admin profile and role from OIDC claims before login (if enabled)
                $this->syncAdminProfileIfEnabled($adminUser, $flattenedAttrs, $attrs);
                $this->syncAdminRoleIfEnabled($adminUser, $flattenedAttrs, $attrs);

                $nonce = $this->securityHelper->createAdminLoginNonce($userEmail, $this->providerId);
                $this->cookieManager->setPublicCookie(
                    'oidc_admin_nonce',
                    $nonce,
                    $this->cookieMetadataFactory->createPublicCookieMetadata()
                        ->setDuration(120)
                        ->setPath('/' . $this->backendUrl->getAreaFrontName())
                        ->setHttpOnly(true)
                        ->setSecure(true)
                        ->setSameSite('Lax')
                );
                $adminCallbackUrl = $this->backendUrl->getUrl('m2oidc/actions/oidccallback');

                $this->oauthUtility->customlog("Admin callback URL: " . $adminCallbackUrl);

                return $this->resultRedirectFactory->create()->setUrl($adminCallbackUrl);
            }
            // User tried to login as admin but has no admin account
            // Check if auto-create admin is enabled (per-provider first, then global config)
            $autoCreateAdmin = $this->providerAutoCreateAdmin
                ?? $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_ADMIN);
            $autoCreateMsg = "Auto-create admin setting: ";
            $autoCreateMsg .= ($autoCreateAdmin ? 'ENABLED' : 'DISABLED');
            $this->oauthUtility->customlog($autoCreateMsg);
            if ($autoCreateAdmin) {
                $this->oauthUtility->customlog("=== Auto-creating admin user for: " . $userEmail . " ===");

                // Extract attributes using configured mappings
                $adminFirstName = $flattenedAttrs[$this->firstName] ?? null;
                $adminLastName = $flattenedAttrs[$this->lastName] ?? null;
                $adminUserName = $flattenedAttrs[$this->usernameAttribute] ?? $userEmail;

                $mappedLog = sprintf(
                    'Mapped attributes - userName: %s, firstName: %s, lastName: %s',
                    $adminUserName,
                    $adminFirstName ?? '',
                    $adminLastName ?? ''
                );
                $this->oauthUtility->customlog($mappedLog);

                // Get groups from OIDC response
                $groupAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_GROUP);
                $userGroups = [];
                if (!empty($groupAttribute)) {
                    $rawGroups = $flattenedAttrs[$groupAttribute] ?? $attrs[$groupAttribute] ?? null;
                    $userGroups = $this->oidcAuthenticationService->normalizeGroups($rawGroups);
                }
                $groupsJson = json_encode($userGroups) ?: '[]';
                $this->oauthUtility->customlog("User groups from OIDC: " . $groupsJson);

                // Create the admin user via UserProvisioningService (fires before/after events)
                $adminUser = $this->userProvisioningService->provisionAdmin(
                    $userEmail,
                    $adminUserName,
                    $adminFirstName,
                    $adminLastName,
                    $userGroups,
                    $this->providerId,
                    $flattenedAttrs
                );

                if ($adminUser instanceof \Magento\User\Model\User && $adminUser->getId()) {
                    $this->oauthUtility->customlog("Admin user created successfully. ID: " . $adminUser->getId());

                    // Sync profile and role for the newly created admin (if enabled)
                    $this->syncAdminProfileIfEnabled($adminUser, $flattenedAttrs, $attrs);
                    $this->syncAdminRoleIfEnabled($adminUser, $flattenedAttrs, $attrs);

                    // Redirect to admin callback for login
                    $nonce = $this->securityHelper->createAdminLoginNonce($userEmail, $this->providerId);
                    $this->cookieManager->setPublicCookie(
                        'oidc_admin_nonce',
                        $nonce,
                        $this->cookieMetadataFactory->createPublicCookieMetadata()
                            ->setDuration(120)
                            ->setPath('/' . $this->backendUrl->getAreaFrontName())
                            ->setHttpOnly(true)
                            ->setSecure(true)
                            ->setSameSite('Lax')
                    );
                    $adminCallbackUrl = $this->backendUrl->getUrl('m2oidc/actions/oidccallback');
                    $this->oauthUtility->customlog("Redirecting to admin callback: " . $adminCallbackUrl);

                    return $this->resultRedirectFactory->create()->setUrl($adminCallbackUrl);
                }
                $this->oauthUtility->customlog("ERROR: Failed to create admin user for: " . $userEmail);
                $groupList = implode(', ', array_map(fn(string $v): string => $v, $userGroups));
                $errorMessage = OAuthMessages::parse(
                    'ADMIN_ROLE_MAPPING_NO_MATCH',
                    ['groups' => $groupList !== '' ? $groupList : '(none)']
                );
                $adminLoginUrl = $this->backendUrl->getUrl('admin')
                    . '?oidc_error=' . urlencode(base64_encode($errorMessage));
                return $this->resultRedirectFactory->create()->setUrl($adminLoginUrl);
            }
            // Auto-create disabled - show error
            $errorMsg = "ERROR: Admin login attempted but no admin account exists for: ";
            $this->oauthUtility->customlog($errorMsg . $userEmail);
            $errorMessage = OAuthMessages::parse(
                'ADMIN_ACCOUNT_NOT_FOUND',
                ['email' => $userEmail]
            );
            $adminLoginUrl = $this->backendUrl->getUrl('admin')
                . '?oidc_error=' . urlencode(base64_encode($errorMessage));
            return $this->resultRedirectFactory->create()->setUrl($adminLoginUrl);
        }

        // Customer login flow (either explicit customer intent or default)
        $this->oauthUtility->customlog("Routing to customer login flow for: " . ($userEmail ?? ''));

        try {
            return $this->moOAuthCheckMapping($attrs, $flattenedAttrs, $userEmail ?? '');
        } catch (MissingAttributesException $e) {
            $this->oauthUtility->customlog("ERROR: Missing attributes - " . $e->getMessage());
            $receivedClaims = is_array($attrs) ? implode(', ', array_keys($attrs)) : '(none)';
            $msg = OAuthMessages::parse(
                'MISSING_ATTRIBUTES_DETAIL',
                [
                    'received_claims'    => $receivedClaims !== '' ? $receivedClaims : '(none)',
                    'missing_attributes' => $e->getMessage(),
                ]
            );
            $encodedError = urlencode(base64_encode($msg));
            $loginUrl = $this->oauthUtility->getCustomerLoginUrl() . '?oidc_error=' . $encodedError;
            return $this->resultRedirectFactory->create()->setUrl($loginUrl);
        }
    }

    /**
     * Process OAuth/OIDC attribute mapping for customer users
     *
     * Maps OAuth attributes to Magento customer fields based on
     * the configuration set in the admin panel.
     *
     * @param  mixed   $attrs          Raw OAuth response attributes
     * @param  mixed[] $flattenedAttrs Flattened attribute array
     * @param  string  $userEmail      User email from OAuth response
     * @throws MissingAttributesException
     */
    private function moOAuthCheckMapping(
        $attrs,
        array $flattenedAttrs,
        string $userEmail
    ): \Magento\Framework\Controller\ResultInterface {
        $this->oauthUtility->customlog("Starting attribute mapping for customer user");

        // Save debug data
        $this->saveDebugData($attrs);

        if (empty($attrs)) {
            $this->oauthUtility->customlog("ERROR: Empty attributes received from OAuth provider");
            throw new MissingAttributesException;
        }

        // Process required attributes
        $this->processUserName($flattenedAttrs);
        $this->processEmail($flattenedAttrs);
        $this->processFirstName($flattenedAttrs);
        $this->processLastName($flattenedAttrs);
        $this->processGroupName($flattenedAttrs);

        $this->oauthUtility->customlog("Attribute mapping completed, proceeding to user processing");

        // Dispatch oidc_after_attribute_mapping so third-party observers can mutate
        // the resolved attributes before user creation / profile sync.
        // mapped_attrs is a writable DataObject: observers may call setData() to alter values.
        $mappedAttrsObject = new \Magento\Framework\DataObject($flattenedAttrs);
        $this->_eventManager->dispatch('oidc_after_attribute_mapping', [
            'provider_id'  => $this->providerId,
            'mapped_attrs' => $mappedAttrsObject,
            'raw_claims'   => new \Magento\Framework\DataObject($attrs),
        ]);
        // Allow observers to have mutated the attrs
        $flattenedAttrs = $mappedAttrsObject->getData();

        return $this->processResult($attrs, $flattenedAttrs, $userEmail);
    }

    /**
     * Process the result - either show test screen or login/create user
     *
     * @param  mixed[] $attrs          Raw attributes
     * @param  mixed[] $flattenedattrs Flattened attributes
     * @param  string  $email          User email
     */
    private function processResult(
        array $attrs,
        array $flattenedattrs,
        string $email
    ): \Magento\Framework\Controller\ResultInterface {
        // Production mode - process user login/registration
        // Note: test mode is handled earlier in handle() before moOAuthCheckMapping() is called.
        $this->oauthUtility->customlog("Production mode - processing user login/registration");
        $provisioningContext = new OidcUserProvisioningContext(
            $attrs,
            $flattenedattrs,
            $email,
            $this->providerAutoCreateCustomer,
            $this->providerId,
            $this->headless
        );
        return $this->processUserAction->handle($provisioningContext);
    }

    /**
     * Process first name attribute
     *
     * Falls back to email prefix if not provided
     *
     * @param mixed[] $attrs Attribute array
     */
    private function processFirstName(array &$attrs): void
    {
        if (!isset($attrs[$this->firstName])) {
            $parts = explode("@", (string) $this->userEmail);
            $attrs[$this->firstName] = $parts[0];
            $this->oauthUtility->customlog("First name not provided, using email prefix: " . $parts[0]);
        }
    }

    /**
     * Process last name attribute
     *
     * Falls back to email domain if not provided
     *
     * @param mixed[] $attrs Attribute array
     */
    private function processLastName(array &$attrs): void
    {
        if (!isset($attrs[$this->lastName])) {
            $parts = explode("@", (string) $this->userEmail);
            $domain = $parts[1] ?? '';
            // Fall back to the local part (first name) if domain is empty (malformed email)
            $attrs[$this->lastName] = $domain !== '' ? $domain : ($parts[0] ?: 'User');
            $this->oauthUtility->customlog(
                "Last name not provided, using fallback: " . $attrs[$this->lastName]
            );
        }
    }

    /**
     * Process username attribute
     *
     * Falls back to email if not provided
     *
     * @param mixed[] $attrs Attribute array
     */
    private function processUserName(array &$attrs): void
    {
        if (!isset($attrs[$this->usernameAttribute])) {
            $attrs[$this->usernameAttribute] = $this->userEmail;
            $this->oauthUtility->customlog("Username not provided, using email: " . ($this->userEmail ?? ''));
        }
    }

    /**
     * Process email attribute
     *
     * Falls back to userEmail if not provided
     *
     * @param mixed[] $attrs Attribute array
     */
    private function processEmail(array &$attrs): void
    {
        if (isset($attrs[$this->emailAttribute])) {
            return;
        }

        // Check for case-mismatch: is the claim present under a different casing?
        $lowerConfigured = strtolower($this->emailAttribute);
        $caseMatchKey    = null;
        foreach (array_keys($attrs) as $key) {
            /** @psalm-suppress InvalidCast */
            if (strtolower((string) $key) === $lowerConfigured) {
                /** @psalm-suppress InvalidCast */
                $caseMatchKey = (string) $key;
                break;
            }
        }

        if ($caseMatchKey !== null) {
            // The claim exists but under a different case — log a specific actionable message
            $this->oauthUtility->customlog(
                OAuthMessages::parse(
                    'EMAIL_CLAIM_WRONG_CASE',
                    ['actual_claim' => $caseMatchKey, 'configured_claim' => $this->emailAttribute]
                )
            );
        } else {
            // The claim is genuinely absent — list received claims so the admin can identify the right name
            $receivedClaims = implode(', ', array_keys($attrs));
            $this->oauthUtility->customlog(
                OAuthMessages::parse(
                    'EMAIL_CLAIM_NOT_FOUND_WITH_CONTEXT',
                    ['received_claims' => $receivedClaims, 'configured_attribute' => $this->emailAttribute]
                )
            );
        }

        $attrs[$this->emailAttribute] = $this->userEmail;
        $this->oauthUtility->customlog(
            "Email attribute not mapped, using userEmail: " . ($this->userEmail ?? '')
        );
    }

    /**
     * Process group/role name attribute
     *
     * Defaults to empty array if not provided
     *
     * @param mixed[] $attrs Attribute array
     */
    private function processGroupName(array &$attrs): void
    {
        if (isset($attrs[$this->groupName])) {
            return;
        }

        // flattenAttributes() recurses into arrays, turning ["admins","admin"] into
        // groups.0 / groups.1 keys. Reconstruct the array from those indexed keys.
        $reconstructed = [];
        $prefix = $this->groupName . '.';
        foreach ($attrs as $key => $value) {
            if (str_starts_with((string) $key, $prefix)
                && is_numeric(substr((string) $key, strlen($prefix)))
            ) {
                $reconstructed[] = $value;
            }
        }

        if ($reconstructed !== []) {
            $attrs[$this->groupName] = $reconstructed;
            return;
        }

        // Zitadel nested format: groupName.roleName.orgId → extract roleName
        $this->oidcAuthenticationService->reconstructNestedGroupClaim($attrs, $this->groupName);
        if (isset($attrs[$this->groupName])) {
            $this->oauthUtility->customlog(
                "Group names reconstructed from Zitadel subkeys: "
                . (json_encode($attrs[$this->groupName]) ?: '[]')
            );
            return;
        }

        $attrs[$this->groupName] = [];
        $this->oauthUtility->customlog("Group name not provided, using empty array");
    }

    /**
     * Apply per-provider attribute mappings and metadata from the client details row.
     *
     * When a numeric provider_id is known, ReadAuthorizationResponse passes the provider's
     * DB row here (via OidcAttributeMappingContext::$clientDetails). Any non-empty attribute
     * column in the row takes precedence over the global store-config defaults set in the
     * constructor, enabling per-provider mapping.
     *
     * Fields read from the provider row:
     *   email_attribute, username_attribute, firstname_attribute,
     *   lastname_attribute, group_attribute
     *
     * @param  mixed[] $clientDetails Provider row data array
     */
    private function applyClientDetails(array $clientDetails): void
    {
        if (!empty($clientDetails['email_attribute'])) {
            $this->emailAttribute = (string) $clientDetails['email_attribute'];
        }
        if (!empty($clientDetails['username_attribute'])) {
            $this->usernameAttribute = (string) $clientDetails['username_attribute'];
        }
        if (!empty($clientDetails['firstname_attribute'])) {
            $this->firstName = (string) $clientDetails['firstname_attribute'];
        }
        if (!empty($clientDetails['lastname_attribute'])) {
            $this->lastName = (string) $clientDetails['lastname_attribute'];
        }
        if (!empty($clientDetails['group_attribute'])) {
            $this->groupName = (string) $clientDetails['group_attribute'];
        }

        // Persist provider ID in customer session so the logout observer
        // can load the correct end_session_endpoint for IdP-initiated logout.
        $providerId = (int) ($clientDetails['id'] ?? 0);
        if ($providerId > 0) {
            $this->customerSession->setData('oidc_provider_id', $providerId);
        }
        // Store provider ID for user-creation tracking (written to m2oidc_oauth_user_provider)
        $this->providerId = $providerId;

        // FEAT-04: load access control rules from the provider row
        $rulesJson = (string) ($clientDetails['access_control_rules'] ?? '');
        if ($rulesJson !== '') {
            $decoded = json_decode($rulesJson, true);
            $this->accessControlRules = is_array($decoded) ? $decoded : null;
        }

        // Per-provider auto-create flags (override global config when set in the provider row)
        if (isset($clientDetails['m2oidc_auto_create_admin'])
            && $clientDetails['m2oidc_auto_create_admin'] !== ''
        ) {
            $this->providerAutoCreateAdmin = (int) $clientDetails['m2oidc_auto_create_admin'];
        }
        if (isset($clientDetails['m2oidc_auto_create_customer'])
            && $clientDetails['m2oidc_auto_create_customer'] !== ''
        ) {
            $this->providerAutoCreateCustomer = (int) $clientDetails['m2oidc_auto_create_customer'];
        }
    }

    /**
     * Evaluate per-provider claims-based access control rules (FEAT-04).
     *
     * Each rule is an associative array with:
     *   - claim        (string) : flattened OIDC attribute key to test
     *   - operator     (string) : eq | neq | contains | not_contains | exists | not_exists
     *   - value        (string) : expected value (ignored for exists/not_exists)
     *   - deny_message (string) : user-visible message shown when this rule fails
     *
     * Rules are AND-combined; the first failing rule short-circuits and returns
     * its deny_message. Returns null when all rules pass (access granted).
     *
     * Array-valued claims (e.g. groups) are joined with commas for string comparison.
     *
     * @param  mixed[] $claims Flattened OIDC claims from the IdP response
     * @return string|null  Denial message if access is denied, null if granted
     */
    private function evaluateAccessControlRules(array $claims): ?string
    {
        if ($this->accessControlRules === null) {
            return null;
        }
        foreach ($this->accessControlRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $claim    = (string) ($rule['claim']        ?? '');
            $operator = (string) ($rule['operator']     ?? 'eq');
            $expected = (string) ($rule['value']        ?? '');
            $message  = (string) ($rule['deny_message'] ?? '');

            if ($claim === '') {
                continue;
            }

            $actual = $claims[$claim] ?? null;

            // Handle array-valued claims (e.g. groups: ["admin", "users"]).
            // For equality/containment operators, check if *any* element matches
            // rather than comparing against a comma-joined string, which would
            // require the rule value to contain commas to match multi-valued claims.
            $passes = match ($operator) {
                'eq'  => is_array($actual)
                    ? in_array($expected, $actual, true)
                    : ((string) ($actual ?? '')) === $expected,
                'neq' => is_array($actual)
                    ? !in_array($expected, $actual, true)
                    : ((string) ($actual ?? '')) !== $expected,
                'contains' => is_array($actual)
                    ? (array_filter($actual, fn($v): bool => str_contains((string) $v, $expected)) !== [])
                    : str_contains((string) ($actual ?? ''), $expected),
                'not_contains' => is_array($actual)
                    ? (array_filter($actual, fn($v): bool => str_contains((string) $v, $expected)) === [])
                    : !str_contains((string) ($actual ?? ''), $expected),
                'exists'     => $actual !== null,
                'not_exists' => $actual === null,
                default      => false, // unknown operator: fail closed (deny on misconfiguration)
            };

            if (!$passes) {
                return $message !== '' ? $message : (string) __('Access denied by provider policy.');
            }
        }

        return null; // all rules passed — access granted
    }

    /**
     * Save minimal OAuth debug summary for the current request.
     *
     * Stores only a non-PII summary in the customer session (just timestamps
     * and boolean presence flags) and logs the full filtered payload to the
     * M2Oidc.log file. Full attribute payloads are never persisted in session
     * storage to avoid PII leakage through session dumps or serialised session stores.
     *
     * @param mixed $attrs
     *
     * @return void
     */
    protected function saveDebugData($attrs)
    {
        if (!$this->oauthUtility->isLogEnable()) {
            return;
        }

        try {
            // Log the full (sensitive-scrubbed) payload to file — not to session
            $sensitiveKeys = ['access_token', 'refresh_token', 'id_token', 'client_secret', 'password', 'token'];
            $filteredAttrs = is_array($attrs) ? $attrs : [];
            foreach ($sensitiveKeys as $key) {
                if (isset($filteredAttrs[$key])) {
                    $filteredAttrs[$key] = '********';
                }
            }
            $this->oauthUtility->customlogContext('debug_attributes', $filteredAttrs);

            // Store only a minimal summary in session (no PII, no full attribute payload)
            $summary = [
                'timestamp'     => date('Y-m-d H:i:s'),
                'email_present' => isset($filteredAttrs[$this->emailAttribute]),
                'user_present'  => isset($filteredAttrs[$this->usernameAttribute]),
                'claim_count'   => count($filteredAttrs),
            ];
            $this->customerSession->setData('m2oidc_debug_response', json_encode($summary));

            $this->oauthUtility->customlog("Debug summary saved to session, full payload logged to file");
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("Could not save debug data: " . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────
    //  Admin sync helpers
    // ──────────────────────────────────────────────

    /**
     * Sync admin profile from OIDC claims when sync_admin_profile_on_sso is enabled.
     *
     * Called in the admin routing path before the nonce cookie is set so that
     * profile data is up-to-date by the time the admin logs in.
     *
     * @param \Magento\User\Model\User $adminUser Already-loaded admin user
     * @param mixed[]                  $flat      Flattened OIDC attributes
     * @param mixed[]                  $raw       Raw (nested) OIDC attributes
     */
    private function syncAdminProfileIfEnabled(\Magento\User\Model\User $adminUser, array $flat, array $raw): void
    {
        $flag = $this->oauthUtility->getStoreConfig(OAuthConstants::SYNC_ADMIN_PROFILE_ON_SSO);
        if ((string) $flag !== '1') {
            return;
        }
        try {
            $this->adminProfileSyncService->syncProfile(
                $adminUser,
                $flat,
                $raw,
                $this->firstName,
                $this->lastName,
                $this->usernameAttribute,
                $this->emailAttribute,
                $this->providerId
            );
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'CheckAttributeMappingAction: admin profile sync failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Re-evaluate and update admin role from OIDC group claims when sync_admin_role_on_sso is enabled.
     *
     * @param \Magento\User\Model\User $adminUser Already-loaded admin user
     * @param mixed[]                  $flat      Flattened OIDC attributes
     * @param mixed[]                  $raw       Raw (nested) OIDC attributes
     */
    private function syncAdminRoleIfEnabled(\Magento\User\Model\User $adminUser, array $flat, array $raw): void
    {
        $flag = $this->oauthUtility->getStoreConfig(OAuthConstants::SYNC_ADMIN_ROLE_ON_SSO);
        if ((string) $flag !== '1') {
            return;
        }
        $roleMappings = $this->adminUserCreator->getAdminRoleMappingsForProvider($this->providerId);
        $defaultRole  = (string) ($this->oauthUtility->getStoreConfig(OAuthConstants::MAP_DEFAULT_ROLE) ?? '');
        $groupAttr    = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_GROUP) ?: 'groups';
        try {
            $this->adminProfileSyncService->syncRole(
                $adminUser,
                $flat,
                $raw,
                $groupAttr,
                $roleMappings,
                $defaultRole
            );
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'CheckAttributeMappingAction: admin role sync failed: ' . $e->getMessage()
            );
        }
    }
}
