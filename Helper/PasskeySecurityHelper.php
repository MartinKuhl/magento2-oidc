<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Helper;

use M2Oidc\OAuth\Model\Cache\AtomicCacheInterface;

/**
 * Security primitives for the Passkey (WebAuthn) login flow.
 *
 * Structurally parallel to OAuthSecurityHelper's ephemeral-token machinery,
 * but with distinct constants/markers ('PKEY_' vs 'OIDC_') so the two flows
 * can never collide or be mistaken for one another by
 * AdminLoginRestrictionPlugin / CustomerLoginRestrictionPlugin's format checks.
 */
class PasskeySecurityHelper
{
    /** One-time WebAuthn challenge storage (creation or request options JSON). */
    private const CHALLENGE_CACHE_PREFIX = 'm2passkey_challenge_';

    /** Generous enough to survive a user fumbling with a security key. */
    private const CHALLENGE_TTL = 300;

    /** Ephemeral admin login bridge token, mirrors OAuthSecurityHelper::OIDC_AUTH_TOKEN_*. */
    private const PASSKEY_AUTH_TOKEN_PREFIX = 'm2passkey_authtoken_';

    private const PASSKEY_AUTH_TOKEN_TTL = 300;

    private const PASSKEY_AUTH_TOKEN_MARKER = 'PKEY_';

    /** Customer login handoff nonce, mirrors OAuthSecurityHelper::CUSTOMER_NONCE_CACHE_PREFIX. */
    private const CUSTOMER_NONCE_CACHE_PREFIX = 'm2passkey_custnonce_';

    private const CUSTOMER_NONCE_TTL = 300;

    public function __construct(
        private readonly AtomicCacheInterface $atomicCache
    ) {
    }

    /**
     * Derive a stable, opaque WebAuthn user handle for a Magento user.
     *
     * Deterministic (not random) so that every credential registered by the
     * same user carries the same user.id, as the spec recommends, without a
     * separate column to track it. Not derived from email/PII.
     *
     * @return string Raw 32 bytes (not base64url-encoded)
     */
    public function deriveUserHandle(string $userType, int $userId): string
    {
        return hash('sha256', $userType . ':' . $userId, true);
    }

    /**
     * Store a serialized PublicKeyCredentialCreationOptions/RequestOptions JSON
     * blob under a fresh one-time nonce, to be redeemed when the browser posts
     * its response back.
     */
    public function createChallengeNonce(string $serializedOptionsJson): string
    {
        $nonce = bin2hex(random_bytes(16));
        $cacheKey = self::CHALLENGE_CACHE_PREFIX . $nonce;
        $this->atomicCache->save($cacheKey, $serializedOptionsJson, self::CHALLENGE_TTL);
        return $nonce;
    }

    /**
     * Redeem (validate and consume) a challenge nonce. Returns the serialized
     * options JSON, or null if the nonce is invalid, expired, or already used.
     */
    public function redeemChallengeNonce(string $nonce): ?string
    {
        if ($nonce === '' || !preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            return null;
        }
        $stored = $this->atomicCache->getAndDelete(self::CHALLENGE_CACHE_PREFIX . $nonce);
        return in_array($stored, [null, ''], true) ? null : $stored;
    }

    /**
     * Mint a single-use ephemeral token binding an email to a verified passkey
     * assertion, for PasskeyCredentialAdapter::authenticate() to consume via
     * Auth::login($email, $token) — mirrors OAuthSecurityHelper::createOidcAuthToken().
     */
    public function createPasskeyAuthToken(string $email): string
    {
        $raw = bin2hex(random_bytes(32));
        $token = self::PASSKEY_AUTH_TOKEN_MARKER . $raw;

        $cacheKey = self::PASSKEY_AUTH_TOKEN_PREFIX . hash('sha256', $token);
        $this->atomicCache->save($cacheKey, $email, self::PASSKEY_AUTH_TOKEN_TTL);

        return $token;
    }

    /**
     * Non-consuming format check used by plugins to detect a passkey login
     * attempt without touching the cache.
     */
    public function isPasskeyAuthToken(string $password): bool
    {
        if (strlen($password) !== 69) {
            return false;
        }
        return str_starts_with($password, self::PASSKEY_AUTH_TOKEN_MARKER)
            && ctype_xdigit(substr($password, strlen(self::PASSKEY_AUTH_TOKEN_MARKER)));
    }

    /**
     * Validate a passkey auth token against the given email and consume it (one-time use).
     */
    public function validateAndConsumePasskeyAuthToken(string $email, string $token): bool
    {
        if (!$this->isPasskeyAuthToken($token)) {
            return false;
        }
        $cacheKey = self::PASSKEY_AUTH_TOKEN_PREFIX . hash('sha256', $token);
        $stored = $this->atomicCache->getAndDelete($cacheKey);

        return !in_array($stored, [null, ''], true) && hash_equals($stored, $email);
    }

    /**
     * Create the one-time nonce used to hand a verified customer passkey
     * login off to PasskeyCustomerCallback in a clean HTTP context — mirrors
     * OAuthSecurityHelper::createCustomerLoginNonce().
     */
    public function createCustomerPasskeyLoginNonce(string $email, string $relayState): string
    {
        $nonce = bin2hex(random_bytes(16));
        $cacheKey = self::CUSTOMER_NONCE_CACHE_PREFIX . $nonce;
        $data = json_encode(['email' => $email, 'relayState' => $relayState], JSON_THROW_ON_ERROR);
        $this->atomicCache->save($cacheKey, $data, self::CUSTOMER_NONCE_TTL);
        return $nonce;
    }

    /**
     * @return array{email: string, relayState: string}|null
     */
    public function redeemCustomerPasskeyLoginNonce(string $nonce): ?array
    {
        if ($nonce === '' || !preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            return null;
        }
        $data = $this->atomicCache->getAndDelete(self::CUSTOMER_NONCE_CACHE_PREFIX . $nonce);
        if (in_array($data, [null, ''], true)) {
            return null;
        }
        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || !isset($decoded['email'], $decoded['relayState'])) {
            return null;
        }
        return ['email' => (string) $decoded['email'], 'relayState' => (string) $decoded['relayState']];
    }
}
