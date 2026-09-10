<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Cron;

use Magento\Framework\Encryption\EncryptorInterface;
use M2Oidc\OAuth\Helper\Curl;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Logger\OidcLogger;
use M2Oidc\OAuth\Model\Health\ProviderReachabilityChecker;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\CollectionFactory as ProviderCollectionFactory;
use M2Oidc\OAuth\Model\Validation\SsrfUrlValidator;

/**
 * Fires a webhook alert (Slack/PagerDuty/any HTTP endpoint) after N consecutive
 * IdP reachability-check failures for a given provider, and an optional one-time
 * recovery notification when the provider becomes reachable again.
 *
 * Runs every 5 minutes via etc/crontab.xml (m2oidc_health_check_alert) — far
 * tighter than RefreshOidcDiscovery's 6-hour cadence, since this cron exists
 * specifically for timely outage detection. Only providers that are active and
 * have both health_alert_failure_threshold > 0 and a configured
 * health_alert_webhook_url are considered; everyone else is excluded at the
 * database query level, so upgrading to this feature is a no-op until an admin
 * opts a provider in.
 *
 * Alerts fire exactly once per outage: health_alert_last_status tracks whether an
 * alert has already been sent for the current failure streak, independent of the
 * raw consecutive-failure counter, so editing the threshold mid-outage cannot
 * cause a re-fire storm.
 */
class HealthCheckAlert
{
    /** @var ProviderCollectionFactory */
    private readonly ProviderCollectionFactory $collectionFactory;

    /** @var Curl */
    private readonly Curl $curl;

    /** @var ProviderReachabilityChecker */
    private readonly ProviderReachabilityChecker $reachabilityChecker;

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var OidcLogger */
    private readonly OidcLogger $oidcLogger;

    /** @var SsrfUrlValidator */
    private readonly SsrfUrlValidator $ssrfUrlValidator;

    /** @var EncryptorInterface */
    private readonly EncryptorInterface $encryptor;

    /**
     * @param ProviderCollectionFactory   $collectionFactory
     * @param Curl                        $curl
     * @param ProviderReachabilityChecker $reachabilityChecker
     * @param OAuthUtility                $oauthUtility
     * @param OidcLogger                  $oidcLogger
     * @param SsrfUrlValidator            $ssrfUrlValidator
     * @param EncryptorInterface          $encryptor
     */
    public function __construct(
        ProviderCollectionFactory $collectionFactory,
        Curl $curl,
        ProviderReachabilityChecker $reachabilityChecker,
        OAuthUtility $oauthUtility,
        OidcLogger $oidcLogger,
        SsrfUrlValidator $ssrfUrlValidator,
        EncryptorInterface $encryptor
    ) {
        $this->collectionFactory  = $collectionFactory;
        $this->curl                = $curl;
        $this->reachabilityChecker = $reachabilityChecker;
        $this->oauthUtility        = $oauthUtility;
        $this->oidcLogger          = $oidcLogger;
        $this->ssrfUrlValidator    = $ssrfUrlValidator;
        $this->encryptor           = $encryptor;
    }

    /**
     * Probe every alerting-enabled active provider and fire webhooks as needed.
     */
    public function execute(): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('is_active', ['eq' => 1]);
        $collection->addFieldToFilter('health_alert_failure_threshold', ['gt' => 0]);
        $collection->addFieldToFilter('health_alert_webhook_url', ['notnull' => true]);
        $collection->addFieldToFilter('health_alert_webhook_url', ['neq' => '']);

        $providers = iterator_to_array($collection);
        if ($providers === []) {
            return;
        }

        $checked = 0;
        $alerted = 0;

        foreach ($providers as $provider) {
            $data       = $provider->getData();
            $providerId = (int) ($data['id'] ?? 0);
            $appName    = (string) ($data['app_name'] ?? "provider #{$providerId}");

            if ($providerId <= 0) {
                continue;
            }

            $checked++;

            $reachable = $this->reachabilityChecker->isReachable($data);
            if ($reachable === null) {
                // Nothing configured to probe (no jwks_endpoint / well_known_config_url) —
                // skip silently rather than counting this as a failure.
                continue;
            }

            if ($this->processResult($data, $providerId, $appName, $reachable)) {
                $alerted++;
            }
        }

        $this->oidcLogger->customlog(
            "HealthCheckAlert: Done — {$checked} provider(s) checked, {$alerted} alert(s) sent"
        );
    }

    /**
     * Update the per-provider failure/alert state for one reachability result.
     *
     * Fires a webhook when appropriate; returns true when one was actually sent.
     *
     * @param mixed[] $data       Current provider row data
     * @param int     $providerId
     * @param string  $appName
     * @param bool    $reachable
     */
    private function processResult(array $data, int $providerId, string $appName, bool $reachable): bool
    {
        $lastStatus         = (string) ($data['health_alert_last_status'] ?? 'ok');
        $consecutiveFailures = (int) ($data['health_alert_consecutive_failures'] ?? 0);
        $threshold          = (int) ($data['health_alert_failure_threshold'] ?? 0);
        $notifyOnRecovery   = (int) ($data['health_alert_notify_on_recovery'] ?? 1) === 1;
        $firstFailureAt     = (string) ($data['health_alert_first_failure_at'] ?? '');

        $changes = [];
        $sent    = false;

        if ($reachable) {
            if ($lastStatus === 'down') {
                if ($notifyOnRecovery) {
                    $sent = $this->fireWebhook($data, $providerId, $appName, 'recovered', $firstFailureAt);
                }
                $changes['health_alert_last_status'] = 'ok';
                if ($sent) {
                    $changes['health_alert_last_notified_at'] = $this->now();
                }
            }
            if ($consecutiveFailures !== 0) {
                $changes['health_alert_consecutive_failures'] = 0;
            }
            if ($firstFailureAt !== '') {
                $changes['health_alert_first_failure_at'] = null;
            }
        } else {
            if ($consecutiveFailures === 0) {
                $changes['health_alert_first_failure_at'] = $this->now();
                $firstFailureAt = $changes['health_alert_first_failure_at'];
            }
            $consecutiveFailures++;
            $changes['health_alert_consecutive_failures'] = $consecutiveFailures;

            if ($consecutiveFailures >= $threshold && $lastStatus !== 'down') {
                $sent = $this->fireWebhook(
                    $data,
                    $providerId,
                    $appName,
                    'down',
                    $firstFailureAt,
                    $consecutiveFailures,
                    $threshold
                );
                $changes['health_alert_last_status'] = 'down';
                if ($sent) {
                    $changes['health_alert_last_notified_at'] = $this->now();
                }
            }
        }

        if ($changes !== []) {
            $this->oauthUtility->saveProviderData($providerId, $changes);
        }

        return $sent;
    }

    /**
     * Build the webhook payload, re-validate the decrypted URL, and send it.
     *
     * @param mixed[] $data
     * @param int     $providerId
     * @param string  $appName
     * @param string  $event               'down' or 'recovered'
     * @param string  $firstFailureAt
     * @param int     $consecutiveFailures
     * @param int     $threshold
     */
    private function fireWebhook(
        array $data,
        int $providerId,
        string $appName,
        string $event,
        string $firstFailureAt,
        int $consecutiveFailures = 0,
        int $threshold = 0
    ): bool {
        $encryptedUrl = (string) ($data['health_alert_webhook_url'] ?? '');
        if ($encryptedUrl === '') {
            return false;
        }

        $webhookUrl = preg_match('/^\d+:\d+:/', $encryptedUrl)
            ? $this->encryptor->decrypt($encryptedUrl)
            : $encryptedUrl;

        if ($webhookUrl === '' || !$this->ssrfUrlValidator->isAllowedExternalHttpsUrl($webhookUrl)) {
            $this->oidcLogger->customlog(
                "HealthCheckAlert: [{$appName}] Refusing to send webhook — configured URL failed "
                . "SSRF validation at send time"
            );
            return false;
        }

        $checkedEndpoint = !empty($data['jwks_endpoint']) ? 'jwks_endpoint' : 'well_known_config_url';
        $nowIso          = $this->toIso8601($this->now());

        $payload = [
            'event'            => $event === 'down' ? 'oidc_provider_down' : 'oidc_provider_recovered',
            'provider_id'      => $providerId,
            'provider_name'    => (string) (($data['display_name'] ?? '') ?: $appName),
            'app_name'         => $appName,
            'status'           => $event,
            'checked_endpoint' => $checkedEndpoint,
            'store_base_url'   => $this->oauthUtility->getBaseUrl(),
            'timestamp'        => $nowIso,
        ];

        if ($event === 'down') {
            $payload['consecutive_failures'] = $consecutiveFailures;
            $payload['failure_threshold']    = $threshold;
            $payload['first_failure_at']     = $this->toIso8601($firstFailureAt);
        } else {
            $payload['down_since'] = $this->toIso8601($firstFailureAt);
            $downSince = $firstFailureAt !== '' ? strtotime($firstFailureAt) : false;
            $payload['down_duration_seconds'] = $downSince !== false ? (time() - $downSince) : null;
        }

        $result = $this->curl->sendWebhookNotification($webhookUrl, $payload);

        $this->oidcLogger->customlog(
            "HealthCheckAlert: [{$appName}] Webhook '{$payload['event']}' "
            . ($result['success'] ? 'sent successfully' : 'FAILED to send') . " (HTTP {$result['httpCode']})"
        );

        return $result['success'];
    }

    /**
     * Current timestamp in MySQL datetime format, for the health_alert_* columns.
     */
    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /**
     * Convert a MySQL datetime string (or empty string) to ISO-8601 for the webhook payload.
     *
     * @param string $mysqlDateTime
     */
    private function toIso8601(string $mysqlDateTime): ?string
    {
        if ($mysqlDateTime === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDateTime);
        return $date instanceof \DateTimeImmutable ? $date->format(\DateTimeInterface::ATOM) : null;
    }
}
