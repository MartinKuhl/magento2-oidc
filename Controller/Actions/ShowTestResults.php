<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions;

use M2Oidc\OAuth\Helper\Exception\IncorrectUserInfoDataException;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Service\OidcAuthenticationService;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Raw as RawResult;
use Magento\Framework\Controller\ResultFactory;

/**
 * Displays the OIDC attributes received during a test authentication in the frontend.
 *
 * Persists last_test_status and last_test_at to the provider record via
 * saveTestStatusById() (preferred, uses numeric ID from OAuth state) with
 * fallback to saveTestStatus() (legacy, uses app_name from customer session).
 */
class ShowTestResults extends Action
{
    /**
     * @var mixed Attributes received from OIDC provider
     */
    private $attrs;

    /**
     * @var string|null User email
     */
    private $userEmail;

    /**
     * @var string|null Greeting name derived from attributes
     */
    private $greetingName;

    /**
     * @var string|null Status of the test (TEST SUCCESSFUL / TEST FAILED / TEST UNSUCCESSFUL)
     */
    protected $status;

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var OidcAuthenticationService */
    private readonly OidcAuthenticationService $oidcAuthService;

    /** @var \Magento\Framework\App\Request\Http */
    protected \Magento\Framework\App\Request\Http $request;

    /** @var \Magento\Framework\Escaper */
    private readonly \Magento\Framework\Escaper $escaper;

    /** @var \Magento\Customer\Model\Session */
    private readonly \Magento\Customer\Model\Session $customerSession;

    /**
     * @var string Absolute path to the PHTML template used for rendering test results.
     */
    private readonly string $templatePath;

    /**
     * Initialize ShowTestResults action.
     *
     * @param Context                                    $context
     * @param OAuthUtility                               $oauthUtility
     * @param OidcAuthenticationService                  $oidcAuthService
     * @param \Magento\Framework\App\Request\Http        $request
     * @param \Magento\Customer\Model\Session            $customerSession
     * @param \Magento\Framework\Escaper                 $escaper
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        OidcAuthenticationService $oidcAuthService,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\Escaper $escaper
    ) {
        $this->oauthUtility    = $oauthUtility;
        $this->oidcAuthService = $oidcAuthService;
        $this->request         = $request;
        $this->customerSession = $customerSession;
        $this->escaper         = $escaper;
        // Absolute path to PHTML template (two dirs up from Controller/Actions/)
        // phpcs:disable Magento2.Functions.DiscouragedFunction.DiscouragedWithAlternative
        $this->templatePath  = dirname(__DIR__, 2)
            . '/view/frontend/templates/test_results.phtml';
        // phpcs:enable Magento2.Functions.DiscouragedFunction.DiscouragedWithAlternative
        parent::__construct($context);
    }

    /**
     * Main entry point: load test data from session, render PHTML template, and return it.
     */
    #[\Override]
    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        // Check for OIDC error first
        $oidcError = $this->request->getParam('oidc_error');
        if ($oidcError) {
            return $this->handleOidcError($oidcError);
        }

        // Read the session key from the URL parameter
        $key        = $this->request->getParam('key');
        $testResults = $this->customerSession->getData('m2oidc_test_results');
        $attrs      = (is_array($testResults) && isset($testResults[$key])) ? $testResults[$key] : null;

        // Resolve provider context so getStoreConfig() reads the correct row.
        $providerId = (int) $this->request->getParam('provider_id');
        if ($providerId > 0) {
            $this->oauthUtility->setActiveProviderId($providerId);
        }

        // Apply claim value decoding (e.g. Base64 for Zitadel metadata) to the raw
        // session attrs before rendering, using the same logic as the auth flow.
        if (!empty($attrs)
            && $this->oauthUtility->getStoreConfig(OAuthConstants::CLAIM_ENCODING)
                === OAuthConstants::CLAIM_ENCODING_BASE64
        ) {
            try {
                $decoded = [];
                $this->oidcAuthService->flattenAttributes('', $attrs, $decoded);
                $attrs = $decoded;
            } catch (IncorrectUserInfoDataException $e) {
                // Flatten limit exceeded — keep the raw attrs so the test page still renders.
                $this->oauthUtility->customlog(
                    'ShowTestResults: WARNING — could not flatten test attributes: ' . $e->getMessage()
                );
            }
        }

        // Normalize all Zitadel-style role claims for display — runs unconditionally so
        // users see extracted role names even before the group attribute is configured.
        // Handles both raw nested objects (most providers) and flat dot-notation subkeys
        // (Base64 providers after flattenAttributes has run above).
        if (!empty($attrs)) {
            $this->oidcAuthService->normalizeZitadelRoleClaimsForDisplay($attrs);
        }

        $this->setAttrs($attrs);
        if ($attrs !== null) {
            $this->setUserEmail($attrs['email'] ?? null);
        }
        $this->setGreetingName($attrs);

        // Store received OIDC claims per-provider for dropdown selection in Attribute Mapping
        if (!empty($attrs)) {
            $claimKeys = $this->extractClaimKeys($attrs);

            if ($providerId > 0) {
                // Per-provider: save to m2oidc_oauth_client_apps.received_oidc_claims
                $this->oauthUtility->saveReceivedOidcClaims($providerId, $claimKeys);
                $encodedKeys = json_encode($claimKeys) ?: '[]';
                $this->oauthUtility->customlog(
                    'Stored received OIDC claims for provider ID=' . $providerId . ': ' . $encodedKeys
                );
            } else {
                $this->oauthUtility->customlog(
                    'WARNING: Could not store OIDC claims – no provider_id in request.'
                );
            }

            $this->oauthUtility->flushCache();
        }

        if (ob_get_length()) {
            ob_end_clean();
        }
        $this->oauthUtility->customlog('ShowTestResultsAction: execute');

        $this->status = $this->oauthUtility->isBlank($this->userEmail) ? 'TEST FAILED' : 'TEST SUCCESSFUL';

        // Persist last test status to provider record
        $testStatus = ($this->status === 'TEST SUCCESSFUL') ? 'success' : 'failed';
        $this->persistTestStatus($testStatus);

        $this->oauthUtility->flushCache();

        // FIX: providerConfig laden und an Template übergeben
        $providerConfig = $this->loadProviderConfig();

        $data = $this->renderTemplate([
            'status'         => $this->status,
            'attrs'          => $this->attrs,
            'greetingName'   => $this->greetingName ?? '',
            'rightImage'     => $this->oauthUtility->getImageUrl(OAuthConstants::IMAGE_RIGHT),
            'wrongImage'     => $this->oauthUtility->getImageUrl(OAuthConstants::IMAGE_WRONG),
            'errorMessage'   => '',
            'providerConfig' => $providerConfig,
        ]);

        /** @var RawResult $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setContents($data);
        return $result;
    }

    /**
     * Handle OIDC error and display the TEST UNSUCCESSFUL page.
     *
     * @param string $encodedError Base64-encoded error message from the query string
     */
    private function handleOidcError(string $encodedError): \Magento\Framework\Controller\ResultInterface
    {
        if (ob_get_length()) {
            ob_end_clean();
        }
        $this->oauthUtility->customlog('ShowTestResultsAction: handleOidcError');

        $errorMessage = $this->oauthUtility->decodeBase64($encodedError);
        $this->status = 'TEST UNSUCCESSFUL';

        // Persist unsuccessful status
        $this->persistTestStatus('unsuccessful');

        // FIX: providerConfig laden und an Template übergeben
        $providerConfig = $this->loadProviderConfig();

        $data = $this->renderTemplate([
            'status'         => $this->status,
            'attrs'          => null,
            'greetingName'   => '',
            'rightImage'     => $this->oauthUtility->getImageUrl(OAuthConstants::IMAGE_RIGHT),
            'wrongImage'     => $this->oauthUtility->getImageUrl(OAuthConstants::IMAGE_WRONG),
            // Raw value — the template escapes it at the render site
            'errorMessage'   => $errorMessage,
            'providerConfig' => $providerConfig,
        ]);

        /** @var RawResult $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setContents($data);
        return $result;
    }

    /**
     * Load PKCE/JWKS config for the current provider.
     *
     * @return array{pkce_flow?: string, jwks_uri?: string}
     */
    private function loadProviderConfig(): array
    {
        $providerId = (int) $this->request->getParam('provider_id');
        if ($providerId < 1) {
            return [];
        }

        $clientDetails = $this->oauthUtility->getClientDetailsById($providerId);
        if (!is_array($clientDetails)) {
            return [];
        }

        return [
            'pkce_flow' => (string) ($clientDetails['pkce_flow'] ?? ''),
            'jwks_uri'  => (string) ($clientDetails['jwks_endpoint'] ?? ''),
        ];
    }

    /**
     * Render the test_results PHTML template with the given variables.
     *
     * @param  mixed[] $vars Associative array of variables to extract into the template scope
     * @return string Rendered HTML
     */
    private function renderTemplate(array $vars): string
    {
        $escaper = $this->escaper;
        extract($vars); // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        ob_start();    // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        /** @psalm-suppress UnresolvableInclude */
        include $this->templatePath; // phpcs:ignore Magento2.Security.IncludeFile.FoundIncludeFile
        $output = ob_get_clean();
        return (string) $output;
    }

    /**
     * Set the user attributes for display.
     *
     * @param mixed $attrs
     */
    public function setAttrs($attrs): void
    {
        $this->attrs = $attrs;
    }

    /**
     * Set the user email from the OIDC attributes.
     *
     * @param string|null $email
     */
    public function setUserEmail($email): void
    {
        $this->userEmail = $email;
    }

    /**
     * Derive a greeting name from the OIDC attributes and store it.
     *
     * @param mixed $attrs
     */
    public function setGreetingName($attrs): void
    {
        if (!is_array($attrs)) {
            $this->greetingName = null;
            return;
        }
        $this->greetingName = $attrs['name']
            ?? $attrs['given_name']
            ?? $attrs['preferred_username']
            ?? $attrs['email']
            ?? null;
    }

    /**
     * Persist the test status to the provider record.
     *
     * Priority:
     *   1. provider_id from URL parameter (redirect-safe, set by ReadAuthorizationResponse)
     *   2. app_name from customer session (legacy fallback)
     *
     * @param string $status 'success' | 'failed' | 'unsuccessful'
     */
    private function persistTestStatus(string $status): void
    {
        // 1. Preferred: provider_id from URL parameter (redirect-safe)
        $providerId = (int) $this->request->getParam('provider_id');

        if ($providerId > 0) {
            $this->oauthUtility->customlog(
                'ShowTestResults: persisting status "' . $status . '" via provider ID=' . $providerId
            );
            $this->oauthUtility->saveTestStatusById($providerId, $status);
            return;
        }

        // Fallback: app_name from customer session (legacy single-provider path)
        $appName = (string) $this->oauthUtility->getSessionData(OAuthConstants::APP_NAME);
        if ($appName !== '') {
            $this->oauthUtility->customlog(
                'ShowTestResults: persisting status "' . $status . '" via app_name="' . $appName . '"'
            );
            $this->oauthUtility->saveTestStatus($appName, $status);
            return;
        }

        $this->oauthUtility->customlog(
            'ShowTestResults: WARNING – could not persist test status, '
            . 'neither provider ID nor app_name found in session.'
        );
    }

    /**
     * Extract claim keys from the OIDC attribute array.
     *
     * Flattens nested objects using dot-notation (e.g. address.locality).
     * Skips numeric array indices to avoid entries like "0", "1".
     *
     * @param  mixed[]    $attrs  OIDC attributes
     * @param  int|string $prefix Dot-notation prefix for recursion
     * @return string[]   Flat list of claim key names
     */
    private function extractClaimKeys(array $attrs, int|string $prefix = ''): array
    {
        $keys = [];
        $nestedChunks = [];
        foreach ($attrs as $key => $value) {
            // Skip numeric array indices (e.g., groups: ["admin", "user"] → 0, 1)
            if (is_int($key)) {
                continue;
            }

            $fullKey = $prefix !== '' ? $prefix . '.' . $key : $key;

            if (is_array($value) && !$this->isIndexedArray($value)) {
                // Nested associative object: only add dotted sub-keys, NOT the parent key
                $nestedChunks[] = $this->extractClaimKeys($value, $fullKey);
            } else {
                $keys[] = $fullKey;
            }
        }

        if ($nestedChunks !== []) {
            return array_merge($keys, ...$nestedChunks);
        }

        return $keys;
    }

    /**
     * Check if array is numerically indexed (list) vs associative (object).
     *
     * @param mixed[] $arr
     */
    private function isIndexedArray(array $arr): bool
    {
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
