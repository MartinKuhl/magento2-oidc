<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Block;

use M2Oidc\OAuth\Helper\OAuthConstants;
use Magento\Customer\Model\Session;
use Magento\Framework\Escaper;

/**
 * Utility block for OAuth/OIDCfinal  admin templates.
 *
 * Provides helper methods used by admin and frontend templates to access
 * configuration values, URLs and attribute mappings.
 */
class OAuth extends \Magento\Framework\View\Element\Template
{
    /** @var \M2Oidc\OAuth\Helper\OAuthUtility */
    private readonly \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility;

    /** @var \Magento\Customer\Model\Session */
    protected \Magento\Customer\Model\Session $customerSession;

    /** @var \Magento\Framework\Escaper */
    protected \Magento\Framework\Escaper $escaper;

    /** @var \Magento\Framework\Data\Form\FormKey */
    protected \Magento\Framework\Data\Form\FormKey $_formKey;

    /** @var \Magento\Framework\Component\ComponentRegistrarInterface */
    private readonly \Magento\Framework\Component\ComponentRegistrarInterface $componentRegistrar;

    /** @var \Magento\Framework\Filesystem\Driver\File */
    private readonly \Magento\Framework\Filesystem\Driver\File $fileDriver;

    /**
     * Initialize OAuth block.
     *
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility
     * @param Session $customerSession
     * @param Escaper $escaper
     * @param \Magento\Framework\Data\Form\FormKey $formKey
     * @param \Magento\Framework\Component\ComponentRegistrarInterface $componentRegistrar
     * @param \Magento\Framework\Filesystem\Driver\File $fileDriver
     * @param mixed[] $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility,
        Session $customerSession,
        Escaper $escaper,
        \Magento\Framework\Data\Form\FormKey $formKey,
        \Magento\Framework\Component\ComponentRegistrarInterface $componentRegistrar,
        \Magento\Framework\Filesystem\Driver\File $fileDriver,
        array $data = []
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->customerSession = $customerSession;
        $this->escaper = $escaper;
        $this->_formKey = $formKey;
        $this->componentRegistrar = $componentRegistrar;
        $this->fileDriver = $fileDriver;
        parent::__construct($context, $data);
    }

    /**
     * Get customer session
     */
    public function getCustomerSession(): \Magento\Customer\Model\Session
    {
        return $this->customerSession;
    }

    /**
     * Escape HTML to prevent XSS
     *
     * @param string|int|float|\Stringable|array<string|int|float|\Stringable> $data Data to escape
     * @param array<mixed>|null $allowedTags Allowed HTML tags
     * @return ($data is array ? string[] : string)
     */
    #[\Override]
    public function escapeHtml($data, $allowedTags = null)
    {
        return $this->escaper->escapeHtml($data, $allowedTags);
    }

    /**
     * Escape string for HTML attribute
     *
     * @param  string|int|float|\Stringable $string
     * @param  bool                         $escapeSingleQuote
     * @return string
     */
    #[\Override]
    public function escapeHtmlAttr($string, $escapeSingleQuote = true)
    {
        return $this->escaper->escapeHtmlAttr($string, $escapeSingleQuote);
    }

    /**
     * Escape URL
     *
     * @param  string $string
     * @return string
     */
    #[\Override]
    public function escapeUrl($string)
    {
        return $this->escaper->escapeUrl($string);
    }

    /**
     * Escape JavaScript string
     *
     * @param  string|int|float|\Stringable $string
     * @return string
     */
    #[\Override]
    public function escapeJs($string)
    {
        return $this->escaper->escapeJs($string);
    }

    /**
     * Get CSRF form key
     *
     * @return string
     */
    public function getFormKey()
    {
        return $this->_formKey->getFormKey();
    }

    /**
     * Get OIDC claims for dropdown selection.
     *
     * Loads claims per-provider from the m2oidc_oauth_client_apps table.
     * Falls back to the static OIDC_STANDARD_CLAIMS list if no test was run yet.
     *
     * @return string[]
     */
    public function getOidcStandardClaims(): array
    {
        // Technical claims to exclude from dropdown
        $excludedClaims = [
            'sub', 'iat', 'rat', 'at_hash', 'updated_at',
            'exp', 'nbf', 'aud', 'iss', 'azp',
            'nonce', 'auth_time', 'acr', 'amr', 'sid'
        ];

        $claims = [];

        // Try to load per-provider claims
        $providerId = $this->getActiveProviderId();
        if ($providerId > 0) {
            $providerData = $this->oauthUtility->getClientDetailsById($providerId);
            if ($providerData !== null && !empty($providerData['received_oidc_claims'])) {
                $decoded = json_decode((string) $providerData['received_oidc_claims'], true);
                if (is_array($decoded) && $decoded !== []) {
                    $claims = $decoded;
                }
            }
        }

        // Fallback: static standard claims if no provider-specific claims available
        if ($claims === []) {
            $claims = OAuthConstants::OIDC_STANDARD_CLAIMS;
        }

        // Filter out technical claims
        return array_values(
            array_filter(
                $claims,
                fn($claim): bool => !in_array($claim, $excludedClaims, true)
            )
        );
    }

    /**
     * This function fetches the OAuth App name saved by the admin
     */
    public function getAppName(): mixed
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
    }

    /**
     * Get the admin JS URL to be appended to the admin dashboard pages for plugin functionality
     */
    public function getAdminJSURL(): string
    {
        return $this->oauthUtility->getAdminJSUrl('adminSettings.js');
    }

    /**
     * Get/Create Base URL of the site
     */
    #[\Override]
    public function getBaseUrl()
    {
        return $this->oauthUtility->getBaseUrl();
    }

    /**
     * Get the active provider_id from the current request (URL or POST).
     *
     * Returns 0 when no provider context is active (global/legacy mode).
     */
    public function getActiveProviderId(): int
    {
        return (int) $this->getRequest()->getParam('provider_id', 0);
    }

    /**
     * Load the provider data array for the active provider_id.
     *
     * Returns null when no provider_id is set or provider is not found.
     *
     * @return array<string,mixed>|null
     */
    public function getProviderData(): ?array
    {
        $id = $this->getActiveProviderId();
        if ($id <= 0) {
            return null;
        }
        return $this->oauthUtility->getClientDetailsById($id);
    }

    /**
     * Load provider data by a specific provider ID (used by provider_edit.phtml in edit mode).
     *
     * @param int $id Provider database ID
     * @return array<string,mixed>|null
     */
    public function getClientDetailsById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return $this->oauthUtility->getClientDetailsById($id);
    }

    /**
     * Check if non-OIDC admin login is disabled.
     *
     * Aggregator: returns true if ANY active admin provider (with visible button)
     * has m2oidc_disable_non_oidc_admin_login = 1.
     */
    public function isNonOidcAdminLoginDisabled(): bool
    {
        foreach ($this->oauthUtility->getAllActiveProviders('admin') as $provider) {
            if ((int) ($provider['show_admin_link'] ?? 0) !== 1) {
                continue;
            }
            if ((int) ($provider['m2oidc_disable_non_oidc_admin_login'] ?? 0) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create/Get the SP initiated URL for the site (frontend/customer login).
     *
     * @param string|null $relayState
     * @param string|null $app_name
     */
    public function getSPInitiatedUrl(?string $relayState = null, ?string $app_name = null): string
    {
        return $this->oauthUtility->getSPInitiatedUrl($relayState, $app_name);
    }

    /**
     * Create/Get the Admin SP initiated URL (admin backend OIDC login).
     *
     * Uses the admin controller which sets loginType=admin.
     *
     * @param string|null $relayState
     * @param string|null $app_name
     */
    public function getAdminSPInitiatedUrl(?string $relayState = null, ?string $app_name = null): string
    {
        return $this->oauthUtility->getAdminSPInitiatedUrl($relayState, $app_name);
    }

    /**
     * Return all active providers for the given login type, ordered by sort_order.
     *
     * Powers the multi-provider SSO button loop in customerssobutton.phtml.
     * Each element is a plain data array (same shape as getClientDetailsByAppName()).
     *
     * @param  string $loginType 'customer' | 'admin' | 'both'
     * @return array<int, array<string, mixed>> Array of provider data arrays, may be empty
     */
    public function getActiveProviders(string $loginType = 'customer'): array
    {
        return $this->oauthUtility->getAllActiveProviders($loginType);
    }

    /**
     * Build the SP-initiated authorization URL for a specific provider row.
     *
     * @param  int         $providerId
     * @param  string|null $relayState
     * @param  string      $loginType  'customer' or 'admin'
     * @param  string|null $appName    Optional backup provider identifier
     */
    public function getSPInitiatedUrlForProvider(
        int $providerId,
        ?string $relayState = null,
        string $loginType = 'customer',
        ?string $appName = null
    ): string {
        return $this->oauthUtility->getSPInitiatedUrlForProvider(
            $providerId,
            $relayState,
            $loginType,
            $appName
        );
    }

    /**
     * Fetch/Create the text of the button to be shown for SP initiated login from the admin / customer login pages.
     *
     * @return string
     */
    public function getSSOButtonText()
    {
        $buttonText = $this->oauthUtility->getStoreConfig(OAuthConstants::BUTTON_TEXT);
        $idpName = $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        return $this->oauthUtility->isBlank($buttonText) ? 'Login with ' . $idpName : $buttonText;
    }

    /**
     * Check if log printing is on or off
     */
    public function isDebugLogEnable(): mixed
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
    }

    /**
     * Get OIDC error message from URL parameter
     */
    public function getOidcErrorMessage(): ?string
    {
        $encodedMessage = $this->getRequest()->getParam('oidc_error');
        if ($encodedMessage) {
            $decoded = $this->oauthUtility->decodeBase64($encodedMessage);
            return $decoded === '' ? null : $decoded;
        }
        return null;
    }

    /**
     * Check if there is an OIDC error
     */
    public function hasOidcError(): bool
    {
        return $this->getOidcErrorMessage() !== null;
    }

    /**
     * Check if non-OIDC customer login is disabled.
     *
     * Aggregator: returns true if ANY active customer provider (with visible button)
     * has m2oidc_disable_non_oidc_customer_login = 1.
     */
    public function isNonOidcCustomerLoginDisabled(): bool
    {
        foreach ($this->oauthUtility->getAllActiveProviders('customer') as $provider) {
            if ((int) ($provider['show_customer_link'] ?? 0) !== 1) {
                continue;
            }
            if ((int) ($provider['m2oidc_disable_non_oidc_customer_login'] ?? 0) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate a per-provider SSO button color, falling back to a sane default.
     *
     * Extracted from the duplicated "validate #rrggbb, else fallback" logic
     * previously inlined in adminssobutton.phtml and customerssobutton.phtml.
     *
     * @param string|null $raw      Raw `button_color` value from the provider row
     * @param string      $fallback Fallback hex color used when $raw is missing/invalid
     */
    public function resolveButtonColor(?string $raw, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $raw) ? (string) $raw : $fallback;
    }

    /**
     * Resolve the SSO button label.
     *
     * Extracted from the duplicated "button_label, else 'Login with %1'" logic
     * previously inlined in adminssobutton.phtml and customerssobutton.phtml.
     *
     * @param string|null $rawLabel    Raw `button_label` value from the provider row
     * @param string      $displayName Provider display name used in the fallback phrase
     */
    public function resolveButtonLabel(?string $rawLabel, string $displayName): string
    {
        return in_array($rawLabel, [null, '', '0'], true) ? (string) __('Login with %1', $displayName) : $rawLabel;
    }

    /**
     * Read the module's version straight from its composer.json.
     *
     * Returns an empty string if composer.json is missing/unreadable or has
     * no "version" field, so callers can decide how to handle that case.
     */
    public function getModuleVersion(): string
    {
        $modulePath = $this->componentRegistrar->getPath(
            \Magento\Framework\Component\ComponentRegistrar::MODULE,
            'M2Oidc_OAuth'
        );

        if ($modulePath === null) {
            return '';
        }

        $composerJsonPath = $modulePath . '/composer.json';

        if (!$this->fileDriver->isExists($composerJsonPath)) {
            return '';
        }

        try {
            $composerData = json_decode(
                $this->fileDriver->fileGetContents($composerJsonPath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\Magento\Framework\Exception\FileSystemException | \JsonException $e) {
            return '';
        }

        return (string) ($composerData['version'] ?? '');
    }
}
