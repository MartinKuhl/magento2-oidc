<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions;

use Magento\Framework\App\Action\Context;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuth\AccessTokenRequest;
use M2Oidc\OAuth\Helper\OAuth\AccessTokenRequestBody;
use M2Oidc\OAuth\Helper\Curl;
use M2Oidc\OAuth\Helper\Exception\IncorrectUserInfoDataException;
use M2Oidc\OAuth\Helper\JwtVerifier;
use M2Oidc\OAuth\Helper\OAuthSecurityHelper;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Data\OidcAttributeMappingContext;
use M2Oidc\OAuth\Model\Security\OidcRateLimiter;
use M2Oidc\OAuth\Model\Service\OidcAuthenticationService;
use M2Oidc\OAuth\Model\Service\TokenRefreshService;

/**
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class ReadAuthorizationResponse extends BaseAction
{
    /** @var \Magento\Framework\UrlInterface */
    protected \Magento\Framework\UrlInterface $url;

    /** @var \Magento\Customer\Model\Session */
    private readonly \Magento\Customer\Model\Session $customerSession;

    /** @var \M2Oidc\OAuth\Helper\OAuthSecurityHelper */
    private readonly \M2Oidc\OAuth\Helper\OAuthSecurityHelper $securityHelper;

    /** @var \M2Oidc\OAuth\Helper\JwtVerifier */
    private readonly \M2Oidc\OAuth\Helper\JwtVerifier $jwtVerifier;

    /** @var \M2Oidc\OAuth\Helper\Curl */
    private readonly \M2Oidc\OAuth\Helper\Curl $curl;

    /** @var \M2Oidc\OAuth\Model\Service\OidcAuthenticationService */
    private readonly \M2Oidc\OAuth\Model\Service\OidcAuthenticationService $oidcAuthService;

    /** @var OidcRateLimiter */
    private readonly OidcRateLimiter $rateLimiter;

    /** @var TokenRefreshService */
    private readonly TokenRefreshService $tokenRefreshService;

    /** @var \M2Oidc\OAuth\Controller\Actions\CheckAttributeMappingAction */
    private readonly \M2Oidc\OAuth\Controller\Actions\CheckAttributeMappingAction $attrMappingAction;

    /** @var \Magento\Framework\Stdlib\CookieManagerInterface */
    private readonly \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager;

    /** @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory */
    private readonly \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory;

    /**
     * Initialize read authorization response action.
     *
     * @param Context $context
     * @param OAuthUtility $oauthUtility
     * @param \Magento\Framework\UrlInterface $url
     * @param \Magento\Customer\Model\Session $customerSession
     * @param OAuthSecurityHelper $securityHelper
     * @param JwtVerifier $jwtVerifier
     * @param Curl $curl
     * @param OidcAuthenticationService $oidcAuthService
     * @param CheckAttributeMappingAction $attrMappingAction
     * @param \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager
     * @param \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory
     * @param OidcRateLimiter $rateLimiter
     * @param TokenRefreshService $tokenRefreshService
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        \Magento\Framework\UrlInterface $url,
        \Magento\Customer\Model\Session $customerSession,
        OAuthSecurityHelper $securityHelper,
        JwtVerifier $jwtVerifier,
        Curl $curl,
        OidcAuthenticationService $oidcAuthService,
        CheckAttributeMappingAction $attrMappingAction,
        \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
        \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory,
        OidcRateLimiter $rateLimiter,
        TokenRefreshService $tokenRefreshService,
    ) {
        $this->url = $url;
        $this->customerSession = $customerSession;
        $this->securityHelper = $securityHelper;
        $this->jwtVerifier = $jwtVerifier;
        $this->curl = $curl;
        $this->oidcAuthService = $oidcAuthService;
        $this->attrMappingAction = $attrMappingAction;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->rateLimiter = $rateLimiter;
        $this->tokenRefreshService = $tokenRefreshService;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Process the OAuth/OIDC authorization callback.
     *
     * Validates state token, exchanges authorization code for access/ID tokens,
     * verifies JWT signatures, and redirects to the appropriate destination.
     *
     * @return \Magento\Framework\Controller\ResultInterface|\Magento\Framework\App\ResponseInterface
     */
    #[\Override]
    public function execute()
    {
        // configureSSOSession() removed from callback handler.
        // SameSite=None is only needed in SendAuthorizationRequest (outbound redirect to IdP).
        // Calling it here sets a stale Secure cookie that conflicts with session_regenerate_id()
        // inside setCustomerAsLoggedIn(), causing the browser to keep the old destroyed session ID.

        // Rate limiting: block IPs that exceed MAX_ATTEMPTS/WINDOW_SECONDS
        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->getRequest();
        $clientIp = (string) $request->getClientIp();
        if (!$this->rateLimiter->isAllowed($clientIp)) {
            $this->oauthUtility->customlog(
                "ReadAuthResponse: Rate limit exceeded for IP: " . $clientIp
            );
            $this->messageManager->addErrorMessage(
                (string) __('Too many authentication attempts. Please wait before trying again.')
            );
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        }

        $params = $this->getRequest()->getParams();

        // Preparatory logic and logging

        if (!isset($params['code'])) {
            // Parse loginType from state even on error
            $loginType = OAuthConstants::LOGIN_TYPE_CUSTOMER;
            $relayState = '';
            if (isset($params['state'])) {
                $stateData = $this->securityHelper->decodeRelayState($params['state']);
                if ($stateData !== null) {
                    $loginType = $stateData['loginType'];
                    $relayState = $stateData['relayState'];
                } else {
                    $parts = explode('|', (string) $params['state']);
                    $loginType = isset($parts[3]) ? $parts[3] : OAuthConstants::LOGIN_TYPE_CUSTOMER;
                    $relayState = urldecode($parts[0]);
                }
            }

            if (isset($params['error'])) {
                // Strip non-printable/non-ASCII characters from the IdP-supplied
                // error_description before logging or embedding in any redirect URL.
                $rawError = (string) ($params['error_description'] ?? $params['error']);
                // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
                $errorMsg = preg_replace('/[^\x20-\x7E]/', '', $rawError);
                $encodedError = base64_encode((string) $errorMsg);

                $isTest = (
                    ($this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST) == true)
                    || (strpos((string) $relayState, 'showTestResults') !== false)
                );

                if ($isTest && strpos((string) $relayState, 'showTestResults') !== false) {
                    $safeRelay = $this->securityHelper->validateRedirectUrl(
                        (string) $relayState,
                        $this->url->getUrl('customer/account/login')
                    );
                    $errorUrl = $safeRelay . (strpos($safeRelay, '?') !== false ? '&' : '?')
                        . 'oidc_error=' . urlencode($encodedError);
                    return $this->_redirect($errorUrl);
                }

                return $this->_redirect(
                    $this->resolveErrorLoginUrl($loginType, urlencode($encodedError))
                );
            }
        } else {
            $authorizationCode = $params['code'];
            $combinedRelayState = $params['state'];

            // Try JSON+Base64 decoding first; fall back to legacy pipe format for backward compatibility
            $stateData = $this->securityHelper->decodeRelayState($combinedRelayState);
            if ($stateData !== null) {
                $relayState = $stateData['relayState'];
                $originalSessionId = $stateData['sessionId'];
                $app_name = $stateData['appName'];
                $loginType = $stateData['loginType'];
                $stateToken = $stateData['stateToken'];
                $providerId = $stateData['providerId'] ?? 0;
                $headless = (bool) ($stateData['headless'] ?? false);
                // Headless flag is re-validated against provider config after lookup below
                $this->oauthUtility->setActiveProviderId($providerId);
            } else {
                /** @psalm-suppress RedundantCast */
                $parts = explode('|', (string) $combinedRelayState);
                $relayState = urldecode($parts[0]);
                $originalSessionId = isset($parts[1]) ? $parts[1] : '';
                $app_name = isset($parts[2]) ? urldecode($parts[2]) : '';
                $loginType = isset($parts[3]) ? $parts[3] : OAuthConstants::LOGIN_TYPE_CUSTOMER;
                $stateToken = isset($parts[4]) ? $parts[4] : '';
                $providerId = 0;
                $headless = false; // legacy format does not support headless
            }

            // Validate CSRF state token
            $this->oauthUtility->customlog(
                "ReadAuthResponse: Validating state token. "
                . "Original Session ID: " . $originalSessionId
                . ", State Token: " . substr((string) $stateToken, 0, 8) . "..."
            );

            if (empty($stateToken)
                || !$this->securityHelper->validateStateToken($originalSessionId, $stateToken)
            ) {
                $this->oauthUtility->customlog("ERROR: State token validation failed (CSRF protection)");
                $encodedError = urlencode(base64_encode('Security validation failed. Please try logging in again.'));
                return $this->_redirect($this->resolveErrorLoginUrl($loginType, $encodedError));
            }

            $this->oauthUtility->customlog(
                "ReadAuthResponse: State token validation PASSED"
            );

            // Consume the OIDC nonce that was stored when the authorization request was
            // sent. Returns null when no nonce was stored (e.g. non-OIDC flow), in which case
            // JwtVerifier will skip nonce validation rather than fail.
            $expectedNonce = $this->securityHelper->consumeOidcNonce($stateToken);

            // Prefer direct-by-ID lookup
            $clientDetails = ($providerId > 0)
                ? $this->oauthUtility->getClientDetailsById($providerId)
                : $this->oauthUtility->getClientDetailsByAppName($app_name);
            if (!$clientDetails) {
                $this->oauthUtility->customlog(
                    "ERROR: Provider not found for relay state (provider_id={$providerId}, app={$app_name})"
                );
                $encodedError = base64_encode(
                    'OAuth provider not found. Please try signing in again.'
                );
                return $this->_redirect($this->resolveErrorLoginUrl($loginType, $encodedError));
            }

            // Re-validate headless flag against provider config
            if ($headless && empty($clientDetails['headless_mode'])) {
                $headless = false;
            }

            // Build token request
            $clientID = $clientDetails["clientID"];
            // Cast to string: public clients have no secret (DB column is nullable).
            $clientSecret = (string) ($clientDetails["client_secret"] ?? '');
            $accessTokenURL = $clientDetails["access_token_endpoint"];
            $header = (int) ($clientDetails["values_in_header"] ?? 1);
            $body   = (int) ($clientDetails["values_in_body"] ?? 0);
            $redirectURL = $this->oauthUtility->getCallBackUrl();

            // PKCE (RFC 7636 §4.5): retrieve verifier from cache via cookie nonce (one-time use).
            // Two paths:
            //   (a) Customer flow / admin SSO button → frontend SendAuthorizationRequest → oidc_pkce_nonce cookie
            //   (b) Admin backend login → adminhtml SendAuthorizationRequest (F2) → oidc_admin_pkce_nonce cookie
            $pkceNonce    = (string) $this->cookieManager->getCookie('oidc_pkce_nonce', '');
            $codeVerifier = ($pkceNonce !== '')
                ? $this->securityHelper->consumePkceVerifier($pkceNonce)
                : null;

            // Delete cookie regardless of result (prevent stale cookie on retry)
            if ($pkceNonce !== '') {
                $this->cookieManager->deleteCookie(
                    'oidc_pkce_nonce',
                    $this->cookieMetadataFactory->createCookieMetadata()->setPath('/m2oidc/')
                );
            }

            // Path (b): admin nonce cookie set by adminhtml SendAuthorizationRequest (F2)
            if ($codeVerifier === null) {
                $adminPkceNonce = (string) $this->cookieManager->getCookie('oidc_admin_pkce_nonce', '');
                if ($adminPkceNonce !== '') {
                    $codeVerifier = $this->securityHelper->consumePkceVerifier($adminPkceNonce);
                    $this->cookieManager->deleteCookie(
                        'oidc_admin_pkce_nonce',
                        $this->cookieMetadataFactory->createCookieMetadata()->setPath('/m2oidc/')
                    );
                }
            }

            if ($codeVerifier !== null) {
                $this->oauthUtility->customlog(
                    "ReadAuthResponse: PKCE code_verifier loaded from cache — including in token request"
                );
            } else {
                $this->oauthUtility->customlog(
                    "ReadAuthResponse: PKCE code_verifier not found in cache"
                );
            }

            if ($header === 1 && $body === 0) {
                // Public clients (empty secret) get no Authorization header from
                // Curl::sendAccessTokenRequest(), so client_id must be carried in the
                // body (RFC 6749 §3.2.1). Confidential clients authenticate via HTTP
                // Basic and must not duplicate client_id in the body (RFC 6749 §2.3.1).
                $bodyClientID = ($clientSecret === '') ? (string) $clientID : null;
                $accessTokenRequest = (new AccessTokenRequestBody(
                    $redirectURL,
                    $authorizationCode,
                    $codeVerifier,
                    $bodyClientID
                ))->build();
            } else {
                $accessTokenRequest = (new AccessTokenRequest(
                    $clientID,
                    $clientSecret,
                    $redirectURL,
                    $authorizationCode,
                    $codeVerifier
                ))->build();
            }

            $accessTokenResponse = $this->curl->sendAccessTokenRequest(
                $accessTokenRequest,
                $accessTokenURL,
                $clientID,
                $clientSecret,
                $header,
                $body
            );

            $accessTokenResponseData = json_decode($accessTokenResponse, true);

            // ── RP-Initiated Logout: persist id_token via cookie for Oidccallback ──
            if (!empty($accessTokenResponseData['id_token'])) {
                $rawIdToken = $accessTokenResponseData['id_token'];
                
                // Encrypt before storing in cookie
                $encryptedToken = $this->oauthUtility->getEncryptor()->encrypt($rawIdToken);
                
                $metadata = $this->cookieMetadataFactory
                    ->createPublicCookieMetadata()
                    ->setPath('/')
                    ->setHttpOnly(true)
                    ->setSecure(true)
                    ->setSameSite('Lax')
                    ->setDuration(120); // 2 min — just enough for the redirect chain

                $this->cookieManager->setPublicCookie(
                    'oidc_id_token_transport',
                    $encryptedToken,
                    $metadata
                );

                $this->cookieManager->setPublicCookie(
                    'oidc_provider_id_transport',
                    (string) $providerId,
                    $metadata
                );

                $this->oauthUtility->customlog(
                    'ReadAuthResponse: id_token stored in transport cookie for provider_id=' . $providerId
                );
            }

            // ── Admin token transport (FEAT-03 admin): carry access/refresh tokens ──
            // ReadAuthorizationResponse runs in the frontend PHP process; Oidccallback
            // runs in the admin process with a separate PHP session. Transport cookies
            // (encrypted, 2-min TTL) bridge this gap, mirroring the id_token_transport
            // pattern above. Only set for admin flows to avoid unnecessary cookie data.
            if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN
                && !empty($accessTokenResponseData['access_token'])
            ) {
                $tokenMeta = $this->cookieMetadataFactory
                    ->createPublicCookieMetadata()
                    ->setPath('/')
                    ->setHttpOnly(true)
                    ->setSecure(true)
                    ->setSameSite('Lax')
                    ->setDuration(120);

                $encryptedAccess = $this->oauthUtility->getEncryptor()->encrypt(
                    (string) $accessTokenResponseData['access_token']
                );
                $this->cookieManager->setPublicCookie(
                    'oidc_access_token_transport',
                    $encryptedAccess,
                    $tokenMeta
                );

                if (!empty($accessTokenResponseData['refresh_token'])) {
                    $encryptedRefresh = $this->oauthUtility->getEncryptor()->encrypt(
                        (string) $accessTokenResponseData['refresh_token']
                    );
                    $this->cookieManager->setPublicCookie(
                        'oidc_refresh_token_transport',
                        $encryptedRefresh,
                        $tokenMeta
                    );
                }

                $expiresIn = (int) ($accessTokenResponseData['expires_in'] ?? 3600);
                $this->cookieManager->setPublicCookie(
                    'oidc_expires_in_transport',
                    (string) $expiresIn,
                    $tokenMeta
                );

                $this->oauthUtility->customlog(
                    'ReadAuthResponse: access/refresh tokens stored in transport cookies for admin login'
                );
            }
            // ── END admin token transport ──

            // ── END id_token transport ──

            $userInfoResponseData = null;
            if (isset($accessTokenResponseData['access_token']) || isset($accessTokenResponseData['id_token'])) {
                // Fetch user info
                $userInfoURL = $clientDetails['user_info_endpoint'];
                if ($userInfoURL != null && $userInfoURL != '' && isset($accessTokenResponseData['access_token'])) {
                    $accessToken = $accessTokenResponseData['access_token'];

                    // Store tokens in session for subsequent refresh (Phase 2.3)
                    $this->tokenRefreshService->storeTokens(
                        (string) ($accessTokenResponseData['refresh_token'] ?? ''),
                        (int) ($accessTokenResponseData['expires_in'] ?? 0),
                        (string) $accessToken
                    );

                    $headerAuth = "Bearer " . $accessToken;
                    $authHeader = ["Authorization: $headerAuth"];
                    $userInfoResponse = $this->curl->sendUserInfoRequest($userInfoURL, $authHeader);
                    $userInfoResponseData = json_decode($userInfoResponse, true);

                    // When user data comes from the userinfo endpoint but the token
                    // response also contains an id_token, validate that token's nonce.
                    // This prevents replay attacks in the hybrid flow.
                    if (!empty($accessTokenResponseData['id_token'])) {
                        $idTokenValidation = $this->resolveUserInfoFromIdToken(
                            (string) $accessTokenResponseData['id_token'],
                            $clientDetails,
                            $clientID,
                            $relayState,
                            $loginType,
                            $expectedNonce
                        );
                        if ($idTokenValidation instanceof \Magento\Framework\Controller\ResultInterface) {
                            return $idTokenValidation;
                        }
                    }
                } elseif (isset($accessTokenResponseData['id_token'])) {
                    $idToken = $accessTokenResponseData['id_token'];
                    if (!empty($idToken)) {
                        $idTokenResult = $this->resolveUserInfoFromIdToken(
                            $idToken,
                            $clientDetails,
                            $clientID,
                            $relayState,
                            $loginType,
                            $expectedNonce
                        );
                        if ($idTokenResult instanceof \Magento\Framework\Controller\ResultInterface) {
                            return $idTokenResult;
                        }
                        $userInfoResponseData = $idTokenResult;
                    }
                }

                if (empty($userInfoResponseData)) {
                    $this->oauthUtility->customlog("ERROR: Empty user info response data");
                    $encodedError = base64_encode('Invalid response from OAuth provider. Please try again.');
                    // Test mode: show error on showTestResults instead of admin login
                    if (strpos((string) $relayState, 'showTestResults') !== false) {
                        $safeRelay = $this->securityHelper->validateRedirectUrl(
                            (string) $relayState,
                            $this->url->getUrl('customer/account/login')
                        );
                        $errorUrl = rtrim($safeRelay, '/') . '?oidc_error=' . urlencode($encodedError);
                        return $this->_redirect($errorUrl);
                    }
                    return $this->_redirect($this->resolveErrorLoginUrl($loginType, $encodedError));
                }

                // ==== TEST REDIRECT LOGIC ====
                // Removed user-controlled $params['option'] trigger. Test mode is now
                // only activated by server-side config (IS_TEST) or a relay state that
                // originates from the admin-only "Test Configuration" button
                // (which embeds 'showTestResults' in the relay state it sends to the IdP).
                $isTest = (
                    ($this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST) == true)
                    || (strpos((string) $relayState, 'showTestResults') !== false)
                );

                if ($isTest) {
                    // Extract test key from relayState (e.g. /key/abc123...)
                    preg_match('/key\/([a-f0-9]{32})/', (string) $relayState, $matches);
                    $testKey = $matches[1] ?? '';
                    $this->storeTestResultInSession($testKey, $userInfoResponseData);

                    // Append provider_id as URL parameter to the relayState URL.
                    // Session-based approach removed — sessions are unreliable across
                    // cross-domain OIDC redirects (SameSite cookies, session regeneration).
                    if ($providerId > 0 && strpos((string) $relayState, 'showTestResults') !== false) {
                        $separator = (strpos((string) $relayState, '?') !== false) ? '&' : '?';
                        $relayState .= $separator . 'provider_id=' . $providerId;
                        $this->oauthUtility->customlog(
                            'ReadAuthResponse: appended provider_id=' . $providerId . ' to relayState URL'
                        );
                    }

                    $safeRelayState = $this->securityHelper->validateRedirectUrl($relayState, '/');
                    return $this->_redirect($safeRelayState);
                }
                // ==== END TEST REDIRECT LOGIC ====

                // Process authentication via service layer
                if (is_array($userInfoResponseData)) {
                    $userInfoResponseData['relayState'] = $relayState;
                    $userInfoResponseData['loginType'] = $loginType;
                } else {
                    $userInfoResponseData->relayState = $relayState;
                    $userInfoResponseData->loginType = $loginType;
                }

                try {
                    $this->oidcAuthService->validateUserInfo($userInfoResponseData);
                } catch (IncorrectUserInfoDataException $e) {
                    $this->oauthUtility->customlog(
                        "ERROR: Invalid user info data from OAuth provider - " . $e->getMessage()
                    );
                    $errorMsg = 'Authentication failed: Invalid user information received from identity provider.';
                    if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                        $this->messageManager->addErrorMessage((string) __($errorMsg));
                        return $this->resultRedirectFactory->create()->setPath('admin');
                    }
                    $encodedError = base64_encode($errorMsg);
                    $loginUrl = $this->url->getUrl(
                        'customer/account/login',
                        ['_query' => ['oidc_error' => $encodedError]]
                    );
                    return $this->_redirect($loginUrl);
                }

                $flattenedResponse = [];
                $this->oidcAuthService->flattenAttributes('', $userInfoResponseData, $flattenedResponse);

                $userEmail = $this->oidcAuthService->extractEmail($flattenedResponse, $userInfoResponseData);
                if ($userEmail === '' || $userEmail === '0') {
                    $encodedError = base64_encode(
                        'Email address not received. Please check attribute mapping.'
                    );
                    $loginUrl = $this->url->getUrl(
                        'customer/account/login',
                        ['_query' => ['oidc_error' => $encodedError]]
                    );
                    return $this->_redirect($loginUrl);
                }

                $this->oauthUtility->customlog("ReadAuthorizationResponse: loginType = " . $loginType);

                // Pass per-provider attribute mappings via the immutable context DTO
                $mappingContext = new OidcAttributeMappingContext(
                    $userInfoResponseData,
                    $flattenedResponse,
                    $userEmail,
                    $loginType,
                    $headless,
                    $clientDetails
                );

                return $this->attrMappingAction->handle($mappingContext);
            }
            $this->oauthUtility->customlog("ERROR: Invalid token response - no access_token or id_token");
            $encodedError = base64_encode('Invalid response from OAuth provider. Please try again.');
            // Test mode: show error on showTestResults instead of admin login
            if (strpos((string) $relayState, 'showTestResults') !== false) {
                $safeRelay = $this->securityHelper->validateRedirectUrl(
                    (string) $relayState,
                    $this->url->getUrl('customer/account/login')
                );
                $errorUrl = rtrim($safeRelay, '/') . '?oidc_error=' . urlencode($encodedError);
                return $this->_redirect($errorUrl);
            }
            return $this->_redirect($this->resolveErrorLoginUrl($loginType, $encodedError));
        }
        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath($this->oauthUtility->getCallBackUrl());
        return $resultRedirect;
    }

    /**
     * Store OIDC test result data in the customer session under the given key.
     *
     * Filters out large token values to prevent session bloat.
     * Keeps only the 3 most recent test results.
     *
     * @param  string $testKey          Hex key extracted from relayState
     * @param  mixed  $userInfoResponse Raw user info response data
     */
    private function storeTestResultInSession(string $testKey, $userInfoResponse): void
    {
        if ($testKey === '' || $testKey === '0') {
            return;
        }
        $testResults = $this->customerSession->getData('m2oidc_test_results') ?: [];
        // Filter out large token data to prevent session bloat
        $filteredData = $userInfoResponse;
        if (is_array($filteredData)) {
            $excludeKeys = ['access_token', 'refresh_token', 'id_token', 'token'];
            foreach ($excludeKeys as $exKey) {
                unset($filteredData[$exKey]);
            }
        }
        $testResults[$testKey] = $filteredData;
        // Only keep the latest 3 test results
        if (count($testResults) > 3) {
            $testResults = array_slice($testResults, -3, 3, true);
        }
        $this->customerSession->setData('m2oidc_test_results', $testResults);
    }

    /**
     * Verify and decode an OIDC id_token using the configured JWKS endpoint.
     *
     * Returns the decoded claims array on success, a redirect ResultInterface on
     * verification failure, or null when no JWKS endpoint is configured.
     *
     * @param  string      $idToken       Raw JWT id_token from the token endpoint
     * @param  mixed[]     $clientDetails Provider configuration row
     * @param  string      $clientID      OAuth client identifier
     * @param  string      $relayState    Relay state URL for test-mode error redirect
     * @param  string      $loginType     Login type (admin|customer) for error routing
     * @param  string|null $expectedNonce OIDC nonce to validate, null to skip
     * @return mixed
     */
    private function resolveUserInfoFromIdToken(
        string $idToken,
        array $clientDetails,
        string $clientID,
        string $relayState,
        string $loginType,
        ?string $expectedNonce = null
    ) {
        $jwksEndpoint = $clientDetails['jwks_endpoint'] ?? '';
        if (!empty($jwksEndpoint)) {
            // Resolve expected issuer from stored discovery document data
            $expectedIssuer = $clientDetails['issuer'] ?? null;
            if (empty($expectedIssuer)) {
                // Fallback: derive issuer from well-known URL
                $wellKnownUrl = $clientDetails['well_known_config_url'] ?? '';
                if (!empty($wellKnownUrl)) {
                    $expectedIssuer = preg_replace(
                        '#/\.well-known/openid-configuration$#i',
                        '',
                        (string) $wellKnownUrl
                    );
                }
            }
            // Pass expectedNonce so JwtVerifier validates the nonce claim
            $decoded = $this->jwtVerifier->verifyAndDecode(
                $idToken,
                $jwksEndpoint,
                $expectedIssuer,
                $clientID,
                $expectedNonce
            );
            if ($decoded === null) {
                $this->oauthUtility->customlog("ERROR: JWT signature verification failed");
                $encodedError = base64_encode(
                    'ID token verification failed. Please contact the administrator.'
                );
                if (strpos($relayState, 'showTestResults') !== false) {
                    $safeRelay = $this->securityHelper->validateRedirectUrl(
                        $relayState,
                        $this->url->getUrl('customer/account/login')
                    );
                    $errorUrl = rtrim($safeRelay, '/') . '?oidc_error='
                        . urlencode($encodedError);
                    return $this->_redirect($errorUrl);
                }
                return $this->_redirect($this->resolveErrorLoginUrl($loginType, $encodedError));
            }
            return $decoded;
        }

        // SECURITY: Refuse unverified JWTs — JWKS endpoint is required
        $this->oauthUtility->customlog(
            "ERROR: Cannot verify id_token - no JWKS endpoint configured."
        );
        $encodedError = base64_encode(
            'OIDC configuration error: JWKS endpoint is required for id_token verification.'
            . ' Please configure it in the OAuth settings.'
        );
        if (strpos($relayState, 'showTestResults') !== false) {
            $safeRelay = $this->securityHelper->validateRedirectUrl(
                $relayState,
                $this->url->getUrl('customer/account/login')
            );
            $errorUrl = rtrim($safeRelay, '/') . '?oidc_error='
                . urlencode($encodedError);
            return $this->_redirect($errorUrl);
        }
        return $this->_redirect($this->resolveErrorLoginUrl($loginType, $encodedError));
    }

    /**
     * Resolve the login-page error URL for the given login type.
     *
     * Routes admin logins to the admin login page and customer logins to the
     * customer login page, carrying the Base64-encoded error message in the
     * oidc_error query parameter (decoded for display by OidcErrorMessage).
     *
     * @param  string $loginType    Login type (admin|customer)
     * @param  string $encodedError Base64-encoded error message
     * @return string Login URL with the oidc_error query parameter
     */
    private function resolveErrorLoginUrl(string $loginType, string $encodedError): string
    {
        $query = ['_query' => ['oidc_error' => $encodedError]];
        return ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN)
            ? $this->url->getUrl('admin', $query)
            : $this->url->getUrl('customer/account/login', $query);
    }
}
