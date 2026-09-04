<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\ResourceModel;

use M2Oidc\OAuth\Model\M2oidcOauthClientAppsFactory;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps as AppResource;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\CollectionFactory as ClientCollectionFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Psr\Log\LoggerInterface;

/**
 * Repository for OIDC provider (client app) records.
 *
 * Handles all read and write access to the m2oidc_oauth_client_apps table,
 * including collection retrieval, provider lookup by name or ID, test-status
 * persistence, and received-claims storage.
 *
 * This class is intentionally decoupled from Helper\Data to avoid circular
 * dependencies; inject it directly wherever provider data is needed.
 */
class OidcProviderRepository
{
    /**
     * @param M2oidcOauthClientAppsFactory $clientAppsFactory       Factory for individual provider models
     * @param ClientCollectionFactory      $clientCollectionFactory Factory for provider collections
     * @param AppResource                  $appResource             Resource model for save/load operations
     * @param EncryptorInterface           $encryptor               Magento encryptor for client_secret decryption
     * @param LoggerInterface              $logger                  PSR-3 logger
     */
    public function __construct(
        private readonly M2oidcOauthClientAppsFactory $clientAppsFactory,
        private readonly ClientCollectionFactory $clientCollectionFactory,
        private readonly AppResource $appResource,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Return a fresh collection of all OAuthClientApp records.
     */
    public function getOAuthClientApps(): \M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\Collection
    {
        return $this->clientCollectionFactory->create();
    }

    /**
     * Get client details by app name using collection filtering.
     *
     * Returns the provider data array with client_secret decrypted if it was
     * stored in Magento's encrypted format (^\d+:\d+:).
     *
     * @param  string $appName The app_name column value to look up
     * @return array<string, mixed>|null Client details array or null if not found
     */
    public function getClientDetailsByAppName(string $appName): ?array
    {
        $collection = $this->getOAuthClientApps();
        $collection->addFieldToFilter('app_name', $appName);
        $data = $collection->getSize() > 0 ? $collection->getFirstItem()->getData() : null;

        if ($data !== null && (int) ($data['public_client'] ?? 0) !== 1
            && isset($data['client_secret']) && !empty($data['client_secret'])
            && preg_match('/^\d+:\d+:/', (string) $data['client_secret'])) {
            $data['client_secret'] = $this->decryptSecretWithLogging((string) $data['client_secret'], $appName);
        }
        if ($data !== null) {
            return $this->decryptWebhookUrl($data, $appName);
        }

        return $data;
    }

    /**
     * Get client details by numeric provider ID (row `id`).
     *
     * Returns the provider data array with client_secret decrypted if it was
     * stored in Magento's encrypted format (^\d+:\d+:).
     *
     * @param  int $providerId Row `id` of the provider record (must be > 0)
     * @return array<string, mixed>|null Client details array or null if not found
     */
    public function getClientDetailsById(int $providerId): ?array
    {
        if ($providerId <= 0) {
            return null;
        }

        $collection = $this->getOAuthClientApps();
        $collection->addFieldToFilter('id', ['eq' => $providerId]);
        $data = $collection->getSize() > 0 ? $collection->getFirstItem()->getData() : null;

        if ($data !== null && (int) ($data['public_client'] ?? 0) !== 1
            && isset($data['client_secret']) && !empty($data['client_secret'])
            && preg_match('/^\d+:\d+:/', (string) $data['client_secret'])) {
            $data['client_secret'] = $this->decryptSecretWithLogging(
                (string) $data['client_secret'],
                'id=' . $providerId
            );
        }
        if ($data !== null) {
            return $this->decryptWebhookUrl($data, 'id=' . $providerId);
        }

        return $data;
    }

    /**
     * Persist the last test run result for a provider – lookup by app_name.
     *
     * @param string $appName Provider app_name column value
     * @param string $status  One of: 'success', 'failed', 'unsuccessful'
     */
    public function saveTestStatus(string $appName, string $status): void
    {
        $allowed = ['success', 'failed', 'unsuccessful'];
        if (!in_array($status, $allowed, true)) {
            $this->logger->warning('saveTestStatus: invalid status value "' . $status . '"');
            return;
        }

        $collection = $this->clientCollectionFactory->create();
        $collection->addFieldToFilter('app_name', $appName);
        $model = $collection->getFirstItem();

        if (!$model->getId()) {
            $this->logger->warning('saveTestStatus: no provider found for app_name "' . $appName . '"');
            return;
        }

        try {
            $model->setData('last_test_status', $status);
            $model->setData('last_test_at', date('Y-m-d H:i:s'));
            /** @psalm-suppress ArgumentTypeCoercion */ // @phpstan-ignore-next-line
            $this->appResource->save($model);
        } catch (\Exception $e) {
            $this->logger->error('saveTestStatus failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Persist the last test run result for a provider – lookup by primary key.
     *
     * @param int    $providerId Row `id` of the provider record (must be > 0)
     * @param string $status     One of: 'success', 'failed', 'unsuccessful'
     */
    public function saveTestStatusById(int $providerId, string $status): void
    {
        $allowed = ['success', 'failed', 'unsuccessful'];
        if ($providerId <= 0 || !in_array($status, $allowed, true)) {
            $this->logger->warning(
                'saveTestStatusById: invalid arguments – providerId=' . $providerId . ', status=' . $status
            );
            return;
        }

        $model = $this->clientAppsFactory->create();
        $this->appResource->load($model, $providerId);

        if (!$model->getId()) {
            $this->logger->warning('saveTestStatusById: no provider found for id=' . $providerId);
            return;
        }

        try {
            $model->setData('last_test_status', $status);
            $model->setData('last_test_at', date('Y-m-d H:i:s'));
            $this->appResource->save($model);
        } catch (\Exception $e) {
            $this->logger->error('saveTestStatusById failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Save received OIDC claims to the provider record.
     *
     * The claim keys are JSON-encoded and persisted in the received_oidc_claims column.
     *
     * @param int      $providerId Provider row ID (must be > 0)
     * @param string[] $claimKeys  Array of claim key names received from the IdP
     */
    public function saveReceivedOidcClaims(int $providerId, array $claimKeys): void
    {
        if ($providerId <= 0) {
            return;
        }

        $model = $this->clientAppsFactory->create();
        $this->appResource->load($model, $providerId);

        if (!$model->getId()) {
            $this->logger->warning(
                'saveReceivedOidcClaims: no provider found for id=' . $providerId
            );
            return;
        }

        try {
            $model->setData('received_oidc_claims', json_encode($claimKeys));
            $this->appResource->save($model);
        } catch (\Exception $e) {
            $this->logger->error(
                'saveReceivedOidcClaims failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Return all active provider records for a given login type, ordered by sort_order.
     *
     * Client secrets are decrypted before being returned. An empty/NULL `login_type`
     * is treated as a wildcard — such a row matches any requested login type, not just
     * 'both' — mirroring legacy rows backfilled before the multi-provider `login_type`
     * column existed.
     *
     * @param  string $loginType 'customer', 'admin', or 'both'
     * @return array<int, array<string, mixed>> Array of provider data arrays (may be empty)
     */
    public function getAllActiveProviders(string $loginType = 'customer'): array
    {
        $collection = $this->getOAuthClientApps();
        $collection->addFieldToFilter('is_active', ['eq' => 1]);
        $collection->setOrder('sort_order', 'ASC');

        $results = [];
        foreach ($collection as $item) {
            $data = $item->getData();
            $providerLoginType = (string) ($data['login_type'] ?? '');
            if (!in_array($providerLoginType, [$loginType, 'both', ''], true)) {
                continue;
            }

            $context = 'id=' . (string) ($data['id'] ?? '');
            if ((int) ($data['public_client'] ?? 0) !== 1
                && isset($data['client_secret']) && !empty($data['client_secret'])
                && preg_match('/^\d+:\d+:/', (string) $data['client_secret'])) {
                $data['client_secret'] = $this->decryptSecretWithLogging((string) $data['client_secret'], $context);
            }
            $data = $this->decryptWebhookUrl($data, $context);
            $results[] = $data;
        }

        return $results;
    }

    /**
     * Decrypt a client_secret ciphertext, logging a WARNING when a non-empty
     * ciphertext decrypts to an empty string (silent decryption failure — e.g.
     * corrupted ciphertext or a post-key-rotation mismatch).
     *
     * @param string $ciphertext Encrypted secret matching the `^\d+:\d+:` envelope
     * @param string $context    Human-readable identifier for the affected provider (for the log line)
     */
    private function decryptSecretWithLogging(string $ciphertext, string $context): string
    {
        $decrypted = $this->encryptor->decrypt($ciphertext);
        if ($decrypted === '') {
            $this->logger->warning(
                'OidcProviderRepository: client_secret decrypted to an empty string for provider (' . $context . ')'
            );
        }

        return $decrypted;
    }

    /**
     * Decrypt health_alert_webhook_url in-place when it holds a Magento-encrypted value.
     *
     * Same treatment as client_secret: a Slack/PagerDuty-style webhook URL commonly embeds
     * a bearer-token-equivalent secret in its path/query, so it is encrypted at rest.
     *
     * @param  mixed[] $data    Provider data
     * @param  string  $context Human-readable identifier for the affected provider (for the log line)
     * @return mixed[]
     */
    private function decryptWebhookUrl(array $data, string $context): array
    {
        if (isset($data['health_alert_webhook_url']) && !empty($data['health_alert_webhook_url'])
            && preg_match('/^\d+:\d+:/', (string) $data['health_alert_webhook_url'])) {
            $decrypted = $this->encryptor->decrypt((string) $data['health_alert_webhook_url']);
            if ($decrypted === '') {
                $this->logger->warning(
                    'OidcProviderRepository: health_alert_webhook_url decrypted to an empty string '
                    . 'for provider (' . $context . ')'
                );
            }
            $data['health_alert_webhook_url'] = $decrypted;
        }

        return $data;
    }
}
