<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Helper;

use Magento\Framework\App\CacheInterface;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthMessages;

/**
 * Pure PHP JWT verification using openssl_verify().
 *
 * Supports RS256, RS384, RS512 signature verification via JWKS endpoints.
 * No external composer dependencies required.
 */
class JwtVerifier
{
    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var CacheInterface */
    private readonly CacheInterface $cache;

    /** @var \Magento\Framework\HTTP\Adapter\CurlFactory */
    private readonly \Magento\Framework\HTTP\Adapter\CurlFactory $curlFactory;

    /**
     * Initialize JWT verifier.
     *
     * @param OAuthUtility                                $oauthUtility
     * @param CacheInterface                              $cache
     * @param \Magento\Framework\HTTP\Adapter\CurlFactory $curlFactory
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        CacheInterface $cache,
        \Magento\Framework\HTTP\Adapter\CurlFactory $curlFactory
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->cache = $cache;
        $this->curlFactory = $curlFactory;
    }

    /**
     * Verify and decode a JWT id_token using the provider's JWKS endpoint.
     *
     * When $expectedNonce is non-null the nonce claim in the token payload
     * MUST match exactly; a missing or mismatched nonce is rejected. Pass null
     * to skip nonce validation (e.g. when the IdP does not support nonces).
     *
     * @param  string      $idToken       The raw JWT string
     * @param  string      $jwksUrl       The JWKS endpoint URL
     * @param  string|null $issuer        Expected issuer (iss claim), null to skip
     * @param  string|null $audience      Expected audience (aud claim), null to skip
     * @param  string|null $expectedNonce Expected nonce claim value, null to skip
     * @return array<string, mixed>|null Decoded payload array, or null on failure
     */
    public function verifyAndDecode(
        string $idToken,
        string $jwksUrl,
        ?string $issuer,
        ?string $audience,
        ?string $expectedNonce = null
    ): ?array {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            $this->oauthUtility->customlog("JwtVerifier: Invalid JWT format - expected 3 parts, got " . count($parts));
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        if (!$header || !isset($header['alg'])) {
            $this->oauthUtility->customlog("JwtVerifier: Cannot decode JWT header or missing alg");
            return null;
        }

        $algMap = [
            'RS256' => OPENSSL_ALGO_SHA256,
            'RS384' => OPENSSL_ALGO_SHA384,
            'RS512' => OPENSSL_ALGO_SHA512,
        ];

        $alg = strtoupper((string) $header['alg']);
        $weakAlgorithms = ['NONE', 'HS256', 'HS384', 'HS512'];
        if (in_array($alg, $weakAlgorithms, true)) {
            $this->oauthUtility->customlog("JwtVerifier: REJECTED weak/forbidden algorithm: " . $alg);
            return null;
        }
        if (!isset($algMap[$alg])) {
            $this->oauthUtility->customlog("JwtVerifier: Unsupported algorithm: " . $alg);
            return null;
        }

        // Fetch JWKS
        $jwks = $this->fetchJwks($jwksUrl);
        if ($jwks === null) {
            return null;
        }

        // Find the matching public key in the cached JWKS
        $kid = isset($header['kid']) ? (string) $header['kid'] : null;
        $pem = $this->findPublicKey($jwks, $kid, $alg);

        // Token has no kid and the cached key set holds no usable key at all —
        // a re-fetch cannot pick a better key, so fail immediately.
        if ($pem === null && $kid === null) {
            $this->oauthUtility->customlog("JwtVerifier: No matching key found in JWKS for kid=null");
            return null;
        }

        $dataToVerify = $headerB64 . '.' . $payloadB64;
        $signature = $this->base64UrlDecode($signatureB64);

        $result = ($pem !== null)
            ? openssl_verify($dataToVerify, $signature, $pem, $algMap[$alg])
            : 0;

        if ($result !== 1) {
            // M-13: Only evict + re-fetch the JWKS cache on a true key-rotation
            // signal: the token names a kid that is absent from the cached key set,
            // or the token has no kid header at all (some IdPs omit it, so a stale
            // cache cannot be distinguished from a bad signature). A failed
            // signature under a kid that IS in the cached set means the token
            // itself is invalid — re-fetching would not change the outcome.
            if ($kid !== null && $pem !== null) {
                $this->oauthUtility->customlog(
                    "JwtVerifier: Signature verification FAILED with known kid=" . $kid
                    . " — token invalid, keeping cached JWKS"
                );
                return null;
            }

            if ($pem === null) {
                $this->oauthUtility->customlog(
                    "JwtVerifier: kid=" . $kid . " not found in cached JWKS —"
                    . " possible key rotation. Re-fetching JWKS."
                );
            } else {
                $this->oauthUtility->customlog(
                    "JwtVerifier: Signature verification FAILED with cached JWKS (token has no kid)."
                    . " Retrying with fresh JWKS."
                );
            }

            // #11: Circuit-breaker — if JWKS endpoint recently returned an error, skip re-fetch
            // to prevent 30 s cURL timeouts cascading across concurrent login attempts during IdP outage.
            $failKey = 'm2oidc_jwks_fail_' . hash('sha256', $jwksUrl);
            if ($this->cache->load($failKey) !== false) {
                $this->oauthUtility->customlog(
                    "JwtVerifier: JWKS circuit-breaker open — skipping re-fetch to avoid IdP hammering"
                );
                return null;
            }

            // Invalidate cache and re-fetch in case the IdP rotated its keys
            $cacheKey = 'm2oidc_jwks_' . hash('sha256', $jwksUrl);
            $this->cache->remove($cacheKey);
            $freshJwks = $this->fetchJwks($jwksUrl);
            if ($freshJwks !== null) {
                $freshPem = $this->findPublicKey($freshJwks, $kid, $alg);
                if ($freshPem !== null) {
                    $result = openssl_verify($dataToVerify, $signature, $freshPem, $algMap[$alg]);
                }
            } else {
                // fetchJwks failed — open circuit for 60 s to stop concurrent requests hammering the endpoint
                $this->cache->save('1', $failKey, [], 60);
            }
            if ($result !== 1) {
                $this->oauthUtility->customlog("JwtVerifier: Signature verification FAILED after JWKS refresh");
                return null;
            }
            $this->oauthUtility->customlog("JwtVerifier: Signature verified with refreshed JWKS");
        }

        $this->oauthUtility->customlog("JwtVerifier: Signature verification PASSED");

        // Decode payload
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        if (!$payload) {
            $this->oauthUtility->customlog("JwtVerifier: Cannot decode JWT payload");
            return null;
        }

        // Validate expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            $this->oauthUtility->customlog(
                "JwtVerifier: " . OAuthMessages::parse('JWT_EXPIRED', [
                    'exp' => date('Y-m-d H:i:s', (int) $payload['exp']),
                    'now' => date('Y-m-d H:i:s'),
                ])
            );
            return null;
        }

        // Validate not-before (RFC 7519 §4.1.5)
        if (isset($payload['nbf']) && $payload['nbf'] > time()) {
            $this->oauthUtility->customlog(
                "JwtVerifier: Token not yet valid, nbf=" . date('Y-m-d H:i:s', $payload['nbf'])
            );
            return null;
        }

        // Validate issuer — a missing iss claim when one is expected is a failure (RFC 7519 §4.1.1)
        if ($issuer !== null && ($payload['iss'] ?? '') !== $issuer) {
            $this->oauthUtility->customlog(
                "JwtVerifier: " . OAuthMessages::parse('JWT_ISSUER_MISMATCH', [
                    'token_issuer'      => $payload['iss'] ?? 'MISSING',
                    'configured_issuer' => $issuer,
                ])
            );
            return null;
        }

        // Validate audience — a missing aud claim when one is expected is a failure (OIDC Core 1.0 §3.1.3.7)
        if ($audience !== null) {
            $aud = $payload['aud'] ?? null;
            if ($aud === null) {
                $this->oauthUtility->customlog("JwtVerifier: Audience claim missing - expected: $audience");
                return null;
            }
            $audMatch = is_array($aud) ? in_array($audience, $aud, true) : ($aud === $audience);
            if (!$audMatch) {
                $this->oauthUtility->customlog("JwtVerifier: Audience mismatch - expected: $audience");
                return null;
            }
        }

        // Validate nonce — prevents id_token replay attacks (OIDC Core 1.0 §3.1.2.1)
        if ($expectedNonce !== null) {
            $tokenNonce = $payload['nonce'] ?? null;
            if ($tokenNonce === null) {
                $this->oauthUtility->customlog(
                    "JwtVerifier: Nonce claim missing in id_token — expected nonce was set"
                );
                return null;
            }
            if ($tokenNonce !== $expectedNonce) {
                $this->oauthUtility->customlog(
                    "JwtVerifier: Nonce mismatch — token nonce does not match expected value"
                );
                return null;
            }
            $this->oauthUtility->customlog("JwtVerifier: Nonce validation PASSED");
        } else {
            // #5: Log when nonce validation is skipped so operators can detect misconfigured flows
            $this->oauthUtility->customlog(
                "JwtVerifier: WARNING — nonce validation skipped (expectedNonce is null). "
                . "If the IdP supports nonces, verify that consumeOidcNonce() is called before verifyAndDecode()."
            );
        }

        return $payload;
    }

    /**
     * Decode a JWT WITHOUT verifying the signature.
     *
     * ⚠️  DANGER: This method bypasses ALL signature, issuer, audience, and nonce
     * validation. It MUST NOT be used in any authentication code path. Use only
     * for debugging/logging claim structure, or in unit tests with mock tokens.
     *
     * @param string $idToken The raw JWT string
     * @return array<string, mixed>|null Decoded payload array, or null on failure
     * @internal For debug and test use only — never call from production auth code.
     */
    public function decodeWithoutVerification(string $idToken): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        return is_array($payload) ? $payload : null;
    }

    /**
     * Fetch and parse JWKS from the given URL.
     *
     * @param  string $jwksUrl
     * @return array<int, mixed>|null The JWKS keys array, or null on failure
     */
    private function fetchJwks(string $jwksUrl): ?array
    {
        $cacheKey = 'm2oidc_jwks_' . hash('sha256', $jwksUrl);
        $cached = $this->cache->load($cacheKey);
        if ($cached) {
            $cachedData = json_decode($cached, true);
            if (isset($cachedData['keys']) && is_array($cachedData['keys'])) {
                return $cachedData['keys'];
            }
        }

        $curl = $this->curlFactory->create();
        $curl->setConfig(
            [
            'header' => false,
            'CURLOPT_TIMEOUT' => (int) ($this->oauthUtility->getStoreConfig(OAuthConstants::HTTP_TIMEOUT)
                                  ?: OAuthConstants::HTTP_TIMEOUT_DEFAULT),
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            ]
        );
        $curl->write('GET', $jwksUrl, '1.1', ['Accept: application/json']);
        $response = $curl->read();
        $httpStatus = (int) $curl->getInfo(CURLINFO_HTTP_CODE);
        $curl->close();

        if ($httpStatus !== 200) {
            $this->oauthUtility->customlog(
                "JwtVerifier: JWKS fetch returned HTTP " . $httpStatus . " from: " . $jwksUrl
            );
            return null;
        }

        if (empty($response)) {
            $this->oauthUtility->customlog("JwtVerifier: Empty response from JWKS endpoint: " . $jwksUrl);
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['keys']) || !is_array($data['keys'])) {
            $this->oauthUtility->customlog("JwtVerifier: Invalid JWKS format from: " . $jwksUrl);
            return null;
        }

        $cacheTtl = (int) $this->oauthUtility->getStoreConfig(OAuthConstants::JWKS_CACHE_TTL) ?: 86400;
        $this->cache->save($response, $cacheKey, [], $cacheTtl);

        return $data['keys'];
    }

    /**
     * Find the matching RSA public key from JWKS and convert to PEM.
     *
     * @param  mixed[]     $keys The JWKS keys array
     * @param  string|null $kid  Key ID from the JWT header
     * @param  string      $alg  Algorithm from the JWT header
     * @return string|null PEM-encoded public key, or null if not found
     */
    private function findPublicKey(array $keys, ?string $kid, string $alg): ?string
    {
        foreach ($keys as $key) {
            // Must be RSA key type
            if (($key['kty'] ?? '') !== 'RSA') {
                continue;
            }

            // Match by kid if provided — strict: reject keys without kid when token has one
            if ($kid !== null && (!isset($key['kid']) || $key['kid'] !== $kid)) {
                continue;
            }

            // Match by alg if provided in JWKS
            if (isset($key['alg']) && strtoupper((string) $key['alg']) !== $alg) {
                continue;
            }

            // Must have modulus and exponent
            if (!isset($key['n']) || !isset($key['e'])) {
                continue;
            }

            $pem = $this->jwkToPem($key['n'], $key['e']);
            if ($pem !== null) {
                return $pem;
            }
        }

        return null;
    }

    /**
     * Convert JWK RSA modulus (n) and exponent (e) to PEM format.
     *
     * Uses ASN.1 DER encoding — no external dependencies.
     *
     * @param  string $n Base64url-encoded modulus
     * @param  string $e Base64url-encoded exponent
     * @return string|null PEM-encoded public key
     */
    private function jwkToPem(string $n, string $e): ?string
    {
        $modulus = $this->base64UrlDecode($n);
        $exponent = $this->base64UrlDecode($e);

        if ($modulus === '' || $modulus === '0' || ($exponent === '' || $exponent === '0')) {
            return null;
        }

        // Ensure modulus has leading zero byte if high bit is set (ASN.1 unsigned integer)
        if (ord($modulus[0]) > 0x7f) {
            $modulus = "\x00" . $modulus;
        }

        // Build ASN.1 DER structure
        $modBytes = $this->asn1Length(strlen($modulus));
        $expBytes = $this->asn1Length(strlen($exponent));

        // INTEGER modulus
        $modSequence = "\x02" . $modBytes . $modulus;
        // INTEGER exponent
        $expSequence = "\x02" . $expBytes . $exponent;

        // SEQUENCE { modulus, exponent }
        $rsaPublicKey = $modSequence . $expSequence;
        $rsaSequence = "\x30" . $this->asn1Length(strlen($rsaPublicKey)) . $rsaPublicKey;

        // BIT STRING wrapping
        $bitString = "\x00" . $rsaSequence;
        $bitStringDer = "\x03" . $this->asn1Length(strlen($bitString)) . $bitString;

        // AlgorithmIdentifier: OID for rsaEncryption + NULL
        $algorithmIdentifier = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        // SubjectPublicKeyInfo SEQUENCE
        $publicKeyInfo = $algorithmIdentifier . $bitStringDer;
        $publicKeyInfoDer = "\x30" . $this->asn1Length(strlen($publicKeyInfo)) . $publicKeyInfo;

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($publicKeyInfoDer), 64, "\n")
            . "-----END PUBLIC KEY-----";

        // Verify the PEM is valid
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            $this->oauthUtility->customlog("JwtVerifier: Failed to create public key from JWK");
            return null;
        }

        return $pem;
    }

    /**
     * Encode ASN.1 DER length bytes.
     *
     * @param  int $length
     */
    private function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return pack('C', $length);
        }

        $temp = ltrim(pack('N', $length), "\x00");
        return pack('C', 0x80 | strlen($temp)) . $temp;
    }

    /**
     * Base64url decode (RFC 7515).
     *
     * @param  string $input
     */
    private function base64UrlDecode(string $input): string
    {
        if ($input !== '' && !preg_match('/^[A-Za-z0-9_=-]+$/', $input)) {
            return '';
        }
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        $translated = strtr($input, '-_', '+/');
        return $this->oauthUtility->decodeBase64($translated);
    }
}
