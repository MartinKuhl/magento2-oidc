# M2Oidc OAuth/OIDC SSO Module — Technical Documentation

> **Module**: `M2Oidc_OAuth` — package `martinkuhl/magento2-oidc-sso` (`composer.json` version `1.0.0`; `etc/module.xml` `setup_version="1.0.0"`). These two files are the source of truth for the current version — check them directly rather than trusting a hardcoded number here, since they move independently of this document.
> **Requires**: PHP ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0 (per `composer.json`'s `require.php` constraint — the floor is PHP 8.2, not 8.1), Magento 2.4.7+, `web-auth/webauthn-lib` `^5.3` (Composer dependency, pulled in automatically — powers the Passkey login feature)

This document is a deep-dive technical reference for developers working on or extending this module. For a quick map of "what lives where," see [`CLAUDE.md`](../CLAUDE.md) at the module root — it's kept intentionally short; this file has the full depth.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Structure](#2-structure)
3. [Quick Start](#3-quick-start)
4. [Functionalities and Use Cases](#4-functionalities-and-use-cases)
5. [Gotchas](#5-gotchas)
6. [Future Improvements](#6-future-improvements)

---

## 1. Overview

### What it does

This Magento 2 module adds **OAuth 2.0 / OpenID Connect** single sign-on for both **Customer** (frontend) and **Admin** (backend) users. It replaces Magento's native login flow with an external Identity Provider (Authelia, Keycloak, Auth0, Zitadel, Azure AD, Okta, Google, etc.) while keeping all of Magento's built-in security events, ACL checks, and session handling intact — no core patches, no rewrites, just DI-configured plugins and observers.

### Why it exists

Out of the box, Magento has no OIDC support. Enterprises with a corporate identity provider, multi-store operators wanting shared customer identity, and B2B storefronts wanting zero-touch account provisioning all need this bridge. It respects Magento's plugin architecture end-to-end.

### Core capabilities

- **SP-initiated flow**: user starts at Magento and is redirected to the IdP.
- **IdP-initiated flow** (OIDC Third-Party Initiated Login §4): user starts at the IdP portal; the IdP redirects to `https://<store>/m2oidc/actions/idpInitiatedLogin?provider_id=<id>`. Enabled per provider via `idp_initiated_enabled`. Supports optional `relay_state`, `login_hint`, and `login_type` parameters. Rate-limited and PKCE-protected.
- **Customer flow**: creates or matches a Magento customer, creates a one-time nonce cookie, and redirects to `CustomerOidcCallback` (or `HeadlessOidcCallback` in headless mode) which sets the session and redirects to the relay state.
- **Admin flow**: uses Magento's native `Auth::login()` with a plugin-injected credential adapter — no bootstrap hacking, all security events fire normally. A nonce cookie bridges from the OIDC callback into the admin-authenticated context.
- **Token auto-refresh**: `TokenAutoRefreshObserver` (frontend) and `AdminTokenAutoRefreshObserver` (adminhtml) fire on every `controller_action_predispatch` and silently refresh the access token 60 seconds before expiry using the stored refresh token.
- **JIT provisioning**: auto-creates customers and admins on first login (configurable per provider).
- **Attribute mapping**: maps OIDC claims to Magento user fields (email, name, groups, address, DOB, gender, phone). Per-provider overrides take priority over global config. Legacy single-column mappings and a normalized `m2oidc_oauth_attribute_mappings` table both exist; the normalized table takes priority when populated.
- **Per-attribute claim transformers**: each row in `m2oidc_oauth_attribute_mappings` can specify a `transform_function` (`concat`, `split`, `prefix`, `regex_replace`) with JSON `transform_params`, applied by `Model/Attribute/Transformer.php` before the value is written to the Magento field. See [§4](#claim-value-transformers).
- **Auto-discovery**: if a `well_known_config_url` is configured, all OIDC endpoints (authorize, token, userinfo, JWKS, logout, revocation, issuer) are auto-populated from the provider's discovery document on save.
- **Group-to-role/group mapping**: maps OIDC groups to Magento admin roles or customer groups with a configurable fallback. Stored in a normalized `m2oidc_oauth_role_mappings` table.
- **Claims-Based Access Control**: evaluates per-provider JSON rules against OIDC claims before allowing login — can block access based on any claim value.
- **Profile sync**: optionally re-syncs profile, address, and group/role assignments on every SSO login, with a per-attribute `sync_on_sso` guard.
- **Identity verification bypass**: OIDC-authenticated admins skip the "enter your password" prompt when editing users/roles/account settings.
- **Password lifecycle suppression**: `OidcPasswordExpirationPlugin` and `OidcForcePasswordChangePlugin` prevent password expiry warnings and forced password-change redirects for OIDC-authenticated admins.
- **Per-user IdP binding**: the `m2oidc_oauth_user_provider` table is read during authentication, not just written. `ProcessUserAction` (customer flow) and `CheckAttributeMappingAction` (admin flow) call `UserProviderResource::getBoundProviderId()` on every login. If a binding exists and does not match the current provider, the login is rejected with `OAuthMessages::PROVIDER_MISMATCH`. If no binding exists (pre-OIDC account), the first IdP to authenticate the user claims the binding immediately.
- **Login restriction**: optionally blocks all non-OIDC admin or customer logins, per provider. Protected by a lockout-prevention guard that reverts the setting if no OIDC users exist yet for that provider.
- **User-delete cleanup**: `AdminUserDeleteObserver`/`AdminUserDeletePlugin` and `CustomerDeleteObserver` remove OIDC provider mappings from `m2oidc_oauth_user_provider` when Magento users are deleted.
- **Admin-side provider unlink**: `Controller/Adminhtml/Provider/UnlinkUser.php` lets an admin unlink a customer's or admin's bound provider from the Customer Edit page / Admin User Edit page, so the next OIDC login can claim a new binding. (There is no equivalent *customer self-service* unlink yet — see [§6](#6-future-improvements).)
- **Dirty-field tracking**: `view/adminhtml/web/js/dirtyTracking.js` highlights modified provider form fields with an amber border before save.
- **PKCE (RFC 7636)**: supports S256 and plain code challenge methods. The code verifier is stored in the shared atomic cache (not the database, not the PHP session), keyed by a nonce carried in a cookie — see [§2 Database Tables](#database-tables) and [Gotcha #12](#5-gotchas).
- **Rate limiting**: `OidcRateLimiter` enforces a fixed-window IP-based rate limit (10 attempts / 60 s) on the customer callback, admin callback, back-channel logout, front-channel logout, IdP-initiated login, and headless callback endpoints.
- **CSRF protection**: a token is embedded in the OAuth `state` parameter, generated on authorization request and validated on callback.
- **RP-Initiated Logout**: on admin logout, `OidcLogoutPlugin` captures session tokens before destruction, calls the IdP's `end_session_endpoint`, and optionally revokes the access token via RFC 7009. Supports both standard OIDC and Authelia Forward-Auth logout modes. When the IdP only allows registering a single Post Logout Redirect URI, both admin and customer flows are routed through a unified callback (`m2oidc/actions/postlogout`, controller class `Postlogout`) that uses a context-prefix in the `state` parameter to determine the final redirect destination.
- **Back-Channel Logout**: `POST /m2oidc/actions/backchannellogout` accepts a signed JWT logout token from the IdP and destroys the matching PHP session via `OidcSessionRegistry` + `SessionDestructionService`. The `aud` claim is supported in both string and array formats per the OIDC spec. The audience check fails closed (HTTP 400 + ERROR log) when the resolved provider's `clientID` is empty.
- **Front-Channel Logout**: `GET /m2oidc/actions/frontchannellogout?sid=<sid>` — for IdPs (Entra, some Keycloak configs) that perform logout via a hidden `<iframe>` per service provider instead of a server-to-server POST. Shares session-destruction logic with Back-Channel Logout via `SessionDestructionService`; always returns a 1×1 transparent GIF so the iframe gets a valid image response.
- **Headless / PWA login**: per-provider `headless_mode` flag. When enabled, `HeadlessOidcCallback` issues a Magento customer token and delivers it to the calling PWA via `window.postMessage` instead of a session cookie — see [§4](#headless--pwa-login).
- **Per-provider log isolation**: an optional `log_file_suffix` column routes a given provider's log lines to `var/log/M2Oidc_<suffix>.log` instead of the shared `var/log/M2Oidc.log`, useful when running many providers and wanting to debug one in isolation.
- **Hybrid flow nonce validation**: `ReadAuthorizationResponse` validates the nonce in the `id_token` from the token endpoint even when user data is sourced from the userinfo endpoint, preventing replay attacks in hybrid flows.
- **Debug log cleanup**: a dedicated cron job (`Cron/LogCleanup.php`, registered as `m2oidc_log_rotation`, scheduled `0 3 * * *`) deletes `var/log/M2Oidc.log` (and any `M2Oidc_*.log` provider-suffixed files) and disables debug logging when the log exceeds 7 days or when debug logging has been disabled in the admin UI.
- **OIDC discovery auto-refresh**: `Cron/RefreshOidcDiscovery.php` (registered as `m2oidc_refresh_oidc_discovery`, schedule `0 */6 * * *`) re-fetches `.well-known/openid-configuration` for every active provider every 6 hours and dirty-checks before writing.
- **Structured logging service**: `Logger/OidcLogger.php` is the dedicated logging service extracted from `OAuthUtility`. Supports dual format: legacy Monolog envelope (default) and true JSON Lines (`{"ts":"...","level":"debug","message":"..."}`) controlled by `oidc/logging/json_lines` config. Automatically masks sensitive fields (`client_secret`, `access_token`, `id_token`, `refresh_token`, `password`, `token`).
- **Layered service architecture**: `OAuthUtility` is a thin facade — `Logger/OidcLogger` handles all log output, `Model/Provider/ProviderResolver` handles per-request provider context and resolution, and `Model/Config/OidcConfigReader` maps `OAuthConstants` keys to `m2oidc_oauth_client_apps` columns. `Helper/Data` delegates DB operations to `Model/ResourceModel/OidcProviderRepository`, and `OAuthUtility` inherits those methods directly. `Block/OAuth.php` and the provider-listing logic in `ProviderResolver` (which delegates to `OidcProviderRepository::getAllActiveProviders()`) follow the same delegation pattern.
- **Shared validation layer**: `Model/Validation/SsrfUrlValidator.php` (loopback/RFC-1918 host blocking) and `Model/Validation/ProviderDataValidator.php` (+ `ProviderValidationResult.php`) centralize the whitelisting, SSRF checks, and lockout-prevention guard, enforced consistently across `Provider/Save.php`, both config-import paths (CLI and admin UI), and the discovery-refresh cron.
- **DTO-based controller invocation**: `Controller/Actions/CheckAttributeMappingAction.php` and `Controller/Actions/ProcessUserAction.php` expose `handle(DtoType $context): ResultInterface`, taking an immutable `Model/Data/OidcAttributeMappingContext` or `Model/Data/OidcUserProvisioningContext`. See [§2 Key Classes Reference](#key-classes-reference) and [Gotcha #13](#5-gotchas).
- **First data patch**: `Setup/Patch/Data/EncryptPlaintextClientSecrets.php` encrypts legacy plaintext `client_secret` values and backfills empty `login_type` to `'both'` on `setup:upgrade`.
- **Atomic token consumption**: `Model/Cache/AtomicCacheInterface` (`save()` / `getAndDelete()`) eliminates the TOCTOU race condition in one-time token consumption (nonces, state tokens, PKCE verifiers, ephemeral auth tokens). Default implementation: `RedisAtomicCache` — opens its own dedicated Redis connection from `cache/frontend/default/backend_options` via `RedisConnectionFactory` and uses `GETDEL`/Lua for true atomicity; transparently falls back to sequential load + remove (`FileAtomicCache`-equivalent behavior) when that connection is unavailable.
- **Per-provider attribute mapper overrides**: `Model/Attribute/MapperPool` is a DI-registered registry that resolves the correct `AttributeMapperInterface` for a given provider and type via `{providerId}_{type}` keys in `etc/di.xml`.
- **Rate-limiter strategy pattern**: `OidcRateLimiter` is a thin facade delegating to an injected `StrategyInterface`. Default: `FixedWindowStrategy`. DI virtual type `OidcSlidingWindowRateLimiter` provides a true sliding-window implementation via `SlidingWindowStrategy` (Lua-based on Redis) for deployments that need burst tolerance.
- **PHP 8 serialization for `OidcCredentialAdapter`**: `__serialize()`/`__unserialize()` replace `__sleep()`/`__wakeup()` — dependencies are restored eagerly on unserialize.
- **Multi-provider**: database schema and utility layer support multiple active providers with per-provider settings, managed via the Provider grid at `/admin/m2oidc/provider/index` and the Provider Settings page at `/admin/m2oidc/providersettings/index`.
- **Session Activity view**: `/admin/m2oidc/sessions/index` lists all users who authenticated via OIDC, with total and active user counts per provider. Individual session records can be deleted via `Sessions/Delete.php` (POST, with confirmation dialog).
- **Zitadel support**: `OidcAuthenticationService` handles Zitadel-specific claim encoding (`claim_encoding = base64`) and nested role objects (`{"role_name": {"orgId": ...}}`), normalizing them into the standard flat group list.
- **Public client support**: `AccessTokenRequest` omits `client_secret` when `public_client` is enabled, supporting RFC 6749 §2.1 public clients (e.g., Zitadel PKCE apps without a secret).
- **GraphQL support**: `oidcLoginUrl` and `oidcProviders` queries (`Model/Resolver/`) for headless/Hyvä frontends that need provider data without server-rendered `.phtml` buttons.
- **CLI tools**: `bin/magento oidc:config:export` and `bin/magento oidc:config:import` for moving provider configuration (including normalized attribute/role mappings) across environments.
- **Health check**: `GET /m2oidc/health/check` checks per-provider configuration completeness and `is_active` status and returns JSON — it does not make an outbound network call to the IdP itself.
- **Passkey (WebAuthn/FIDO2) login**: an independent, non-OIDC passwordless login method for both admin and customer users, backed by `web-auth/webauthn-lib` (`^5.3`). Self-service registration and login, with global enable/disable toggles and Relying Party name/ID configured at **M2 OIDC > Passkey Settings** (`/admin/m2oidc/passkeysettings/index`, `core_config_data` paths under `m2oidc_passkey/general/*` — not `system.xml`). A verified WebAuthn assertion is bridged into Magento's native `Auth::login()` via a single-use, cache-backed ephemeral token (`PKEY_`-prefixed, 300s TTL), exactly like the OIDC admin flow, but with a distinct marker so the two methods never collide. Customer login is usernameless/discoverable (empty `allowCredentials`); admin login is email-scoped. Registration requires a discoverable (resident-key) credential using ES256/RS256, with `attestation=none`. See [§4 Passwordless Login (Passkeys)](#passwordless-login-passkeys) for the full flow and [Gotcha #33](#5-gotchas) onward for passkey-specific caveats.

---

## 2. Structure

### Directory tree

```
M2Oidc_OAuth/
├── registration.php                          # Registers module with Magento
├── composer.json                             # Package metadata (martinkuhl/magento2-oidc-sso)
├── etc/
│   ├── module.xml                            # Module declaration (setup_version)
│   ├── di.xml                                # DI config: plugins, constructor args
│   ├── db_schema.xml                         # DB tables (4 tables — see below)
│   ├── db_schema_whitelist.json              # Declarative schema whitelist
│   ├── acl.xml                                # ACL resources for admin pages
│   ├── events.xml                            # Global event observers (customer_delete_after, admin_user_delete_after, customer-login-redirect, plus stub declarations for the custom oidc_* events)
│   ├── schema.graphqls                       # GraphQL type/query definitions
│   ├── crontab.xml                           # Cron job registrations (m2oidc_log_rotation daily 03:00; m2oidc_refresh_oidc_discovery every 6h)
│   ├── csp_whitelist.xml                     # Content Security Policy whitelist
│   ├── frontend/
│   │   ├── routes.xml                        # Frontend route: m2oidc
│   │   └── events.xml                        # Frontend event observers
│   └── adminhtml/
│       ├── routes.xml                        # Admin route: m2oidc
│       ├── events.xml                        # Admin event observers
│       ├── menu.xml                          # Admin menu entries
│       └── csp_whitelist.xml                 # Admin CSP whitelist
│
├── Cron/
│   ├── LogCleanup.php                        # Daily cron (03:00); deletes var/log/M2Oidc*.log when >7 days old or logging disabled
│   └── RefreshOidcDiscovery.php              # Every 6h; re-fetches .well-known for all active providers; dirty-checks before writing to DB; endpoint validated via Model/Validation/SsrfUrlValidator
│
├── Console/
│   └── Command/
│       ├── ExportOidcConfig.php              # CLI: bin/magento oidc:config:export; EXCLUDED_FIELDS strips received_oidc_claims/last_test_status/last_test_at
│       └── ImportOidcConfig.php              # CLI: bin/magento oidc:config:import; imported data validated via Model/Validation/ProviderDataValidator
│
├── Setup/
│   └── Patch/
│       └── Data/
│           └── EncryptPlaintextClientSecrets.php  # First data patch; encrypts legacy plaintext client_secret, backfills login_type='' -> 'both'
│
├── Controller/
│   ├── Health/
│   │   └── Check.php                         # GET /m2oidc/health/check — config completeness + is_active, no outbound IdP call
│   ├── Passkey/
│   │   └── Index.php                         # GET /m2oidc/passkey/index — customer "My Account > Passkeys" page; implements AccountInterface (auto-redirects anonymous visitors)
│   ├── Actions/Passkey/                      # Frontend passkey AJAX endpoints (customer self-service + anonymous login)
│   │   ├── RegistrationOptions.php           # POST .../actions_passkey/registrationoptions — logged-in customer only; builds creation options
│   │   ├── RegistrationVerify.php            # POST .../actions_passkey/registrationverify — verifies attestation, persists credential
│   │   ├── Delete.php                        # POST .../actions_passkey/delete — self-service delete, scoped to the logged-in customer
│   │   ├── LoginOptions.php                  # POST .../actions_passkey/loginoptions — anonymous; empty allowCredentials (usernameless/discoverable)
│   │   ├── LoginVerify.php                   # POST .../actions_passkey/loginverify — verifies assertion, mints customer passkey login nonce, sets m2passkey_customer_nonce cookie (120s)
│   │   └── PasskeyCustomerCallback.php       # Clean-HTTP-context handoff target; redeems nonce, calls CustomerSession::setCustomerAsLoggedIn()
│   ├── Actions/                              # Frontend controllers (OAuth flow)
│   │   ├── BaseAction.php                    # Base class for frontend actions
│   │   ├── BaseAdminAction.php               # Base class for admin config pages
│   │   ├── SendAuthorizationRequest.php      # Step 1: Redirect to IDP (customer); generates PKCE verifier (atomic cache, cookie nonce)
│   │   ├── ReadAuthorizationResponse.php     # Step 2: Receive auth code, exchange for tokens, verify JWT; rate-limited; builds OidcAttributeMappingContext DTO, calls CheckAttributeMappingAction::handle()
│   │   ├── CheckAttributeMappingAction.php   # Step 3: Evaluate access rules, route admin vs customer, map attributes; entry point is handle(OidcAttributeMappingContext) — execute() is a dead stub (Gotcha #13)
│   │   ├── ProcessUserAction.php             # Step 4: Create/match customer, validate relay state, delegate login; entry point is handle(OidcUserProvisioningContext) — a plain DI collaborator, no execute()
│   │   ├── CustomerLoginAction.php           # Step 5: Create customer/headless nonce cookie, redirect to CustomerOidcCallback or HeadlessOidcCallback
│   │   ├── CustomerOidcCallback.php          # Step 6: Redeem nonce, validate website, set customer session, redirect
│   │   ├── HeadlessOidcCallback.php          # Headless/PWA callback: redeems headless nonce, issues a customer token, postMessage's it to the opener
│   │   ├── IdpInitiatedLogin.php             # IdP-Initiated SSO entry point (OIDC §4); rate-limited, PKCE, CSRF state
│   │   ├── Postlogout.php                    # Unified post-logout redirect (class `Postlogout`); reads state prefix (admin:|customer:) and redirects accordingly
│   │   ├── ShowTestResults.php               # Test Configuration results display; test mode detected from relay state URL (TEST_RELAYSTATE constant)
│   │   ├── BackChannelLogout.php             # POST /m2oidc/actions/backchannellogout — server-to-server logout via signed JWT logout token
│   │   └── FrontChannelLogout.php            # GET /m2oidc/actions/frontchannellogout — iframe-based logout, returns a 1×1 GIF
│   └── Adminhtml/
│       ├── Actions/
│       │   ├── SendAuthorizationRequest.php  # Step 1: Redirect to IDP (admin); stamps loginType=admin
│       │   ├── Oidccallback.php              # Admin login: redeems nonce, calls Auth::login() with ephemeral OIDC marker
│       │   ├── HealthCheck.php               # Admin AJAX health-check endpoint with configuration diagnostics
│       │   └── Passkey/                      # Admin passkey AJAX endpoints (self-service + anonymous login)
│       │       ├── RegistrationOptions.php   # Already-authenticated admin only; ADMIN_RESOURCE = Magento_Backend::admin
│       │       ├── RegistrationVerify.php    # Verifies attestation, persists credential against the current admin
│       │       ├── Delete.php                # Self-service delete, scoped to the authenticated admin's own credentials
│       │       ├── LoginOptions.php          # Anonymous, does NOT extend Backend\App\Action (reachable pre-auth); email-scoped allowCredentials when a matching admin is found
│       │       └── LoginVerify.php           # Anonymous; verifies assertion, mints PKEY_ token, calls Auth::login(); sets is_passkey_authenticated auth-storage flag + passkey_authenticated cookie
│       ├── OAuthsettings/Index.php           # Admin page: OAuth Settings; encrypts client_secret via EncryptorInterface before save; ADMIN_RESOURCE const
│       ├── Attrsettings/Index.php            # Admin page: Attribute Mapping; ADMIN_RESOURCE const
│       ├── Signinsettings/Index.php          # Admin page: Sign In Settings; import/export paths run through ProviderDataValidator / EXPORT_EXCLUDED_FIELDS; ADMIN_RESOURCE const
│       ├── Providersettings/Index.php        # Admin page: Provider Settings (display_name, login_type, is_active, sort_order, button_label, button_color); ADMIN_RESOURCE const
│       ├── Passkeysettings/
│       │   ├── Index.php                     # Admin page: Passkey Settings (/admin/m2oidc/passkeysettings/index); toggles + RP name/ID form + cross-user Registered Passkeys grid; ADMIN_RESOURCE = M2Oidc_OAuth::passkey_settings
│       │   ├── Save.php                      # Writes enabled_admin/enabled_customer/rp_name/rp_id to core_config_data, cleans config cache
│       │   └── Delete.php                    # POST: lockout-recovery delete of ANY user's credential by row ID (ACL-gated) — distinct from the self-service Delete controllers above
│       ├── Provider/
│       │   ├── Index.php                     # Provider management grid
│       │   ├── Edit.php                      # Provider edit form
│       │   ├── Save.php                      # Provider CRUD save; validates required attr fields; runs lockout-prevention guard; auto-discovery fetch
│       │   ├── Delete.php                    # Provider delete
│       │   └── UnlinkUser.php                # POST: unlinks a customer's or admin's bound provider (admin-side self-service)
│       └── Sessions/
│           ├── Index.php                     # Active OIDC sessions grid
│           └── Delete.php                    # POST: deletes individual session activity record by ID; requires M2Oidc_OAuth::oidc_sessions
│
├── Model/
│   ├── Auth/
│   │   ├── OidcCredentialAdapter.php         # StorageInterface impl for OIDC auth; PHP 8 __serialize()/__unserialize() (eager restoration)
│   │   └── PasskeyCredentialAdapter.php      # StorageInterface impl for passkey auth; structurally identical to OidcCredentialAdapter but validates a PKEY_ token via PasskeySecurityHelper instead of an OIDC one, and dispatches auth events with a passkey_auth marker
│   ├── Attribute/
│   │   ├── AttributeMapperInterface.php      # Shared mapper interface
│   │   ├── AdminAttributeMapper.php          # Maps OIDC claims → admin user fields
│   │   ├── CustomerAttributeMapper.php       # Maps OIDC claims → customer fields + address; gender/country resolution delegate to GenderMapper/CountryResolver below
│   │   ├── GenderMapper.php                  # Unified gender recognizer (incl. German words) shared by CustomerAttributeMapper and CustomerProfileSyncService — fixes a live re-sync bug
│   │   ├── CountryResolver.php               # Unified country name/code resolution (ISO passthrough -> filtered query -> intl locale match), memoized
│   │   ├── MapperPool.php                    # DI registry for per-provider mapper overrides ({providerId}_{type} keys)
│   │   └── Transformer.php                   # Applies concat/split/prefix/regex_replace transforms per attribute row; regex_replace length-capped at 4096 bytes
│   ├── Passkey/
│   │   ├── WebauthnCeremonyFactory.php       # Single seam constructing every web-auth/webauthn-lib object (serializer, RP entity, ES256/RS256 params, ceremony step managers), configured from PasskeyConfig; attestation conveyance is always 'none'
│   │   ├── PasskeyRegistrationService.php    # Builds PublicKeyCredentialCreationOptions and verifies+persists the resulting attestation; shared by admin and customer self-service registration controllers
│   │   ├── PasskeyAuthenticationService.php  # Builds PublicKeyCredentialRequestOptions and verifies the browser's assertion, bumping the stored credential's signature counter; shared by admin and customer login controllers
│   │   └── StoredCredential.php              # Readonly DTO pairing a m2oidc_passkey_credentials row (dbId, userType, userId, nickname, createdAt) with the deserialized Webauthn\CredentialRecord
│   ├── PasskeyCredential.php + PasskeyCredentialFactory.php  # ORM model/factory for m2oidc_passkey_credentials
│   ├── Cache/
│   │   ├── AtomicCacheInterface.php          # save() / getAndDelete() — atomic write and read-and-delete
│   │   ├── FileAtomicCache.php               # Sequential load+remove; single-server safe
│   │   ├── RedisAtomicCache.php              # GETDEL/Lua for true atomicity via a dedicated Redis connection (DI default)
│   │   └── RedisConnectionFactory.php        # Opens a raw Redis connection from env.php cache config
│   ├── Config/
│   │   └── OidcConfigReader.php              # Extracted from OAuthUtility — config key → DB column mappings
│   ├── Data/
│   │   ├── OidcAttributeMappingContext.php   # Immutable input for CheckAttributeMappingAction::handle(); replaces the former public setter chain
│   │   └── OidcUserProvisioningContext.php   # Immutable input for ProcessUserAction::handle(); replaces the former public setter chain
│   ├── Provider/
│   │   ├── MappingRepository.php             # Repository for normalized attribute/role mapping tables (incl. transform_function/transform_params)
│   │   └── ProviderResolver.php              # Per-request provider context; setActiveProviderId() guards against caching 0/negative IDs; no-explicit-ID fallback delegates to OidcProviderRepository::getAllActiveProviders()
│   ├── Security/
│   │   ├── OidcRateLimiter.php               # Thin facade delegating to StrategyInterface
│   │   └── RateLimiterStrategy/
│   │       ├── StrategyInterface.php         # isAllowed(string $ip): bool
│   │       ├── FixedWindowStrategy.php       # Default fixed-window (all backends); declares MAX_ATTEMPTS/WINDOW_SECONDS
│   │       └── SlidingWindowStrategy.php     # Sliding-window (Redis Lua); declares its own MAX_ATTEMPTS/WINDOW_SECONDS
│   ├── Validation/
│   │   ├── SsrfUrlValidator.php              # Shared loopback/RFC-1918 host blocking for endpoint URLs; used by Provider/Save.php, OAuthsettings, RefreshOidcDiscovery cron, ProviderDataValidator
│   │   ├── ProviderDataValidator.php         # Shared enum whitelisting + SSRF checks + lockout-prevention guard; used by Provider/Save.php, ImportOidcConfig, Signinsettings import path
│   │   └── ProviderValidationResult.php      # Value object returned by ProviderDataValidator
│   ├── Service/
│   │   ├── AdminUserCreator.php              # JIT admin provisioning; role resolution delegates to GroupMappingResolver; password via RandomPasswordGenerator
│   │   ├── AdminProfileSyncService.php       # Syncs admin profile and role from OIDC claims on login; role resolution delegates to GroupMappingResolver
│   │   ├── AbstractTokenRefreshService.php   # Shared token-refresh base class; TokenRefreshService/AdminTokenRefreshService extend it, keeping their own names/public constants
│   │   ├── AdminTokenRefreshService.php      # Silent access-token refresh for admin AuthSession; thin subclass of AbstractTokenRefreshService
│   │   ├── CustomerUserCreator.php           # JIT customer provisioning + address/group creation; group resolution delegates to GroupMappingResolver; password via RandomPasswordGenerator; attribute-lookup init collapsed into a config-map loop
│   │   ├── CustomerProfileSyncService.php    # Syncs customer profile and address from OIDC claims on login; gender/country resolution delegate to Model/Attribute/GenderMapper + CountryResolver
│   │   ├── GroupMappingResolver.php          # Shared group/role mapping fallback chain (normalized table -> legacy JSON -> case-insensitive match -> default -> deny); used by AdminUserCreator, CustomerUserCreator, AdminProfileSyncService
│   │   ├── RandomPasswordGenerator.php       # Shared random-password generation for JIT-provisioned accounts, named constants
│   │   ├── RpInitiatedLogoutService.php      # Shared RP-Initiated Logout logic (session-agnostic); used by OidcLogoutPlugin (admin) and OAuthLogoutObserver (customer)
│   │   ├── OidcAuthenticationService.php     # Core OIDC response processor: validates, flattens, extracts email/type/groups; Zitadel Base64 + nested role normalization; MAX_FLATTENED_KEYS=2000 ceiling
│   │   ├── SessionDestructionService.php     # Shared session-destruction logic used by BackChannelLogout and FrontChannelLogout
│   │   ├── TokenRefreshService.php           # Silent access-token refresh for customer session; thin subclass of AbstractTokenRefreshService
│   │   ├── OidcSessionRegistry.php           # Tracks sub/sid → PHP session ID for back-/front-channel logout; buildKey() hashes sub/sid independently before combining (clean-cut — see Gotcha)
│   │   └── UserProvisioningService.php       # Orchestrates admin/customer provisioning; fires oidc_{admin,customer}_{before,after}_create
│   ├── Resolver/                             # GraphQL resolvers (OidcLoginUrl.php, OidcProviders.php) — always registered; schema.graphqls is merged by Magento's own framework only if Magento_GraphQl happens to be enabled
│   ├── M2oidcOauthClientApps.php             # Model for m2oidc_oauth_client_apps table
│   └── ResourceModel/
│       ├── OidcProviderRepository.php        # All DB ops on m2oidc_oauth_client_apps; extracted from Data.php; decrypt failures logged as WARNING; getAllActiveProviders() treats login_type='' as matching any type
│       ├── UserProvider.php                  # Tracks provider → Magento user binding
│       ├── OauthAttributeMapping.php         # Resource model for m2oidc_oauth_attribute_mappings
│       ├── OauthRoleMapping.php              # Resource model for m2oidc_oauth_role_mappings
│       ├── PasskeyCredential.php + PasskeyCredentialRepository.php  # Resource model + app-level repository for m2oidc_passkey_credentials; the repository is the sole seam between webauthn-lib's Webauthn\CredentialRecord and DB storage
│       ├── PasskeyCredential/Collection.php + CollectionFactory.php # Collection backing the Registered Passkeys admin grid and per-user credential lookups
│       └── M2OidcOauthClientApps/
│           ├── Collection.php                # Collection model
│           └── (ResourceModel).php           # Resource model
│
├── Plugin/
│   ├── AdminLoginRestrictionPlugin.php       # Blocks non-OIDC admin login (with safety net)
│   ├── CustomerLoginRestrictionPlugin.php    # Blocks non-OIDC customer login when restriction is enabled
│   ├── Auth/
│   │   ├── OidcCredentialPlugin.php          # Injects OIDC adapter; resets guard flags on every login
│   │   ├── PasskeyCredentialPlugin.php       # Injects PasskeyCredentialAdapter when a PKEY_ token is detected; structurally identical to OidcCredentialPlugin, distinct token format keeps the two methods from colliding
│   │   └── OidcLogoutPlugin.php              # Token revocation + RP-Initiated Logout (aroundLogout) — NOT an event observer; delegates to Model/Service/RpInitiatedLogoutService
│   ├── Captcha/
│   │   ├── OidcCaptchaBypassPlugin.php       # Skips admin CAPTCHA for OIDC logins
│   │   └── CustomerCaptchaBypassPlugin.php   # Skips customer CAPTCHA for OIDC logins
│   ├── Csp/
│   │   └── OidcCspPolicyCollector.php        # Adds IdP domains to Content Security Policy whitelist dynamically
│   ├── Customer/
│   │   └── Block/
│   │       └── OidcInfoPlugin.php            # Injects OIDC provider info into the ADMIN customer-edit page (Magento\Customer\Block\Adminhtml\Edit\Tab\View) — not a frontend/customer-facing block
│   └── User/
│       ├── AdminUserDeletePlugin.php           # afterDelete on Magento\User\Model\User; removes m2oidc_oauth_user_provider row; belt-and-suspenders alongside AdminUserDeleteObserver
│       ├── OidcIdentityVerificationPlugin.php  # Bypasses password re-verification (on Magento\User\Model\User)
│       ├── OidcPasswordExpirationPlugin.php    # Suppresses password expiry warnings (on Magento\User\Observer\Backend\AuthObserver, NOT Model\User)
│       ├── OidcForcePasswordChangePlugin.php   # Suppresses forced password change (on Magento\User\Observer\Backend\ForceAdminPasswordChangeObserver, NOT Model\User)
│       └── Block/
│           ├── OidcIdentityFieldPlugin.php     # Removes required from password field in User/Role/Account forms
│           ├── OidcUserInfoPlugin.php          # Injects OIDC info into admin user profile page
│           └── PasskeyUserInfoPlugin.php       # Injects the passkey registration/management UI into the admin User Edit / My Account form; also feeds getIsPasskeyAuthenticated() to the password-flow bypass plugins above
│
├── Helper/
│   ├── Data.php                              # Base config; DB ops delegated to OidcProviderRepository
│   ├── OAuthUtility.php                      # Thin facade: delegates logging→OidcLogger, provider→ProviderResolver, config→OidcConfigReader; inherits Data's DB-write methods; isBlank() does not treat "0" as blank
│   ├── OAuthConstants.php                    # All constants (config keys, defaults, URLs); single VERSION constant (PLUGIN_VERSION and the dead PKCE_VERIFIER_SESSION_KEY constant were removed)
│   ├── OAuthMessages.php                     # User-facing message templates
│   ├── OAuthSecurityHelper.php               # PKCE (cache-based), state tokens, nonces, relay state, all via AtomicCacheInterface
│   ├── PasskeyConfig.php                     # Global (non-provider-scoped) passkey config: enabled_admin/enabled_customer/rp_name/rp_id under m2oidc_passkey/general/*; also derives the WebAuthn origin from the store base URL
│   ├── PasskeySecurityHelper.php             # Passkey security primitives: challenge nonces, PKEY_ ephemeral auth tokens, customer login handoff nonces — all AtomicCacheInterface-backed, structurally parallel to OAuthSecurityHelper but with distinct 'PKEY_' markers
│   ├── SessionHelper.php                     # SameSite=None cookie handling (OIDC routes only)
│   ├── Curl.php                              # HTTP client for token/userinfo requests
│   ├── JwtVerifier.php                       # JWT signature validation using JWKS (cached, circuit-breaker on repeated failure)
│   ├── TestResults.php                       # Test configuration HTML output
│   ├── OAuth/
│   │   ├── AuthorizationRequest.php          # Builds the authorize URL query string (includes PKCE challenge)
│   │   ├── AccessTokenRequest.php            # Builds the token exchange POST body; PKCE code_verifier support; omits client_secret for public clients
│   │   └── AccessTokenRequestBody.php        # Alternate token body (header auth variant)
│   └── Exception/                            # Custom exceptions
│       ├── IncorrectUserInfoDataException.php
│       ├── MissingAttributesException.php
│       ├── NotRegisteredException.php
│       ├── RequiredFieldsException.php
│       └── SupportQueryRequiredFieldsException.php
│
├── Ui/
│   ├── Component/DataProvider.php            # Provider grid data provider
│   ├── Component/DataProvider/SessionDataProvider.php  # Active sessions grid data provider
│   ├── Component/DataProvider/PasskeyCredentialDataProvider.php  # Registered Passkeys grid data provider (Passkey Settings page); collection-backed, no cross-table joins
│   └── Component/Listing/Column/
│       ├── Actions.php                       # Provider grid row actions
│       ├── ActiveUserCount.php               # "total (active)" user count per provider
│       ├── OnlineStatus.php                  # Shows providers with active sessions
│       ├── PkceStatus.php                    # PKCE configuration status badge
│       ├── JwksStatus.php                    # JWKS endpoint status badge
│       ├── TestStatusOptions.php             # Test result status badge
│       ├── ActiveStatus.php                  # Colored Active/Inactive badge; reads is_active from collection — no extra DB query
│       ├── SessionActions.php                # "Delete" action link per row in the session activity grid
│       └── PasskeyActions.php                # "Delete" action link per row in the Registered Passkeys grid — posts to the ACL-gated Passkeysettings/Delete.php (any-user delete), not the self-service one
│
├── ViewModel/
│   └── OidcLoginVisibility.php               # Determines OIDC login-button visibility for BOTH admin and customer login pages, per active provider
│
├── Block/
│   ├── OAuth.php                             # Admin/customer template block; cut from ~76 to 26 public methods (removed a dead legacy single-global-provider API surface); resolveButtonColor()/resolveButtonLabel() helpers shared by both SSO-button templates
│   ├── Passkey.php                           # "Login with Passkey" button block, reused on both the admin login page and the customer login page (getUrl() resolves to the right area automatically)
│   ├── Passkey/
│   │   └── ManagePasskeys.php                # Customer "My Account > Passkeys" self-service list/register/delete block
│   └── Adminhtml/
│       ├── OidcErrorMessage.php              # OIDC error display block
│       ├── Passkeysettings/
│       │   └── Index.php                     # Passkey Settings page block: form field accessors + Registered Passkeys grid URLs
│       └── Provider/
│           └── Edit/
│               ├── Tabs.php                  # Tab container for the provider edit form
│               └── Tab/
│                   ├── AttributeMapping.php  # Dynamic attribute-mapping rows tab
│                   ├── LoginOptions.php      # hasOidcAdminUsers()/hasOidcCustomerUsers() lockout-guard warnings
│                   ├── OAuthSettings.php     # OAuth Settings tab
│                   └── ProviderSettings.php  # Provider identity fields tab
│
├── Observer/
│   ├── TestConfigRequestObserver.php         # Detects a test-config request and renders test results inline; registered on controller_action_predispatch in both frontend and adminhtml events.xml
│   ├── OAuthLogoutObserver.php               # Redirects to IDP logout URL; bound to controller_action_postdispatch_customer_account_logout (NOT customer_logout); delegates to RpInitiatedLogoutService
│   ├── CustomerLoginAutoRedirectObserver.php # Bound to controller_action_predispatch_customer_account_login; respects oidc_logout_guard
│   ├── CustomerSetLogoutFlagObserver.php     # Bound to customer_logout; sets logout flag on customer session destruction
│   ├── AdminLoginAutoRedirectObserver.php    # Bound to controller_action_predispatch_adminhtml_auth_login; respects oidc_logout_guard
│   ├── AdminSetLogoutFlagObserver.php        # Bound to adminhtml_user_logout; sets logout flag on admin session destruction
│   ├── TokenAutoRefreshObserver.php          # Bound to controller_action_predispatch (frontend)
│   ├── AdminTokenAutoRefreshObserver.php     # Bound to controller_action_predispatch (adminhtml)
│   ├── AdminUserDeleteObserver.php           # Bound to admin_user_delete_after (global); removes OIDC user mapping AND all passkey credentials for the deleted admin
│   └── CustomerDeleteObserver.php            # Bound to customer_delete_after (global); removes OIDC user mapping AND all passkey credentials for the deleted customer
│
├── Logger/
│   ├── Logger.php                            # Custom Monolog logger
│   ├── Handler.php                           # Writes to var/log/M2Oidc.log
│   └── OidcLogger.php                        # Structured logging service; JSON Lines mode; sensitive-field masking; per-provider M2Oidc_<suffix>.log handlers
│
└── view/
    ├── adminhtml/
    │   ├── layout/                           # Admin layout XML files (m2oidc_* prefix); adminhtml_auth_login.xml injects the passkey login button, m2oidc_passkeysettings_index.xml renders the Passkey Settings page
    │   ├── templates/                        # Admin .phtml templates, incl. provider/tab/{attrsettings,loginoptions}.phtml, passkeysettings.phtml, passkeyloginbutton.phtml
    │   └── web/
    │       ├── css/{m2oidc.css, adminSettings.css}
    │       ├── images/m2oidc_logo.png
    │       └── js/{dirtyTracking.js, passkey-manage.js}  # passkey-manage.js drives the admin self-service registration/delete UI injected by PasskeyUserInfoPlugin; loaded via requirejs-config.js deps
    └── frontend/
        ├── layout/                           # Frontend layout XML files; customer_account_login.xml injects the passkey login button, customer_account.xml adds the "Passkeys" My Account nav link, m2oidc_passkey_index.xml renders the manage-passkeys page
        ├── templates/                        # Frontend .phtml templates (SSO buttons, popups, passkeyloginbutton.phtml, passkey/manage.phtml)
        └── web/                              # Frontend CSS, images, JS templates
```

### Authentication Flow Diagram

```
CUSTOMER FLOW:
  Browser -> SendAuthorizationRequest (frontend, stamps loginType=customer,
                                        stores PKCE verifier in atomic cache, nonce in oidc_pkce_nonce cookie)
    -> IDP (authorize endpoint)
    -> ReadAuthorizationResponse (callback: validates CSRF+state, rate-limit check,
                                  exchanges code + PKCE verifier for tokens, verifies JWT)
    -> CheckAttributeMappingAction (evaluates access control rules, maps attributes, per-provider config override)
    -> ProcessUserAction (find/create customer, validate relay state)
    -> CustomerLoginAction (creates oidc_customer_nonce — or oidc_headless_nonce in headless mode — cookie, 300s TTL)
    -> CustomerOidcCallback (redeems nonce, validates website context,
                             sets customer session, sets oidc_customer_authenticated cookie, redirects to relay state)
       [headless mode] -> HeadlessOidcCallback instead: issues a customer token,
                          postMessage's {status, token, relayState} to the opener window, no session cookie

ADMIN FLOW:
  Browser -> SendAuthorizationRequest (adminhtml, stamps loginType=admin,
                                        stores PKCE verifier in atomic cache, nonce in oidc_admin_pkce_nonce cookie)
    -> IDP (authorize endpoint)
    -> ReadAuthorizationResponse (callback, same as customer: CSRF validation, rate limiting, JWT verification)
    -> CheckAttributeMappingAction (evaluates access control rules, loginType=admin)
    -> [if user exists] -> creates oidc_admin_nonce cookie (300s TTL) -> redirect to Oidccallback
    -> [if auto-create] -> AdminUserCreator (group→role mapping) -> creates nonce -> redirect to Oidccallback
    -> Oidccallback -> rate-limit check (OidcRateLimiter) -> redeems nonce
                    -> creates ephemeral auth token (OAuthSecurityHelper::createOidcAuthToken(), 300s TTL)
                    -> Auth::login($email, $ephemeralToken)
       |-> OidcCredentialPlugin detects OIDC_ token prefix (after resetting guard flags) -> injects OidcCredentialAdapter
       |-> OidcCaptchaBypassPlugin skips CAPTCHA
       |-> OidcCredentialAdapter authenticates (no password check; fires auth events with oidc_auth=true)
       |-> All Magento security events fire normally
    -> Stores oidc_id_token + oidc_provider_id in auth session (used by OidcLogoutPlugin on logout)
    -> Sets oidc_authenticated cookie -> Admin dashboard

ADMIN LOGOUT FLOW (a plugin, not an event observer):
  OidcLogoutPlugin::aroundLogout() (before session destroy):
    -> Reads oidc_id_token + oidc_access_token + oidc_provider_id from auth session
    -> $proceed() — destroys admin session
    -> Deletes oidc_authenticated cookie
    -> Sets oidc_logout_guard cookie (120s, prevents re-login loop)
    -> [if revocation_endpoint] -> RFC 7009 token revocation (fire-and-forget, non-fatal)
    -> Detects logout URL mode (Authelia forward-auth vs standard OIDC RP-Initiated)
    -> Redirects to IdP end_session_endpoint -> exit

CUSTOMER LOGOUT FLOW (an event observer, bound to controller_action_postdispatch_customer_account_logout):
  OAuthLogoutObserver:
    -> Reads oidc_id_token + provider_id from customer session
    -> Revokes access token (RFC 7009, fire-and-forget)
    -> Sets oidc_logout_guard cookie (300s TTL — longer than admin's 120s, to survive the IdP round-trip)
    -> Redirects to IdP end_session_endpoint (state=customer:<hex>)

PASSKEY REGISTRATION FLOW (customer and admin — same ceremony, different caller/storage target):
  Already-authenticated browser -> RegistrationOptions (AJAX POST)
    -> PasskeyRegistrationService::buildCreationOptions() builds PublicKeyCredentialCreationOptions
       (discoverable/resident key required, ES256/RS256, attestation=none, excludeCredentials = user's existing passkeys)
    -> options JSON stored under a one-time nonce in the atomic cache (300s TTL) -> {optionsJson, nonce} returned as JSON
  Browser calls navigator.credentials.create() -> user completes fingerprint/face/PIN/security-key prompt
    -> RegistrationVerify (AJAX POST: nonce + credential JSON + nickname)
    -> PasskeyRegistrationService::verifyAndPersist(): redeems nonce (one-time), verifies attestation via
       AuthenticatorAttestationResponseValidator, then PasskeyCredentialRepository::saveNewCredential()
       writes the new row to m2oidc_passkey_credentials

PASSKEY LOGIN FLOW — CUSTOMER (usernameless/discoverable):
  Anonymous browser -> LoginOptions (AJAX POST, no email/username)
    -> PasskeyAuthenticationService::buildRequestOptions([]) — empty allowCredentials, browser resolves the
       discoverable credential itself -> {optionsJson, nonce} returned
  Browser calls navigator.credentials.get() -> user completes prompt
    -> LoginVerify (AJAX POST: nonce + assertion JSON)
       -> PasskeyAuthenticationService::verifyAssertion(): redeems nonce, looks up the credential by its raw ID
          (findByRawCredentialId), verifies via AuthenticatorAssertionResponseValidator, bumps the stored counter
       -> resolves the owning customer's email, mints a one-time login nonce (createCustomerPasskeyLoginNonce),
          sets m2passkey_customer_nonce cookie (120s) -> returns {success, redirectUrl: PasskeyCustomerCallback}
    -> Browser navigates to PasskeyCustomerCallback (clean HTTP context)
       -> redeems nonce, enforces cross-website guard, calls CustomerSession::setCustomerAsLoggedIn()
       -> redirects to validated relay state or customer/account

PASSKEY LOGIN FLOW — ADMIN (email-scoped):
  Anonymous browser -> LoginOptions (AJAX POST, email from the login form's username field)
    -> if a matching admin exists, allowCredentials is scoped to that admin's own credentials
       (getDescriptorsForUser) — otherwise empty/discoverable -> {optionsJson, nonce} returned
  Browser calls navigator.credentials.get() -> user completes prompt
    -> LoginVerify (AJAX POST: nonce + assertion JSON)
       -> verifyAssertion() as above; rejects if the resolved credential's userType isn't 'admin'
       -> resolves the admin's email, mints a PKEY_ ephemeral auth token (createPasskeyAuthToken)
       -> Auth::login($email, $token)
          |-> PasskeyCredentialPlugin detects PKEY_ token -> injects PasskeyCredentialAdapter
          |-> PasskeyCredentialAdapter authenticates (no password check; fires admin_user_authenticate_before/after
              with passkey_auth=true)
       -> sets is_passkey_authenticated auth-storage flag + passkey_authenticated cookie (admin session lifetime)
       -> returns {success, redirectUrl: admin/dashboard}
```

### Database Tables

**`m2oidc_oauth_client_apps`** — stores the OIDC provider configuration. One row per configured provider.

Key columns:

| Column | Purpose |
|---|---|
| `id` | Primary key; used as `provider_id` throughout the module |
| `app_name` | Provider identifier (e.g., "authelia") |
| `clientID`, `client_secret` | OAuth credentials |
| `authorize_endpoint`, `access_token_endpoint`, `user_info_endpoint` | OIDC endpoints |
| `endsession_endpoint` | IdP logout URL for RP-Initiated Logout redirect |
| `revocation_endpoint` | RFC 7009 token revocation endpoint; if set, access token is revoked on logout |
| `jwks_endpoint` | JWKS URL for JWT signature verification |
| `issuer` | Expected OIDC issuer claim for JWT validation |
| `well_known_config_url` | OIDC discovery document URL |
| `scope` | OAuth scopes (e.g., "openid profile email groups") |
| `pkce_flow` | PKCE method ('S256'/'plain'). **There is no `pkce_code_verifier` column** — the verifier itself is never persisted to the database for either the admin or customer flow; see [Gotcha #12](#5-gotchas). |
| `email_attribute`, `username_attribute`, `firstname_attribute`, `lastname_attribute` | Attribute mapping overrides (legacy; normalized table takes priority if populated) |
| `group_attribute` | OIDC claim containing group memberships |
| `oauth_admin_role_mapping` | JSON: legacy group→role mappings (normalized table preferred) |
| `oauth_customer_group_mapping` | JSON: legacy group→group mappings (normalized table preferred) |
| `access_control_rules` | JSON: claims-based access control rules |
| `m2oidc_auto_create_customer`, `m2oidc_auto_create_admin` | Per-provider JIT provisioning toggles |
| `m2oidc_disable_non_oidc_admin_login`, `m2oidc_disable_non_oidc_customer_login` | Per-provider: block password-based login |
| `show_customer_link`, `show_admin_link` | SSO button visibility |
| `autoredirect_admin`, `autoredirect_customer` | Auto-redirect from login page |
| `is_active`, `login_type`, `sort_order` | Multi-provider: activation, scope ('customer'/'admin'/'both'), display order |
| `idp_initiated_enabled` | Smallint (default 0); enables IdP-Initiated SSO for this provider |
| `headless_mode` | Smallint (default 0); routes the customer callback through `HeadlessOidcCallback` for PWA token delivery instead of a session cookie |
| `jwks_cache_ttl` | Int, nullable, default 86400. Per-provider JWKS public-key cache lifetime in seconds. |
| `http_timeout` | Smallint, not null, default 30. Per-provider HTTP connect/read timeout for token endpoint and JWKS fetch calls. |
| `claim_encoding` | Varchar; `'none'` (default) or `'base64'`. Set to `'base64'` for Zitadel providers that Base64-encode their claim values. |
| `public_client` | Smallint 0\|1 (default 0). Omits `client_secret` from the token exchange for RFC 6749 §2.1 public clients. |
| `display_name`, `button_label`, `button_color` | Multi-provider UI customization |
| `sync_customer_profile_on_sso`, `sync_customer_address_on_sso`, `sync_customer_group_on_sso` | Re-sync customer data on every login |
| `sync_admin_profile_on_sso`, `sync_admin_role_on_sso` | Re-sync admin data on every login |
| `values_in_header`, `values_in_body` | Token request auth method (header vs body) |
| `grant_type` | OAuth grant type (default: `authorization_code`) |
| `log_file_suffix` | Varchar(64), nullable. When set, `OidcLogger` writes this provider's log lines to `var/log/M2Oidc_<suffix>.log` instead of the shared log file. |
| `last_test_status`, `last_test_at`, `received_oidc_claims` | Test configuration tracking |

> **`post_logout_url` is a real, admin-configurable column.** `OidcLogoutPlugin` and `OAuthLogoutObserver` both read `$provider['post_logout_url']` as a per-provider override for the post-logout landing page — configurable via the "Post-Logout Landing Page" field on the provider's Login Options tab. When set, it takes priority over the unified `Postlogout` callback for that provider. See [Gotcha #31](#5-gotchas).

---

**`m2oidc_oauth_attribute_mappings`** — normalized attribute mapping. One row per attribute slot per provider. Takes priority over legacy JSON columns in `m2oidc_oauth_client_apps`.

| Column | Purpose |
|---|---|
| `id` | Primary key |
| `provider_id` | FK → `m2oidc_oauth_client_apps.id` |
| `attribute_type` | Attribute slot (e.g., `email`, `firstname`, `billing_city`) |
| `attribute_name` | OIDC claim key to read from the token response |
| `sync_on_sso` | If `1`, re-sync this attribute on every login (not just first login) |
| `transform_function` | Optional: `concat` \| `split` \| `prefix` \| `regex_replace`; applied by `Model/Attribute/Transformer.php` before the value is assigned |
| `transform_params` | JSON params for the transform function (e.g. `{"fields":"given_name,family_name","delimiter":" "}` for `concat`) |

---

**`m2oidc_oauth_role_mappings`** — normalized role/group mapping. One row per OIDC group per provider. Takes priority over legacy JSON columns.

| Column | Purpose |
|---|---|
| `id` | Primary key |
| `provider_id` | FK → `m2oidc_oauth_client_apps.id` |
| `mapping_type` | `'admin_role'` or `'customer_group'` |
| `oidc_group` | OIDC group value (case-insensitive match at runtime) |
| `magento_role_id` | Magento admin role ID or customer group ID |
| `sort_order` | Evaluation order (lower = checked first) |

---

**`m2oidc_oauth_user_provider`** — tracks which OIDC provider created each Magento user; also doubles as the session activity log.

| Column | Purpose |
|---|---|
| `id` | Primary key |
| `user_type` | `'customer'` or `'admin'` |
| `user_id` | Magento `entity_id` (customer) or `user_id` (admin) |
| `provider_id` | References `m2oidc_oauth_client_apps.id` |
| `created_at` | Timestamp, auto-set on insert |

Unique constraint on `(user_type, user_id)` — each Magento user is linked to at most one provider. Used both for login-time IdP binding enforcement and by the logout flow to retrieve the correct `endsession_endpoint`.

Records are cleaned up in three ways:
1. **`AdminUserDeleteObserver`** — bound to `admin_user_delete_after` (global); removes the row for the deleted admin.
2. **`AdminUserDeletePlugin`** — `afterDelete` on `Magento\User\Model\User`; belt-and-suspenders redundancy alongside the observer.
3. **`Sessions/Delete.php`** and **`Provider/UnlinkUser.php`** admin controllers — allow individual record deletion from the Sessions UI or the admin-side unlink button.

`UserProviderResource` key methods: `saveMapping()` (INSERT … ON DUPLICATE KEY UPDATE), `deleteMapping()`, `getProviderInfo()`, `getBoundProviderId(userType, userId)` (returns the bound `provider_id` or `null` — called by `ProcessUserAction` and `CheckAttributeMappingAction` to enforce per-user IdP binding), `deleteById()`, `countByTypeAndProvider()` (used by lockout-prevention guards in `Provider/Save.php`).

---

**`m2oidc_passkey_credentials`** — registered WebAuthn passkey credentials for admin and customer users. One row per registered credential (a user may have several, e.g. one per device).

| Column | Purpose |
|---|---|
| `credential_id` | Primary key |
| `user_type` | `'customer'` or `'admin'` |
| `user_id` | Magento `entity_id` (customer) or `user_id` (admin) |
| `public_key_credential_id` | Base64url-encoded WebAuthn credential ID reported by the authenticator; unique constraint — the lookup key used to resolve a credential during login |
| `credential_record` | Full serialized `Webauthn\CredentialRecord` JSON — public key, signature counter, trust path, AAGUID. `PasskeyCredentialRepository` is the sole seam between this JSON blob and webauthn-lib's typed object |
| `nickname` | User-assigned label (e.g. "MacBook Touch ID"), max 191 chars, nullable |
| `transports` | Denormalized comma-separated transports (`internal`, `hybrid`, `usb`, …) for display only — not used for validation |
| `created_at` | Timestamp, auto-set on insert |
| `last_used_at` | Timestamp of the credential's last successful login, nullable |

Index on `(user_type, user_id)` for the self-service "manage passkeys" list and the `excludeCredentials`/`allowCredentials` lookups. No foreign key to `admin_user` or `customer_entity` — cleanup on account deletion is handled explicitly by `AdminUserDeletePlugin` and `CustomerDeleteObserver` calling `PasskeyCredentialRepository::deleteAllForUser()`, not by an FK cascade.

There is no dedicated setup patch for this table — declarative schema (`etc/db_schema.xml`) creates it directly; only the pre-existing `EncryptPlaintextClientSecrets` OIDC patch exists in `Setup/Patch/Data/`.

### Plugin Interceptions (defined in `etc/di.xml`)

| Target | Plugin | Sort Order | Area | Purpose |
|---|---|---|---|---|
| `Magento\Backend\Model\Auth` | `AdminLoginRestrictionPlugin` | 5 | adminhtml | Blocks non-OIDC admin login when restriction is enabled; allows through if OIDC button is hidden (safety net) |
| `Magento\Backend\Model\Auth` | `OidcCredentialPlugin` | 10 | adminhtml | Injects `OidcCredentialAdapter` during OIDC login; resets guard flags on every `beforeLogin()` |
| `Magento\Backend\Model\Auth` | `PasskeyCredentialPlugin` | 12 | adminhtml | Injects `PasskeyCredentialAdapter` when a `PKEY_`-prefixed token is detected; structurally identical to `OidcCredentialPlugin`, sits between it and `OidcLogoutPlugin` in sort order |
| `Magento\Backend\Model\Auth` | `OidcLogoutPlugin` | 20 | adminhtml | Reads OIDC session data before session destroy, revokes access token (RFC 7009), redirects to IdP logout — this is the admin RP-Initiated Logout mechanism; there is no separate admin logout *event observer* |
| `Magento\Captcha\Observer\CheckUserLoginBackendObserver` | `OidcCaptchaBypassPlugin` | 10 | adminhtml | Skips CAPTCHA for OIDC-authenticated admin logins |
| `Magento\Captcha\Observer\CheckUserLoginObserver` | `CustomerCaptchaBypassPlugin` | 10 | frontend | Skips CAPTCHA for OIDC-authenticated customer logins |
| `Magento\Customer\Api\AccountManagementInterface` | `CustomerLoginRestrictionPlugin` | 5 | frontend | Blocks non-OIDC customer logins when restriction is enabled |
| `Magento\User\Model\User` | `OidcIdentityVerificationPlugin` | 10 | adminhtml | Bypasses password re-verification for OIDC admins |
| `Magento\User\Model\User` | `AdminUserDeletePlugin` | 20 | global | `afterDelete`: removes matching row from `m2oidc_oauth_user_provider` and all of the deleted admin's `m2oidc_passkey_credentials` rows |
| `Magento\User\Observer\Backend\AuthObserver` | `OidcPasswordExpirationPlugin` | 10 | adminhtml | Suppresses password expiration warnings for OIDC admins — **not** on `Magento\User\Model\User` |
| `Magento\User\Observer\Backend\ForceAdminPasswordChangeObserver` | `OidcForcePasswordChangePlugin` | 10 | adminhtml | Suppresses forced password change redirects for OIDC admins — **not** on `Magento\User\Model\User` |
| `Magento\User\Block\User\Edit\Tab\Main` | `OidcIdentityFieldPlugin` | 20 | adminhtml | Removes "required" from password field in user edit form |
| `Magento\User\Block\Role\Tab\Info` | `OidcIdentityFieldPlugin` | 20 | adminhtml | Same for role edit form |
| `Magento\Backend\Block\System\Account\Edit\Form` | `OidcIdentityFieldPlugin` | 20 | adminhtml | Same for account settings form |
| `Magento\User\Block\User\Edit\Tab\Main` | `OidcUserInfoPlugin` | 30 | adminhtml | Injects OIDC provider info into admin user profile page |
| `Magento\User\Block\User\Edit\Tab\Main` | `PasskeyUserInfoPlugin` | 40 | adminhtml | Injects the passkey registration/management UI into the admin user profile page, after the OIDC info block |
| `Magento\Backend\Block\System\Account\Edit\Form` | `PasskeyUserInfoPlugin` | 30 | adminhtml | Same, on the account-settings form |
| `Magento\Customer\Block\Adminhtml\Edit\Tab\View` | `OidcInfoPlugin` | 10 | adminhtml | Injects OIDC provider info into the **admin-facing** Customer Edit page — this is not a frontend/customer-account block despite the "Customer" in its namespace |

### Events Observed

Two different extension mechanisms are used and it's worth keeping them straight: **event observers** (`etc/events.xml` family, fire-and-forget, many-to-one) and **plugins** (`etc/di.xml`, method interception, ordered chain). Admin RP-Initiated Logout, for example, is a *plugin* on `Auth::logout()` — there is no admin logout *event* to hook.

| Event | Observer | Registered in | Notes |
|---|---|---|---|
| `controller_action_predispatch_customer_account_login` | `CustomerLoginAutoRedirectObserver` | `etc/events.xml` (global) | Auto-redirects unauthenticated customers to IdP when enabled; suppressed by `oidc_logout_guard` cookie |
| `controller_action_postdispatch_customer_account_logout` | `OAuthLogoutObserver` | `etc/frontend/events.xml` | Customer RP-Initiated Logout — **not** `customer_logout` |
| `customer_logout` | `CustomerSetLogoutFlagObserver` | `etc/frontend/events.xml` | Sets logout flag on session destruction only — does not perform the IdP redirect |
| `customer_delete_after` | `CustomerDeleteObserver` | `etc/events.xml` (global) | Removes `m2oidc_oauth_user_provider` row — **not** `customer_delete` |
| `controller_action_predispatch` | `TokenAutoRefreshObserver` | `etc/frontend/events.xml` | Silent customer access-token refresh |
| `controller_action_predispatch` | `AdminTokenAutoRefreshObserver` | `etc/adminhtml/events.xml` | Silent admin access-token refresh |
| `controller_action_predispatch` | `TestConfigRequestObserver` | both `etc/frontend/events.xml` and `etc/adminhtml/events.xml` | Detects a test-config request and renders test results inline — easy to miss since it shares the event name with two other observers above |
| `controller_action_predispatch_adminhtml_auth_login` | `AdminLoginAutoRedirectObserver` | `etc/adminhtml/events.xml` | Auto-redirects unauthenticated admins to IdP when enabled; respects `oidc_logout_guard` |
| `adminhtml_user_logout` | `AdminSetLogoutFlagObserver` | `etc/adminhtml/events.xml` | Sets logout flag on admin session destruction |
| `admin_user_delete_after` | `AdminUserDeleteObserver` | `etc/events.xml` (global) | Removes `m2oidc_oauth_user_provider` row |

### Custom Events Dispatched

| Event | Where dispatched | Payload keys | Purpose |
|---|---|---|---|
| `oidc_admin_user_before_create` | `UserProvisioningService` | `transport` (DataObject; set `skip_creation = true` to veto) | Fired before admin JIT user creation |
| `oidc_admin_user_after_create` | `UserProvisioningService` | `user`, `email`, `provider_id`, `transport` | Fired after successful admin JIT user creation |
| `oidc_customer_before_create` | `UserProvisioningService` | `transport` (DataObject; set `skip_creation = true` to veto) | Fired before customer JIT user creation |
| `oidc_customer_after_create` | `UserProvisioningService` | `customer`, `email`, `provider_id`, `transport` | Fired after successful customer JIT user creation |
| `oidc_after_attribute_mapping` | `Controller/Actions/CheckAttributeMappingAction.php` (**not** `UserProvisioningService`) | `provider_id` (int), `mapped_attrs` (DataObject — writable), `raw_claims` (DataObject — read-only) | Fired after all OIDC claims have been mapped. Observers may call `mapped_attrs->setData()` to alter values before user creation or profile sync. |

There is no single generic `oidc_before_user_create` / `oidc_after_user_create` pair — admin and customer provisioning fire four distinct, differently-named events, matching the stub `<event>` declarations in `etc/events.xml`.

### Key Classes Reference

A trimmed API reference for the classes you're most likely to touch when extending this module. For the full class list, read [§1](#1-overview) and the directory tree above, or just grep the namespace.

#### `\M2Oidc\OAuth\Helper\Data`

Base data-access helper, injected wherever configuration values are needed.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `getSPInitiatedUrl` | `($relayState = null, $app_name = null)` | `string` | Builds the frontend SSO login URL. |
| `getAdminSPInitiatedUrl` | `($relayState = null, $app_name = null)` | `string` | Builds the admin backend SSO login URL; stamps `loginType=admin` into the state. |
| `getSPInitiatedUrlForProvider` | `(int $providerId, ?string $relayState = null, string $loginType = 'customer')` | `string` | Builds the SSO URL for a specific provider ID — preferred in multi-provider setups. |
| `getCallBackUrl` | `()` | `string` | Returns `{baseUrl}m2oidc/actions/ReadAuthorizationResponse`. Register this in your IdP. |
| `getStoreConfig` | `($config)` | `mixed` | Reads from `m2oidc/oauth/{$config}`; overridden in `OAuthUtility` to resolve provider-specific keys when an active provider ID is set. |
| `getAllActiveProviders` | `(string $loginType = 'customer')` | `array` | Returns all active provider rows for the given login type. |
| `sanitize` | `($value)` | `mixed` | Recursive `htmlspecialchars(strip_tags(trim(...)))`, applied to all config writes by default. |

#### `\M2Oidc\OAuth\Helper\OAuthUtility`

Extends `Data`; the class most controllers and plugins inject.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `isUserLoggedIn` | `()` | `bool` | Checks both customer session and admin auth session. |
| `setActiveProviderId` / `getActiveProviderId` | `(int)` / `()` | `void` / `?int` | Sets/reads the active provider for the current request; drives which `m2oidc_oauth_client_apps` row `getStoreConfig()` resolves against. |
| `customlog` / `customlogContext` | `($txt)` / `(string $event, array $context = [])` | `void` | Delegates to `Logger\OidcLogger`; masks sensitive keys automatically. |
| `extractNameFromEmail` | `(string $email)` | `array` | Splits email into `['first' => 'prefix', 'last' => 'domain']` — used as a name fallback. |

#### `\M2Oidc\OAuth\Helper\OAuthSecurityHelper`

PKCE, state tokens, and nonces — all cache-based (not session, not DB), all one-time-use via `AtomicCacheInterface::getAndDelete()`.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `storePkceVerifier` | `(string $verifier): string` | `string` (nonce) | Stores the PKCE verifier in the atomic cache under a random nonce, 600s TTL. The nonce (not the verifier) is what gets put in the `oidc_pkce_nonce`/`oidc_admin_pkce_nonce` cookie. |
| `consumePkceVerifier` | `(string $nonce): ?string` | `string\|null` | One-time read-and-delete of the verifier by nonce. |
| `createOidcAuthToken` / `validateAndConsumeOidcAuthToken` | — | `string` / `bool` | Ephemeral admin login token (300s TTL) used as the "password" passed to `Auth::login()`. |
| `createCustomerLoginNonce` / `redeemCustomerLoginNonce` | `(string $email, ...)` / `(string $nonce)` | `string` / `array\|null` | Customer (and headless) login nonce, 300s TTL. |
| `validateRedirectUrl` | `(string $url): bool` | `bool` | Same-origin check; also rejects null bytes and backslashes as bypass vectors. |

#### `\M2Oidc\OAuth\Model\Attribute\Transformer`

See [Claim-Value Transformers](#claim-value-transformers) in §4 for the full behavior. One public method:

| Method | Signature | Returns | Description |
|---|---|---|---|
| `apply` | `(?string $rawValue, array $rawClaims, ?string $function, ?string $paramsJson): ?string` | `string\|null` | Applies the named transform (`concat`/`split`/`prefix`/`regex_replace`) or passes the value through unchanged if `$function` is null/empty. Never throws — logs a WARNING and returns the raw value on error. |

#### `\M2Oidc\OAuth\Model\Service\OidcAuthenticationService`

Core service for processing OIDC provider responses; injected into `CheckAttributeMappingAction` and `CustomerUserCreator`.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `validateUserInfo` | `(array $userInfo): void` | `void` | Throws `IncorrectUserInfoDataException` if the response is empty or contains error keys. |
| `flattenAttributes` | `(array $attrs, string $prefix = '', int $depth = 0): array` | `array` | Recursively flattens nested OIDC claims into dot-notation keys (e.g. `address.city`). Base64-decodes when `claim_encoding = base64`, UTF-8-validates, rejects C0/C1 control characters. Depth limit: `MAX_RECURSION_DEPTH = 5`. |
| `extractEmail` | `(array $flatAttrs, string $emailAttrKey): string` | `string` | Falls back to `findEmailRecursive()` if the configured key is absent. |
| `normalizeGroups` | `(mixed $groups): array` | `array` | Handles plain string, flat array, and Zitadel nested-object group formats. |
| `normalizeZitadelRoleClaimsForDisplay` | `(array $flatAttrs, string $groupAttr): array` | `array` | Reconstructs human-readable parent role keys from Zitadel's flattened subkeys, for the Test Configuration display. |

#### `\M2Oidc\OAuth\Model\Cache\AtomicCacheInterface`

| Method | Signature | Returns | Description |
|---|---|---|---|
| `save` | `(string $identifier, string $value, int $ttl): void` | `void` | Stores `$value` under `$identifier`. |
| `getAndDelete` | `(string $key): ?string` | `string\|null` | Reads and deletes in one logical operation — eliminates the load+remove TOCTOU race. Default implementation: `RedisAtomicCache`. |

#### `\M2Oidc\OAuth\Model\Attribute\MapperPool`

| Method | Signature | Returns | Description |
|---|---|---|---|
| `getMapper` | `(int $providerId, string $type): AttributeMapperInterface` | `AttributeMapperInterface` | Lookup order: `"{providerId}_{type}"` → `"default_{type}"`. |

Third-party modules add per-provider overrides:
```xml
<type name="M2Oidc\OAuth\Model\Attribute\MapperPool">
    <arguments>
        <argument name="mappers" xsi:type="array">
            <!-- Override admin mapper for provider ID 3 -->
            <item name="3_admin" xsi:type="object">Vendor\Module\Model\Attribute\MyAdminMapper</item>
        </argument>
    </arguments>
</type>
```

#### `\M2Oidc\OAuth\Helper\PasskeyConfig`

| Method | Signature | Returns | Description |
|---|---|---|---|
| `isEnabledForAdmin` / `isEnabledForCustomer` | `(): bool` | `bool` | Reads `m2oidc_passkey/general/enabled_{admin,customer}`. |
| `getRpName` | `(): string` | `string` | Configured Relying Party name, or the store's frontend name if unset. |
| `getRpId` | `(): string` | `string` | Configured RP ID (domain), or the host parsed from the store base URL if unset. |
| `getOrigin` | `(): string` | `string` | `scheme://host[:port]` the WebAuthn ceremony's `AllowedOrigins` must match — derived from the store base URL, not separately configurable. |

#### `\M2Oidc\OAuth\Helper\PasskeySecurityHelper`

Structurally parallel to `OAuthSecurityHelper`, but with a distinct `PKEY_` marker so the two ephemeral-token schemes can never be confused. All storage is `AtomicCacheInterface`-backed (cache, not session, not DB), all one-time-use.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `deriveUserHandle` | `(string $userType, int $userId): string` | `string` (32 raw bytes) | Deterministic `sha256($userType . ':' . $userId, true)` — same handle across every credential a user registers, without a separate DB column. Not derived from email/PII. |
| `createChallengeNonce` / `redeemChallengeNonce` | `(string $optionsJson): string` / `(string $nonce): ?string` | — | One-time WebAuthn challenge (creation or request options JSON) storage, 300s TTL. |
| `createPasskeyAuthToken` / `validateAndConsumePasskeyAuthToken` | `(string $email): string` / `(string $email, string $token): bool` | — | Ephemeral `PKEY_`-prefixed admin login bridge token (300s TTL), mirrors `OAuthSecurityHelper::createOidcAuthToken()`. |
| `isPasskeyAuthToken` | `(string $password): bool` | `bool` | Non-consuming format check (69 chars, `PKEY_` + 64 hex) used by plugins to detect a passkey login attempt without touching the cache. |
| `createCustomerPasskeyLoginNonce` / `redeemCustomerPasskeyLoginNonce` | `(string $email, string $relayState): string` / `(string $nonce): ?array` | — | Customer login handoff nonce (300s TTL), mirrors `OAuthSecurityHelper::createCustomerLoginNonce()`. |

#### `\M2Oidc\OAuth\Model\Passkey\WebauthnCeremonyFactory`

Single seam constructing every `web-auth/webauthn-lib` object this module needs, configured from `PasskeyConfig`.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `getSerializer` | `(): SerializerInterface` | `SerializerInterface` | Symfony serializer configured with `NoneAttestationStatementSupport` only — this module never validates attestation trust chains. |
| `getRpEntity` / `getRpId` | `(): PublicKeyCredentialRpEntity` / `(): string` | — | RP name + ID from `PasskeyConfig`. |
| `getPublicKeyCredentialParameters` | `(): PublicKeyCredentialParameters[]` | array | `[ES256, RS256]` — the only two algorithms accepted. |
| `getAuthenticatorSelectionCriteria` | `(): AuthenticatorSelectionCriteria` | — | `userVerification=preferred`, `residentKey=required` — discoverable credentials are mandatory. |
| `getCreationCeremonyStepManager` / `getRequestCeremonyStepManager` | `(): CeremonyStepManager` | — | Built with `AllowedOrigins` locked to `PasskeyConfig::getOrigin()`. |

#### `\M2Oidc\OAuth\Model\Passkey\PasskeyRegistrationService` / `PasskeyAuthenticationService`

Shared by admin and customer controllers alike — the WebAuthn ceremony itself doesn't differ between areas, only who is allowed to call it and which `user_type`/`user_id` the result is stored against or resolved from.

| Class | Method | Description |
|---|---|---|
| `PasskeyRegistrationService` | `buildCreationOptions(string $userType, int $userId, string $email, string $displayName): array{optionsJson, nonce}` | Builds attestation options with `excludeCredentials` set to the user's existing passkeys. |
| `PasskeyRegistrationService` | `verifyAndPersist(string $nonce, string $credentialJson, string $userType, int $userId, ?string $nickname): void` | Redeems the challenge, verifies attestation, persists via `PasskeyCredentialRepository::saveNewCredential()`. Throws `\RuntimeException` on an expired/consumed challenge or failed verification. |
| `PasskeyAuthenticationService` | `buildRequestOptions(array $allowCredentials = []): array{optionsJson, nonce}` | Empty `$allowCredentials` = usernameless/discoverable login (customer flow); populated = scoped login (admin flow). |
| `PasskeyAuthenticationService` | `verifyAssertion(string $nonce, string $credentialJson): StoredCredential` | Redeems the challenge, resolves the credential by raw ID, verifies the assertion, bumps the counter, returns the owning `StoredCredential`. Throws on expired challenge, unknown credential, or failed verification. |

#### `\M2Oidc\OAuth\Model\ResourceModel\PasskeyCredentialRepository`

The sole seam between webauthn-lib's `Webauthn\CredentialRecord` (which carries a `TrustPath` object and a `Uuid`, so it can't be persisted as plain columns) and the `m2oidc_passkey_credentials` table.

| Method | Signature | Description |
|---|---|---|
| `saveNewCredential` | `(CredentialRecord $record, string $userType, int $userId, ?string $nickname): void` | Registration flow. |
| `findByRawCredentialId` | `(string $rawCredentialId): ?StoredCredential` | Login flow — resolves which user a browser assertion belongs to. |
| `findAllForUser` / `getDescriptorsForUser` | `(string $userType, int $userId): array` | Powers the self-service list and `excludeCredentials`/`allowCredentials`. |
| `updateAfterAuthentication` | `(StoredCredential $stored, CredentialRecord $updatedRecord): void` | Persists the bumped signature counter after a successful login. |
| `deleteAllForUser` | `(string $userType, int $userId): void` | Called by `AdminUserDeletePlugin` / `CustomerDeleteObserver` on account deletion. |
| `deleteOwnedCredential` | `(int $credentialId, string $userType, int $userId): bool` | Self-service delete — only succeeds if the credential actually belongs to the caller. |
| `deleteById` | `(int $credentialId): void` | Support/lockout-recovery delete — any credential, ACL-gated at the controller level, not scoped by owner. |

---

## 3. Quick Start

### Step 1 — Install and enable

```bash
composer require martinkuhl/magento2-oidc-sso
bin/magento module:enable M2Oidc_OAuth
bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush
```

### Step 2 — Configure a provider

Go to **Stores > Configuration > M2Oidc > OAuth/OIDC** (or the Provider grid at `/admin/m2oidc/provider/index` for multi-provider setups) and fill in:

| Field | Example value |
|---|---|
| App Name | `authelia` |
| Client ID | `magento-store` |
| Client Secret | `your-secret` |
| **Well-Known Config URL** *(optional)* | `https://auth.example.com/.well-known/openid-configuration` |
| Authorize Endpoint | `https://auth.example.com/api/oidc/authorization` |
| Token Endpoint | `https://auth.example.com/api/oidc/token` |
| User Info Endpoint | `https://auth.example.com/api/oidc/userinfo` |
| Scope | `openid profile email groups` |

> **Tip — Auto-Discovery**: Enter only the **Well-Known Config URL** and save. The module fetches the OIDC discovery document and auto-fills all endpoint URLs, JWKS URL, and issuer.

Set the **Callback URL** in your IdP to:
```
https://your-site.com/m2oidc/actions/ReadAuthorizationResponse
```

### Step 3 — Test

Click **Test Configuration** in the admin panel. You'll be redirected to your IdP, and on return you'll see the received attributes. Map them under **Attribute Mapping** and you're done.

---

## 4. Functionalities and Use Cases

### Primary Use Cases

**Enterprise SSO Integration**
- Centralize identity management with a corporate IdP (Authelia, Keycloak, Auth0, Azure AD, Okta, Google, Zitadel)
- Single identity across multiple systems (Magento + other enterprise applications)
- Eliminate password management overhead: no password resets, no credential storage

**Multi-Store Customer Federation**
- Share customer identity across multiple Magento stores; customer authenticates once, accesses all stores in the network

**B2B Customer Onboarding Automation**
- Automatically provision customers from a corporate directory; map organizational groups to Magento customer groups; pre-populate billing/shipping addresses

**Admin Team Management**
- Dynamic admin role assignment based on corporate roles; zero-touch provisioning; automatic role updates when organizational structure changes

### Customer Flow Use Cases

**Guest-to-Customer Conversion (JIT Provisioning)**
- First-time OIDC login automatically creates a Magento customer account. Configurable via `m2oidc_auto_create_customer` (global) or per-provider column. Password generated but never used.

**Address Auto-Population**
- Maps OIDC claims to Magento customer address fields (default: `address.street_address`, `address.locality`, `address.region`, `address.postal_code`, `address.country`). Billing and shipping are configured separately, each with city/state/country/street/phone/zip overrides.
- **Country name resolution**: `CustomerAttributeMapper` resolves country values to ISO codes via Magento's `CountryCollection`. With the PHP `intl` extension available, English country names (e.g., `Germany` from Authelia) are additionally matched via `Locale::getDisplayRegion()` — this handles IdPs that always send English names regardless of store locale.

**Profile Enrichment**
- DOB (`amDob`, default claim `birthdate`, formatted YYYY-MM-DD), gender (`amGender`, default `gender`, mapped to Magento gender IDs 1/2/3), phone (`amPhone`, default `phone_number`). Synchronized on first login; controlled by `sync_customer_profile_on_sso`/`sync_customer_address_on_sso` on subsequent logins.

**Customer Group Assignment**
- OIDC groups mapped via `m2oidc_oauth_role_mappings` (`mapping_type = 'customer_group'`). Example: IdP group "VIP_Customers" → Magento "VIP" group. `createIfNotMapped` flag can deny login if no group matches; `sync_customer_group_on_sso` re-maps on every login.

**Session Continuity with Relay State**
- The OAuth `state` parameter preserves the target URL plus session ID, app name, login type, CSRF token, and provider ID, so shopping cart and checkout flow survive the IdP round-trip. Validated against the store URL to prevent open redirect.

**CAPTCHA Bypass (Customer)**
- `CustomerCaptchaBypassPlugin` skips CAPTCHA for customers authenticating via OIDC, mirroring the admin-side `OidcCaptchaBypassPlugin`.

### Admin Flow Use Cases

**Zero-Touch Admin Provisioning**
- Enabled via `autoCreateAdmin` (global) or per-provider `m2oidc_auto_create_admin`. Email from IdP matched against `admin_user`; if no match and auto-create enabled, `AdminUserCreator::createAdminUser()` is invoked.

**Role Hierarchy Enforcement**
- OIDC groups mapped via `m2oidc_oauth_role_mappings` (`mapping_type = 'admin_role'`). Fallback chain: group mapping (case-insensitive) → `defaultRole` config → **deny** (`null`, user creation refused — no implicit fallback to any built-in role).

**Password Elimination**
- CAPTCHA bypass, password re-verification bypass, password expiration suppression, and forced password-change suppression, all detected via the `oidc_authenticated` cookie or `oidc_auth` event marker.

**Audit Compliance**
- All standard Magento auth events fire (`admin_user_authenticate_before`/`_after`) with an `oidc_auth => true` marker. Login recorded via `User::recordLogin()`.

**Emergency Access Patterns**
- Login restriction safety net: if the OIDC button is hidden (`show_admin_link` off) while restriction is on, password login is still allowed — prevents lockout during IdP outages. CLI admin creation (`bin/magento admin:user:create`) always remains available.

### Security Use Cases

**Passwordless Authentication**
- Eliminates password-based attack vectors entirely; random 32-char passwords generated but never used (28 alphanumeric + 2 special + 2 digit, shuffled), via the shared `Model/Service/RandomPasswordGenerator`, used by both `AdminUserCreator` and `CustomerUserCreator`.

**Claims-Based Access Control**
- Per-provider JSON rules against OIDC claims, evaluated before any user routing or provisioning. Operators: `eq`, `neq`, `contains`, `not_contains`, `exists`, `not_exists`. AND-combined — first failure denies access and JIT provisioning never runs.
```json
[
  {"claim": "email_verified", "operator": "eq", "value": "true"},
  {"claim": "groups", "operator": "contains", "value": "magento-users"}
]
```
An array-valued `groups` claim is joined with commas before string comparison.

**CSRF Protection**
- A token generated in `SendAuthorizationRequest`, embedded in the OAuth `state` parameter, extracted and validated against the session in `ReadAuthorizationResponse` before any authorization code is processed.

**Rate Limiting**
- `OidcRateLimiter` delegates to `FixedWindowStrategy` by default (10 attempts / 60s window; window start never slides). Applied to `ReadAuthorizationResponse`, `Oidccallback`, `BackChannelLogout`, `FrontChannelLogout`, `IdpInitiatedLogin`, and `HeadlessOidcCallback`. Inject `OidcSlidingWindowRateLimiter` for Redis-backed true sliding-window burst tolerance.

**Lockout-Prevention Guard**
- `Provider/Save.php` checks `m2oidc_oauth_user_provider` before saving: if "disable non-OIDC login" is requested but no user of that type has ever authenticated via OIDC for this provider, the setting auto-resets to `0` with a warning. `Block/Adminhtml/Provider/Edit/Tab/LoginOptions.php` surfaces the warning via `hasOidcAdminUsers()`/`hasOidcCustomerUsers()`.

**Per-User IdP Binding**
- Prevents the lowest-common-denominator problem when the same email exists behind multiple IdPs: revoking a user in one IdP shouldn't leave them able to log in via another. Binding lives in `m2oidc_oauth_user_provider.provider_id`, enforced by `ProcessUserAction`/`CheckAttributeMappingAction` calling `getBoundProviderId()` on every login. Mismatch → `PROVIDER_MISMATCH` error. No binding yet (pre-existing account) → first IdP to authenticate claims it. An admin can re-assign via `Provider/UnlinkUser.php` (removes the binding so the next login claims fresh) — there's no customer self-service equivalent yet.

**Required Field Validation**
- Provider save validates `email_attribute`, `username_attribute`, `firstname_attribute`, `lastname_attribute` are all non-empty; a missing field blocks the save entirely.

**Address Integrity Guard**
- `CustomerUserCreator` only creates a billing address when all four required fields (street, ZIP, city, country) are mapped and non-empty — partial mappings are skipped entirely to avoid Magento address-validation failures at checkout.

**JWT Verification**
- RS256/384/512 signatures; JWKS fetched with a configurable per-provider cache TTL (`jwks_cache_ttl`, default 86400s); circuit-breaker opens a `m2oidc_jwks_fail_*` cache key for 60s after a failed re-fetch. Nonce validation logs a WARNING if `expectedNonce` is `null` (misconfigured hybrid flow). The JWKS cache is evicted/refetched only when the token's `kid` is unknown to the cached key set — a signature failure against a *known* `kid` does not force a live re-fetch, which prevents an unauthenticated caller (e.g. via `BackChannelLogout`) from forcing repeated JWKS fetches.

**PKCE (RFC 7636)**
- Code verifier is generated in `SendAuthorizationRequest` and stored in the shared **atomic cache** (Redis by default, file-based fallback), keyed by a random nonce that travels in a cookie (`oidc_pkce_nonce` for customer, `oidc_admin_pkce_nonce` for admin). Retrieved and deleted (one-time use) in `ReadAuthorizationResponse`. This is not session-based and not DB-based — see [Gotcha #12](#5-gotchas) for details.

**RP-Initiated Logout with Token Revocation**
- Admin: `OidcLogoutPlugin::aroundLogout()` reads `oidc_id_token`/`oidc_access_token` before `Auth::logout()` destroys the session, optionally revokes via RFC 7009, then redirects to `end_session_endpoint` in either standard OIDC mode (`id_token_hint`, `state=admin:<hex>`, unified `post_logout_redirect_uri`) or Authelia forward-auth mode (`rd=<adminBaseUrl>`).
- Customer: `OAuthLogoutObserver` (bound to `controller_action_postdispatch_customer_account_logout`) follows the same pattern with `state=customer:<hex>`.

**Open Redirect Protection**
- `validateRedirectUrl()` enforces same-origin, rejects null bytes/backslashes as bypass vectors, and blocks login-page relay states to prevent redirect loops.

**Worker State Isolation**
- `OidcCredentialPlugin::beforeLogin()` unconditionally resets all internal flags at the start of every login attempt, guarding against PHP-FPM worker recycling leaking OIDC state between requests.

<a id="passwordless-login-passkeys"></a>
### Passwordless Login (Passkeys)

An independent, non-OIDC passwordless login method for both admin and customer users — no external IdP involved. Backed by `web-auth/webauthn-lib` (`^5.3`) and toggled per user type at **M2 OIDC > Passkey Settings**.

**Self-Service Registration**
- A logged-in customer registers a passkey from **My Account > Passkeys**; an already-authenticated admin registers one from their user profile / account settings page (injected by `PasskeyUserInfoPlugin`).
- Registration always requires a discoverable (resident-key) credential, so the browser can present a matching passkey without the user typing an identifier first.
- `excludeCredentials` is populated with the user's existing passkeys, so re-registering the same authenticator produces a browser-level "already registered" response instead of a duplicate row.

**Customer Login — Usernameless**
- No email/username field is used. `LoginOptions` requests assertion options with an empty `allowCredentials` array; the browser's own passkey picker resolves which discoverable credential to use.
- On a verified assertion, the login is handed off to `PasskeyCustomerCallback` via a one-time nonce cookie — the same clean-HTTP-context pattern `CustomerLoginAction`/`CustomerOidcCallback` use for OIDC, so `CustomerSession::setCustomerAsLoggedIn()` runs outside the AJAX request's context.

**Admin Login — Email-Scoped**
- The admin login form's existing email field is reused: if a matching admin account exists, `allowCredentials` is scoped to that admin's own registered passkeys (falls back to discoverable/empty if no match, or if the email field is left blank).
- A verified assertion mints a `PKEY_`-prefixed ephemeral token and calls `Auth::login($email, $token)` — bridged into native Magento admin auth exactly like the OIDC flow (see [Gotcha #33](#5-gotchas)), so CAPTCHA bypass, password re-verification bypass, password expiration suppression, and forced password-change suppression all apply identically.

**Lockout Recovery**
- An admin with the `M2Oidc_OAuth::passkey_settings` ACL resource can delete *any* user's passkey from the Registered Passkeys grid on the Passkey Settings page — distinct from the self-service delete controllers, which only ever operate on the caller's own credentials.

**Account Deletion Cleanup**
- `AdminUserDeletePlugin` (admin) and `CustomerDeleteObserver` (customer) both call `PasskeyCredentialRepository::deleteAllForUser()` alongside the existing OIDC-mapping cleanup — no orphaned credentials survive account deletion, even though there is no DB foreign key enforcing it.

**Security properties**
- Public-key cryptography — no shared secret is ever stored server-side; only a public key and signature counter
- Phishing-resistant by construction: the WebAuthn ceremony's `AllowedOrigins` is locked to the store's own origin, so a credential cannot be used to authenticate against a different domain
- Signature counter is verified and bumped on every login (clone/replay detection is enforced by webauthn-lib's `AuthenticatorAssertionResponseValidator`)
- Rate-limited via the same shared `OidcRateLimiter` used by the OIDC endpoints (10 attempts / 60s)
- Attestation is not validated (`attestation=none`) — this module trusts the public key, not a hardware trust chain; see [Known Limitations](#known-limitations) below

### Common Recipes

**Add a "Login with SSO" button to your theme**
```php
<?php
// In your .phtml template — inject \M2Oidc\OAuth\Helper\Data as $oauthHelper
$loginUrl = $oauthHelper->getSPInitiatedUrl();
?>
<a href="<?= $loginUrl ?>" class="btn-sso">Login with SSO</a>
```
For admin login use `getAdminSPInitiatedUrl()`. For multi-provider setups, iterate `getAllActiveProviders()` and call `getSPInitiatedUrlForProvider($provider['id'])` per button.

**Check if the current user logged in via OIDC**
```php
// Inject \M2Oidc\OAuth\Helper\OAuthUtility as $oauthUtility
if ($oauthUtility->isUserLoggedIn()) {
    $customer = $oauthUtility->getCurrentUser();
    $admin    = $oauthUtility->getCurrentAdminUser();
}
```
Or check cookies directly: `oidc_authenticated` (admin) / `oidc_customer_authenticated` (customer), both `=== '1'`.

**Extend attribute mapping**
Create a plugin on `CheckAttributeMappingAction::handle()`, or observe `oidc_after_attribute_mapping` to mutate `mapped_attrs` before user creation. Attribute mappings live in both the legacy `m2oidc_oauth_client_apps` columns and the normalized `m2oidc_oauth_attribute_mappings` table (which always wins when populated).

<a id="claim-value-transformers"></a>
**Use a claim-value transformer instead of writing a plugin**
For simple value reshaping you often don't need custom code at all — set `transform_function`/`transform_params` on the attribute mapping row:

| Function | Params | Example |
|---|---|---|
| `concat` | `fields` (comma-separated claim names), `delimiter` (default `" "`) | `fields=given_name,family_name` → `"Jane Doe"` |
| `split` | `delimiter` (default `" "`), `index` (0=first, -1=last, …) | `delimiter=@ index=0` → `"jdoe"` from `"jdoe@example.com"` |
| `prefix` | `value` (the literal prefix) | `value=sso_` → `"sso_jdoe"` |
| `regex_replace` | `pattern` (PCRE), `replacement` (default `""`) | `pattern=/@.*$/` → `"jdoe"` from `"jdoe@example.com"` |

Null/empty function is a passthrough. There is still no *conditional* logic (e.g. "apply transform X only if `groups` contains Y") — each transform applies unconditionally to every value of that attribute. For that you still need a plugin on `CheckAttributeMappingAction::handle()` (not `execute()` — see Gotcha #13).

**Configure a single Post Logout Redirect URI for providers with one-URL limits**
Some IdPs allow only one Post Logout Redirect URI per OIDC client. Register:
```
https://your-site.com/m2oidc/actions/postlogout
```
No code changes needed — both admin and customer logout flows already default to this URL. Context is carried in the `state` parameter:

| Flow | `state` value | Final destination |
|---|---|---|
| Admin logout | `admin:<random>` | Admin login page (`/admin/`) |
| Customer logout | `customer:<random>` | Customer login page (`/customer/account/login/`) |
| Unknown/absent state | — | Store home (`/`) |

A per-provider `post_logout_url` override is read by the code and takes priority over the unified URL above when set (see the Database Tables note above and [Gotcha #31](#5-gotchas)). Authelia's `?rd=` mode bypasses this mechanism entirely and needs no change.

**Add a security bypass for OIDC users in a third-party module**
Follow the pattern from `Plugin/Captcha/OidcCaptchaBypassPlugin.php` — check the `oidc_auth` event marker or the `oidc_authenticated` cookie:
```php
public function aroundExecute($subject, callable $proceed, \Magento\Framework\Event\Observer $observer)
{
    $loginData = $observer->getEvent()->getData();
    if (!empty($loginData['oidc_auth'])) {
        return; // Skip for OIDC users
    }
    return $proceed($observer);
}
```

<a id="headless--pwa-login"></a>
**Headless / PWA login (for Hyvä / decoupled frontends)**

1. PWA calls the `oidcLoginUrl(provider_id: X, headless: true)` GraphQL query → returns a URL containing `?headless=1`.
2. PWA opens that URL in a popup window.
3. `SendAuthorizationRequest` detects `headless=1` + `headless_mode=1` on the provider + `login_type=customer` and encodes `h:1` into the relay state.
4. `ReadAuthorizationResponse` extracts the headless flag and threads it through the rest of the action chain via `setHeadless()`.
5. `CustomerLoginAction` creates a nonce tagged `headless: true`, stores it in the `oidc_headless_nonce` cookie, and redirects to `HeadlessOidcCallback` instead of `CustomerOidcCallback`.
6. `HeadlessOidcCallback` redeems the nonce, issues a Magento customer token (`Magento\Integration\Model\Oauth\TokenFactory`), and returns an HTML page that calls `window.opener.postMessage({status:"ok", token:"...", relayState:"..."}, origin)` then closes the popup.
7. The PWA stores the token (e.g. in `localStorage`) and uses it as `Authorization: Bearer <token>` for GraphQL requests. No Magento session cookie is ever set for this flow.

### Known Limitations

- **Claim transformers are unconditional, not per-group.** The four built-in transform functions (`concat`/`split`/`prefix`/`regex_replace`) apply the same way to every login regardless of the user's OIDC group membership. There's no "if group = X, map attribute Y differently" — see [§6](#6-future-improvements) if you need that.
- **No customer self-service IdP unlink.** The admin can unlink a customer's bound provider (`Provider/UnlinkUser.php`), but there's no My Account UI for a customer to do it themselves — see [§6](#6-future-improvements).
- **No token introspection (RFC 7662) or DPoP (RFC 9449).** The module trusts the access token's local expiry and the refresh cycle; there's no live revocation check against the IdP and no proof-of-possession token binding. See [§6](#6-future-improvements).
- **No SAML support.** This module is OIDC-only by design; see [§6](#6-future-improvements) for how to add a companion SAML module that reuses the JIT provisioning services.
- **Passkeys are single-domain.** A passkey is bound to one Relying Party ID (domain) at registration time and cannot be used to log in from a different domain — multi-store installs with per-store-view domains must pick one domain for passkey login or accept it won't carry across the others.
- **No attestation validation for passkeys.** `attestation=none` means the module verifies the public key and signature, not which authenticator model registered it — a deliberate compatibility trade-off, not a planned hardening gap. See [§6](#6-future-improvements) if you need attestation trust chains.
- **No admin-configurable passkey ceremony tuning.** Challenge TTL (300s), token TTL (300s), rate-limit thresholds, and `userVerification=preferred` are all hardcoded constants — there's no admin UI to adjust them per deployment.

---

## 5. Gotchas

### 1. Admin and customer flows are separate entry points

Admin login starts from `Controller/Adminhtml/Actions/SendAuthorizationRequest.php` which stamps `loginType=admin` into the OAuth state. Customer login starts from `Controller/Actions/SendAuthorizationRequest.php` which stamps `loginType=customer`. **Both use the same callback URL** — the `loginType` in the state determines routing. Always use `getAdminSPInitiatedUrl()` for admin-intent logins.

### 2. The IdP email MUST match

For admin login, the email from the OIDC provider is matched against the `email` column in `admin_user`. No match + auto-create disabled → `ADMIN_ACCOUNT_NOT_FOUND`. Check `var/log/M2Oidc.log` with debug logging enabled.

### 3. Mixed content / callback URL protocol

The callback URL is built from `storeManager->getBaseUrl()`. If your load balancer terminates SSL but Magento sees HTTP internally, the callback URL will be `http://...` and most IdPs will reject it. Fix `web/secure/base_url` and handle `X-Forwarded-Proto` at the web server.

### 4. Attribute keys are case-sensitive

Authelia sends `preferred_username`, `email`, `name`, `groups`; other providers might send `Email`, `firstName`, etc. Check exact key names via **Test Configuration**.

### 5. Admin role mapping has no implicit fallback

If no group-to-role mapping matches and no default role is configured, admin auto-creation is **denied** (`getAdminRoleFromGroups()` returns `null`). There's no fallback to "Administrators" or role ID 1 — configure your role mappings explicitly.

### 6. OIDC-authenticated admins bypass password re-verification

`OidcIdentityVerificationPlugin` skips the "enter your current password" prompt for admins carrying the `oidc_authenticated` cookie (set on login, cleared on logout, admin-session lifetime).

### 7. There is no module-wired global cookie-rewrite observer

If you're debugging cross-origin session cookie issues, note that no `events.xml` in this module registers a global cookie-rewrite observer on `controller_front_send_response_before` or any other event. `Helper/SessionHelper.php::updateSessionCookies()` exists and performs the rewrite, but its only caller (`configureSSOSession()`) is itself unused (see Gotcha #29). If you need this behavior, write a new observer bound to an explicit event.

### 8. Debug logs auto-expire after 7 days

`Cron/LogCleanup.php` (`m2oidc_log_rotation`, `0 3 * * *`, registered in `crontab.xml`) disables logging and **deletes** (does not rotate) `var/log/M2Oidc.log` — and any per-provider `M2Oidc_<suffix>.log` files — when the log exceeds 7 days or debug logging has been disabled. You'll need to re-enable debug logging afterward.

### 9. The OAuth `state` parameter encodes multiple values

JSON+Base64 containing relay state, session ID, app name, login type, CSRF token, and provider ID. A legacy pipe-delimited fallback format is also supported — but that fallback logic lives in `ReadAuthorizationResponse.php`, not in `OAuthSecurityHelper`, if you go looking for it.

### 10. Non-OIDC admin login can be disabled (with a safety net)

`AdminLoginRestrictionPlugin` throws when `m2oidc_disable_non_oidc_admin_login` is enabled — but only if the OIDC button (`show_admin_link`) is actually visible for that provider. Hidden button → password login still allowed, preventing total lockout.

### 11. Nonce cookies are one-time use with a 300-second TTL

`oidc_admin_nonce`, `oidc_customer_nonce`, and `oidc_headless_nonce` are `HttpOnly`, `Secure`, `SameSite=Lax`, deleted immediately on use. If cookies are blocked or the user navigates away and back, expect a login-page redirect with an error.

### 12. PKCE verifier storage: cache, not session, not DB

- There is **no `pkce_code_verifier` column** in `etc/db_schema.xml`, and no PKCE-related session key in `OAuthConstants`.
- The verifier is stored via `OAuthSecurityHelper::storePkceVerifier()` in the shared **atomic cache**, keyed by a nonce carried in the `oidc_pkce_nonce` (customer) / `oidc_admin_pkce_nonce` (admin) cookie, 600s TTL, one-time-use.

If you see a reference to "session-based PKCE" or a `pkce_code_verifier` column anywhere, it does not match the current implementation.

### 13. `CheckAttributeMappingAction`/`ProcessUserAction` are invoked via `handle()`, not `execute()`

All attribute mapping and routing logic lives in `CheckAttributeMappingAction`, but the entry point is not its `execute()` method. `CheckAttributeMappingAction` still `extends BaseAction extends Magento\Framework\App\Action\Action`, so it still carries the zero-arg `execute(): ResultInterface` required by `ActionInterface` — but that override is a dead stub that unconditionally throws `\LogicException`. The real logic lives in `handle(OidcAttributeMappingContext $context): ResultInterface`, called by `ReadAuthorizationResponse`. `ProcessUserAction` doesn't extend any Magento action base class or implement `ActionInterface` at all, so it has no `execute()` whatsoever — just `handle(OidcUserProvisioningContext $context): Result\Redirect`, called by `CheckAttributeMappingAction`. If you're adding a plugin, target `CheckAttributeMappingAction::handle()`, not `execute()` — an `execute()` plugin will never fire.

### 14. Do not remove the flag reset in `OidcCredentialPlugin::beforeLogin()`

It unconditionally resets `$isOidcAuth`/`$adapterLogged` at the very start of every call, guarding against PHP-FPM worker recycling. Removing it causes sporadic OIDC adapter injection on non-OIDC logins.

### 15. Access control rules block JIT provisioning entirely

Rules are evaluated before any user lookup or creation. A denying rule means the user is never created, even on their first login with auto-create enabled.

### 16. `OidcLogoutPlugin` must use `aroundLogout`, not `afterLogout`

`Auth::logout()` destroys the session; by the time `afterLogout` runs, `oidc_id_token`/`oidc_provider_id` are already gone. If you add your own admin logout plugin and need that data, use `aroundLogout` too, with a sort order lower than 20.

### 17. Two logout URL formats — selection is heuristic-based

`end_session_endpoint` path ends with `/logout` and doesn't contain `/oauth2/` or `/oidc/` → Authelia forward-auth mode (`?rd=`). Anything else → standard OIDC (`id_token_hint`, `state`, `post_logout_redirect_uri`). If your IdP's path happens to match the heuristic but expects standard params, the redirect will be malformed — check the debug log for `mode=forward-auth(rd)` vs `mode=oidc-rp-logout`.

### 18. Single Post Logout Redirect URI — use the unified callback

Register only `https://your-site.com/m2oidc/actions/postlogout` with IdPs that allow just one URL. The controller class is `Postlogout` (`Controller/Actions/Postlogout.php`) — there is no class or file named `PostLogoutCallback`.

### 19. Zitadel sends claims Base64-encoded

Set **Claim Encoding** to `base64` in OAuth Settings if attribute values look garbled in Test Configuration. `flattenAttributes()` decodes and UTF-8-validates; falls back to the original value if decoding fails.

### 20. Zitadel roles arrive as nested objects, not a flat array

`{"role_name": {"orgId": "..."}}`, not `["role1", "role2"]`. `normalizeGroups()` extracts the top-level keys. Configure role mappings using the key name, not the nested `orgId`.

### 21. Public clients need `public_client = 1`, not just an empty secret

An empty **Client Secret** without the **Public Client** toggle still sends an empty `client_secret` parameter, which some IdPs (including Zitadel) reject.

### 22. JWKS fetch failures trigger a circuit-breaker

A 60-second `m2oidc_jwks_fail_*` cache key blocks further re-fetch attempts after a failure, so signature verification returns `null` immediately rather than hammering the IdP. Check for `JWKS circuit-breaker open` in the debug log if failures persist.

### 23. The admin callback is rate-limited too

`Oidccallback` applies the same 10/60s limit as the frontend callback, but redirects to the admin login page with an error rather than returning a bare HTTP 429.

### 24. Multi-website customer login uses website context validation

`CustomerOidcCallback` checks the authenticated customer belongs to the current Magento website — a customer created on website A cannot SSO-login on website B.

### 25. Lockout-prevention guard silently reverts invalid settings

Enabling "Disable non-OIDC admin login" with zero prior OIDC admin logins auto-resets to `0` with a warning message, not an exception.

### 26. Billing address requires all four fields

Street, ZIP, city, and country must all be mapped and non-empty, or the address object is skipped entirely (intentional — a partial address fails Magento checkout validation).

### 27. Required attribute fields block provider save

Missing `email_attribute`/`username_attribute`/`firstname_attribute`/`lastname_attribute` returns an error and doesn't persist anything.

### 28. Auto-discovery overwrites manually entered endpoints

Every save with a `well_known_config_url` set re-fetches and overwrites all endpoint fields. Clear the URL first if you need a manually-pinned custom endpoint (e.g. a nonstandard revocation URL).

### 29. `configureSSOSession()` is intentionally unused

Calling it from `SendAuthorizationRequest` to force `SameSite=None` conflicts with `session_regenerate_id()` in the callback and causes session data loss. There is no module-wired observer applying `SameSite=None` globally today (see Gotcha #7) — don't call `configureSSOSession()`, and don't assume any observer is rewriting cookies for you.

### 30. Test mode is detected from the relay state URL, not `core_config_data`

Test-mode detection compares against the `TEST_RELAYSTATE` constant rather than reading/writing an `IS_TEST` flag in the database — this avoids a stuck flag if the IdP never completes the callback.

### 31. `post_logout_url` per-provider override is admin-configurable

`OidcLogoutPlugin` and `OAuthLogoutObserver` both read `$provider['post_logout_url']` as a per-provider override for the post-logout landing page. The column exists in `etc/db_schema.xml` and is set via the "Post-Logout Landing Page" field on the provider's Login Options tab (`Block/Adminhtml/Provider/Edit/Tab/LoginOptions.php::getPostLogoutUrl()`) — when populated, it takes priority over the unified `Postlogout` callback URL for that provider.

### 32. Real Magento event names are more specific than you'd guess

If you're about to observe `customer_logout` expecting to catch the IdP-redirect logic, you'll be surprised: that event only sets a flag (`CustomerSetLogoutFlagObserver`). The actual redirect logic (`OAuthLogoutObserver`) is on `controller_action_postdispatch_customer_account_logout`. Similarly, admin login/logout auto-redirect observers are bound to `controller_action_predispatch_adminhtml_auth_login` and `adminhtml_user_logout` — not the generic names you might expect from reading the class names alone. When in doubt, grep `etc/events.xml`, `etc/frontend/events.xml`, and `etc/adminhtml/events.xml` directly rather than assuming from an observer's name what it's bound to.

### 33. Passkey and OIDC ephemeral tokens use distinct prefixes on purpose

`OAuthSecurityHelper::createOidcAuthToken()` produces `OIDC_`-prefixed tokens; `PasskeySecurityHelper::createPasskeyAuthToken()` produces `PKEY_`-prefixed tokens (69 chars total: `PKEY_` + 64 hex chars). `OidcCredentialPlugin` and `PasskeyCredentialPlugin` each do a non-consuming format check on the password argument passed to `Auth::login()` to decide whether to inject their own adapter. Don't rename or reformat either marker without updating both plugins and `AdminLoginRestrictionPlugin`'s safety-net logic (which must recognize *either* passwordless method to avoid a false "OIDC button hidden" lockout-prevention trigger).

### 34. Passkey settings live in `core_config_data`, not `system.xml`

Unlike every other admin-configurable OIDC setting, Passkey Settings (`m2oidc_passkey/general/{enabled_admin,enabled_customer,rp_name,rp_id}`) has no `etc/adminhtml/system.xml` entry — it's a fully custom admin page (`Controller/Adminhtml/Passkeysettings/*`) that reads/writes those config paths directly via `WriterInterface`/`ScopeConfigInterface`. If you go looking for a `<field>` declaration to extend it, you won't find one.

### 35. Two "delete a passkey" controllers exist per area, with different blast radii

`Controller/Actions/Passkey/Delete.php` (frontend) and `Controller/Adminhtml/Actions/Passkey/Delete.php` (admin self-service) only ever delete a credential owned by the calling user (`deleteOwnedCredential()`), scoped by `user_type`/`user_id` at the repository level. `Controller/Adminhtml/Passkeysettings/Delete.php` deletes *any* credential by row ID (`deleteById()`), gated by the `M2Oidc_OAuth::passkey_settings` ACL resource — this is the lockout-recovery path, wired to the Registered Passkeys grid, not the per-user profile pages.

### 36. Registration always requires a discoverable (resident) credential

`WebauthnCeremonyFactory::getAuthenticatorSelectionCriteria()` hardcodes `residentKey=required`. This is intentional — usernameless customer login depends on it, and the admin flow uses it to resolve the account from the credential the browser presents rather than trusting the typed email up front — but it means some older or storage-constrained security keys that don't support resident keys cannot register a passkey with this module.

---

## 6. Future Improvements

### Remaining items — not yet implemented

Ordered roughly by impact.

#### Token Introspection (RFC 7662)

**Problem**: The module trusts the access token's local expiry (stored in session) and relies on the refresh flow to detect revocation. If the IdP revokes a token between refresh cycles, Magento treats the session as valid until the next refresh attempt (up to 60 seconds before expiry).

**Scope**: Add optional RFC 7662 introspection calls in `TokenRefreshService::refreshIfNeeded()` — if an `introspection_endpoint` is configured, call it before deciding whether to refresh; destroy the session immediately on `{"active": false}`.

**Trade-off**: One extra HTTP round-trip per refresh cycle. Gate behind a per-provider `use_token_introspection` toggle.

#### Customer Account Self-Service: Link / Unlink IdP

**Problem**: There is no UI for a logged-in customer to view or change which OIDC provider is bound to their account — only direct DB manipulation or admin-side deletion.

**Scope**: A customer account section (e.g. **My Account > Login & Security**) showing the bound provider and an unlink button. Unlinking deletes the `m2oidc_oauth_user_provider` row so the next login claims a fresh binding.

**Note**: The admin-side unlink button is already implemented (`Controller/Adminhtml/Provider/UnlinkUser.php`, wired into both the Customer Edit page and the Admin User Edit page). The remaining gap is customer self-service only.

**Risk**: Gate the unlink action behind a re-authentication prompt or admin-only enablement toggle, since any customer who unlinks can re-bind to a different IdP on next login.

#### OIDC Proof-of-Possession / DPoP (RFC 9449)

**Problem**: Standard Bearer tokens are vulnerable if intercepted; DPoP binds a token to a client-held private key so a stolen token can't be replayed from a different client.

**Scope**: Optional DPoP proof generation in `AccessTokenRequest`/`Curl::callAPI()`; `JwtVerifier` would need to accept a `dpop_jkt` binding claim. Gate behind a per-provider `dpop_enabled` toggle — an advanced hardening measure for deployments handling highly sensitive data.

**Effort**: Medium-high; requires asymmetric key generation/storage on the Magento side and IdP support.

#### SAML 2.0 Support

**Out of scope for this module** — SAML is a fundamentally different protocol. If needed, build a companion `M2Oidc_Saml` module that reuses the JIT provisioning services (`AdminUserCreator`, `CustomerUserCreator`, `UserProvisioningService`) via DI injection but implements its own assertion parsing and session handling.

#### Passkey Attestation Policy and Per-Store RP ID

**Problem**: Passkey registration always requests `attestation=none`, and there is a single global Relying Party ID for the whole installation — multi-store deployments with distinct domains per store view cannot register passkeys that work across all of them, and deployments needing hardware attestation trust chains (e.g. FIDO MDS-verified authenticators for compliance) have no way to opt in.

**Scope**: Add an admin-configurable attestation conveyance preference (`none` | `indirect` | `direct`) in `WebauthnCeremonyFactory`, plus attestation statement format support beyond `NoneAttestationStatementSupport`. For multi-domain RP support, allow a per-store-view RP ID override in `PasskeyConfig` instead of the single global `m2oidc_passkey/general/rp_id` value.

**Trade-off**: Direct/indirect attestation requires shipping and maintaining a FIDO Metadata Service (MDS) trust store; per-store RP IDs mean a passkey still won't carry across domains, it just becomes configurable which domain each store uses.

**Effort**: Low for a configurable attestation preference; medium for per-store RP ID support (touches `PasskeyConfig`, `WebauthnCeremonyFactory`, and the Passkey Settings save/read path).

---
