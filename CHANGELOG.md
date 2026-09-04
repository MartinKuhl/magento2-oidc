# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.0.0] — 2026-07-13

First production release.

### Added

- OIDC/OAuth 2.0 SSO for both Magento admin and customer login, with native integration into Magento's authentication system (no password bypass hacks — standard auth events fire correctly).
- **Passkey (WebAuthn/FIDO2) login** for both admin and customer users, independent of any external IdP: self-service registration and passwordless sign-in via `web-auth/webauthn-lib`, bridged into Magento's native `Auth::login()` the same way OIDC is (a single-use ephemeral token, never a password). Customer login is usernameless/discoverable; admin login is email-scoped. Discoverable (resident-key) credentials only, ES256/RS256, `attestation=none`. Admins manage a global enable/disable toggle and Relying Party name/ID under **M2 OIDC > Passkey Settings**, including a cross-user "Registered Passkeys" grid for lockout recovery; end users manage their own passkeys from My Account (customers) or their user profile (admins). Credentials are automatically removed when the owning customer or admin account is deleted.
- Multi-provider support: configure any number of OIDC/OAuth providers, each with its own client credentials, endpoints, attribute mappings, and role/group mappings.
- PKCE (S256/plain), CSRF state tokens, replay-protected nonces, and JWT signature/issuer/audience verification via JWKS.
- RP-Initiated Logout, Back-Channel Logout, and Front-Channel Logout, with Authelia forward-auth compatibility.
- Headless / PWA login flow — delivers a customer token via `postMessage` instead of a session cookie, with a matching GraphQL API (`oidcLoginUrl`, `oidcProviders`).
- IdP-Initiated SSO (OIDC Third-Party Initiated Login).
- Claims-based access control rules engine (`eq`, `neq`, `contains`, `not_contains`, `exists`, `not_exists`).
- Just-in-time admin and customer account provisioning, with configurable role/group mapping, profile/address sync on every login, and per-user IdP binding (an account can't be hijacked by logging in through a different provider).
- Per-attribute claim transformers (`concat`, `split`, `prefix`, `regex_replace`).
- Automatic OIDC discovery document refresh (every 6 hours) and JWKS caching with a circuit breaker for unavailable IdPs.
- **Per-provider health-check webhook alerting**: configure a webhook URL (Slack, PagerDuty, or any HTTP endpoint) and a failure threshold per provider; after that many consecutive reachability-check failures (checked every 5 minutes), the module POSTs a JSON alert, and optionally a one-time recovery notification once the provider is reachable again. Alerts fire exactly once per outage — no spam while an outage continues. The webhook URL is encrypted at rest and SSRF-validated both on save and immediately before each send.
- CLI provider configuration export/import (`bin/magento oidc:config:export` / `oidc:config:import`), with client secrets and webhook URLs kept Magento-encrypted in the exported file.
- Rate limiting (fixed-window and Redis sliding-window strategies) on every unauthenticated OIDC endpoint.
- Admin UI: multi-provider management grid, per-provider settings tabs (Provider Settings, OAuth Settings, Attribute Mapping, Login Options), active-session listing with per-session unlink, and a live health-check diagnostic page.
- Per-provider post-logout landing page override, alongside the unified `/m2oidc/actions/postlogout` callback for IdPs that only allow one registered redirect URI.

### Security

- Client secrets and health-alert webhook URLs are encrypted at rest via Magento's `EncryptorInterface` and never appear in plaintext in exports.
- SSRF protection on every admin-configured outbound URL (discovery, endpoints, webhook alerting) — loopback and RFC-1918 private ranges are rejected.
- Lockout-prevention guard: an OIDC-only login restriction can't be enabled for a provider until at least one user has actually authenticated through it.
- Admin login nonces are bound to the originating OIDC provider, so a nonce minted under one provider's context cannot be redeemed under another.
- Passkey login uses its own ephemeral bridging token format (`PKEY_...`), structurally parallel to but distinct from the OIDC ephemeral token (`OIDC_...`), so the two authentication methods can never be confused for one another by the login-restriction plugins. WebAuthn challenges are one-time-use, cache-backed (not session/DB), and time-boxed to 5 minutes.
