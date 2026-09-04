<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Global (non-provider-scoped) configuration for the Passkey/WebAuthn login feature.
 *
 * Passkeys are not tied to an external IdP, so — unlike OidcConfigReader, which
 * resolves per-provider rows — this reads a small set of core_config_data paths
 * under the m2oidc_passkey/general group, edited via Controller/Adminhtml/Passkeysettings.
 */
class PasskeyConfig
{
    private const XML_PATH_ENABLED_ADMIN = 'm2oidc_passkey/general/enabled_admin';

    private const XML_PATH_ENABLED_CUSTOMER = 'm2oidc_passkey/general/enabled_customer';

    private const XML_PATH_RP_NAME = 'm2oidc_passkey/general/rp_name';

    private const XML_PATH_RP_ID = 'm2oidc_passkey/general/rp_id';

    private const XML_PATH_AUTO_LOGOUT_ON_DELETE = 'm2oidc_passkey/general/auto_logout_on_delete';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Whether passkey login is enabled for admin users.
     */
    public function isEnabledForAdmin(): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED_ADMIN, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Whether passkey login is enabled for customers.
     */
    public function isEnabledForCustomer(): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED_CUSTOMER, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Whether an active OIDC/passkey session is force-logged-out on passkey delete.
     */
    public function isAutoLogoutOnDeleteEnabled(): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(
            self::XML_PATH_AUTO_LOGOUT_ON_DELETE,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Relying Party display name shown by the OS/browser passkey prompt.
     */
    public function getRpName(): string
    {
        $configured = (string) $this->scopeConfig->getValue(self::XML_PATH_RP_NAME, ScopeInterface::SCOPE_STORE);
        if ($configured !== '') {
            return $configured;
        }
        /** @var \Magento\Store\Model\Store $store */
        $store = $this->storeManager->getStore();
        return (string) $store->getFrontendName();
    }

    /**
     * Relying Party ID (registrable domain, no scheme/port).
     *
     * A single RP ID is tied to a single domain — passkeys registered under one
     * domain will not authenticate on a different domain. Multi-store installs
     * that use different domains per store view must either share one primary
     * domain for passkey login or accept that passkeys don't carry across them.
     */
    public function getRpId(): string
    {
        $configured = (string) $this->scopeConfig->getValue(self::XML_PATH_RP_ID, ScopeInterface::SCOPE_STORE);
        if ($configured !== '') {
            return $configured;
        }
        /** @var \Magento\Store\Model\Store $store */
        $store = $this->storeManager->getStore();
        $baseUrl = (string) $store->getBaseUrl();
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $host = parse_url($baseUrl, PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    /**
     * Full origin (scheme://host[:port]) the WebAuthn ceremony must have been performed on.
     */
    public function getOrigin(): string
    {
        /** @var \Magento\Store\Model\Store $store */
        $store = $this->storeManager->getStore();
        $baseUrl = (string) $store->getBaseUrl();
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return rtrim($baseUrl, '/');
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }
}
