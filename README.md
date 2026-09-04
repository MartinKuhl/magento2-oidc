# Magento 2 OIDC Single Sign-On Module

<p align="center">
  <img src="view/adminhtml/web/images/m2oidc_logo.png" alt="M2Oidc Logo" width="160" />
</p>

Enterprise-grade OpenID Connect authentication for Magento 2, supporting both customer (frontend) and admin (backend) users with automatic user provisioning and role management.

## Overview

### Why This Module?

Modern e-commerce platforms require secure, centralized authentication. This module bridges Magento 2 with your corporate Identity Provider (IdP), eliminating password management overhead while enhancing security.

**Key Benefits:**

- **Unified Identity Management**: Single source of truth for user authentication across your organization
- **Zero-Touch Provisioning**: Automatically create customer and admin accounts on first login
- **Enhanced Security**: Eliminate password-based attacks, enforce IdP-level MFA, centralize access control
- **Seamless Integration**: Works with Magento's native authentication—all security events fire correctly
- **Rich Attribute Mapping**: Map 30+ OIDC claims to Magento fields (address, phone, date of birth, customer groups)
- **Compliance Ready**: Centralized audit trails, GDPR-compliant identity management

### Key Features

- ✅ **Dual Authentication Flows**: Separate customer (frontend) and admin (backend) SSO
- ✅ **IdP-Initiated SSO**: OIDC Third-Party Initiated Login (§4) — users can start the flow from the IdP portal, per-provider toggle
- ✅ **Token Auto-Refresh**: Access tokens silently refreshed on every request before expiry (frontend and adminhtml)
- ✅ **Multi-Provider Support**: Multiple OIDC providers per Magento installation with per-provider settings
- ✅ **Auto-Discovery**: Auto-populate endpoints from OIDC `.well-known/openid-configuration`
- ✅ **Just-in-Time (JIT) Provisioning**: Auto-create users with group/role mapping
- ✅ **OIDC Group Mapping**: Map IdP groups to Magento admin roles and customer groups
- ✅ **Security Enhancements**: CAPTCHA bypass, password verification bypass for OIDC users
- ✅ **Lockout-Prevention Guards**: Prevents enabling OIDC-only mode when no OIDC users exist yet — enforced consistently on the manual provider save form and on both configuration-import paths (CLI `oidc:config:import` and the Sign In Settings admin import), which also validate imported endpoint URLs and reject unsafe values before writing anything to the database
- ✅ **Cross-Origin Session Handling**: SameSite=None cookies scoped to OIDC routes only
- ✅ **JWT Token Verification**: RS256/384/512 signatures with JWKS caching
- ✅ **RP-Initiated Logout**: Admin and customer logout with IdP redirect and RFC 7009 token revocation; unified single Post Logout Redirect URI (`/m2oidc/actions/postlogout`) for providers that only allow one registered URL
- ✅ **Back-Channel Logout**: Server-to-server logout via signed JWT logout token
- ✅ **Claims-Based Access Control**: Rules engine with 6 operators to gate login on any claim value
- ✅ **Per-User IdP Binding**: The IdP that first authenticates a user is permanently bound to that account — cross-IdP login attempts are rejected
- ✅ **Optional OIDC-Only Mode**: Disable password logins entirely (with lockout safety net)
- ✅ **Session Activity View**: Admin UI listing all users who authenticated via OIDC, with per-record deletion
- ✅ **Dirty-Field Tracking**: Visual amber highlights on modified provider form fields before save
- ✅ **Comprehensive Debug Logging**: Detailed flow logs for troubleshooting, with automatic log rotation via a daily cron job; optional JSON Lines mode (`oidc/logging/json_lines`) for structured log ingestion
- ✅ **Test Configuration UI**: Verify OIDC claims before production deployment
- ✅ **OIDC Discovery Auto-Refresh**: Endpoints automatically re-fetched every 6 hours via cron — no manual provider re-save needed when IdP endpoints change
- ✅ **Health-Check Webhook Alerting**: Configure a webhook URL (Slack, PagerDuty, or any HTTP endpoint) and a failure threshold per provider; after that many consecutive reachability-check failures (checked every 5 minutes), the module posts a JSON alert, plus an optional one-time recovery notification once the provider is reachable again — no repeated alerts while the outage continues
- ✅ **Per-Attribute Sync Control**: Fine-grained `sync_on_sso` flag per mapped attribute in the normalized mappings table, enabling selective sync instead of all-or-nothing toggles
- ✅ **Per-Provider Attribute Mapper Overrides**: Third-party modules can inject custom `AttributeMapperInterface` implementations per OIDC provider via DI (`MapperPool`)
- ✅ **Sliding-Window Rate Limiter**: Redis deployments can switch to the `OidcSlidingWindowRateLimiter` virtual type for burst-tolerant sliding-window rate limiting
- ✅ **Atomic Token Operations**: `AtomicCacheInterface` eliminates the TOCTOU race condition in one-time token consumption (nonces, state tokens, PKCE verifiers) — Redis deployments use Lua `GETDEL`
- ✅ **Passkey (WebAuthn/FIDO2) Login**: Passwordless sign-in for both admin and customer users, independent of any external IdP — self-service registration and login backed by `web-auth/webauthn-lib`, bridged into Magento's native authentication the same way OIDC is

### Supported Identity Providers

- Authelia
- Keycloak
- Auth0
- Okta
- Azure Active Directory (Azure AD)
- Google Workspace
- Zitadel (including Base64-encoded claims and nested role objects)
- Any OIDC-compliant Identity Provider

---

## Requirements

- **PHP**: 8.2 – 8.5 (per `composer.json`'s `require.php` constraint: `~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`)
- **Magento**: 2.4.7 or higher
- **Identity Provider**: OIDC-compliant IdP (OpenID Connect 1.0)
- **HTTPS**: Required for production (SameSite=None cookies require Secure flag)

---

## Installation

### Step 1: Install via Composer

```bash
composer require martinkuhl/magento2-oidc-sso
bin/magento module:enable M2Oidc_OAuth
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Step 2: Register URLs with Your Identity Provider

Register the following URLs in your IdP's OAuth/OIDC client configuration. Only the **Redirect URI** is required; all others are optional depending on the features you want to use.

| URL | IdP field | Required | Feature |
|-----|-----------|----------|---------|
| `https://your-magento-site.com/m2oidc/actions/ReadAuthorizationResponse` | Redirect URI / Callback URL | **Yes** | Authorization code callback |
| `https://your-magento-site.com/m2oidc/actions/postlogout` | Post Logout Redirect URI | Optional | RP-Initiated Logout (unified admin + customer) |
| `https://your-magento-site.com/m2oidc/actions/backchannellogout` | Back-Channel Logout URI | Optional | Server-side session termination (OIDC CIBA) |
| `https://your-magento-site.com/m2oidc/actions/idpInitiatedLogin?provider_id=<id>` | Initiate Login URI | Optional | IdP-Initiated SSO (OIDC §4) |

Replace `your-magento-site.com` with your actual domain. The protocol **must be HTTPS** in production.

**Notes:**

- **Redirect URI**: the only URL every IdP integration requires. Some IdPs label this "Callback URL" or "Allowed Redirect URL".
- **Post Logout Redirect URI**: this single URL handles both admin and customer post-logout redirects automatically (context is carried in the OIDC `state` parameter). If your IdP allows multiple URIs you can also register the admin base URL (`/admin/`) and customer login URL (`/customer/account/login/`) directly, but the unified endpoint is recommended when only one URL is permitted.
- **Back-Channel Logout URI**: the IdP POSTs a signed JWT logout token to this URL to terminate the user's Magento session server-side — useful when the user signs out of the IdP from another device or application. Rate-limited to 10 requests per 60 seconds.
- **Initiate Login URI**: register this URL to allow users to start the SSO flow from the IdP portal. Replace `<id>` with the numeric provider ID shown in **M2 OIDC > Manage Providers**. You must also enable the **IdP-Initiated SSO** toggle (`idp_initiated_enabled`) in the provider's Login Options tab.

> **Health check** (not registered with IdP): `https://your-magento-site.com/m2oidc/health/check` — returns JSON status of active providers; useful for uptime monitoring.

### Step 3: Configure Magento

1. Navigate to **M2 OIDC > Manage Providers**

2. Fill in **OAuth Settings**:
   - **App Name**: Identifier for your IdP (e.g., `authelia`, `keycloak`)
   - **Client ID**: OAuth client ID from your IdP
   - **Client Secret**: OAuth client secret from your IdP
   - **Well-Known Config URL** *(optional)*: Paste your IdP's discovery endpoint (e.g., `https://auth.example.com/.well-known/openid-configuration`) and save — all endpoints will be auto-populated
   - **Authorize Endpoint**: Authorization endpoint URL (e.g., `https://auth.example.com/api/oidc/authorization`)
   - **Token Endpoint**: Token exchange endpoint URL (e.g., `https://auth.example.com/api/oidc/token`)
   - **User Info Endpoint**: User information endpoint URL (e.g., `https://auth.example.com/api/oidc/userinfo`)
   - **Scope**: OAuth scopes (typically: `openid profile email groups`)

3. **Save Config**

> **Tip — Auto-Discovery**: If your IdP supports OIDC discovery (most do), enter only the **Well-Known Config URL** and save. The module will automatically fetch and populate all endpoint URLs, JWKS endpoint, and issuer from the discovery document.

### Step 4: Test Configuration

1. Click **Test Configuration** button in the OAuth Settings page
2. You'll be redirected to your IdP for authentication
3. On successful return, you'll see all OIDC claims received from your IdP
4. Use this to verify claim names for attribute mapping (next step)

### Step 5: Configure Attribute Mapping

1. Navigate to **Stores > Configuration > M2Oidc > OAuth/OIDC > Attribute Mapping**

2. Map OIDC claims to Magento fields:
   - **Email Attribute**: Claim containing user email (default: `email`)
   - **Username Attribute**: Claim containing username (default: `preferred_username`)
   - **First Name Attribute**: Claim containing first name (default: `firstName` or split from `name`)
   - **Last Name Attribute**: Claim containing last name (default: `lastName` or split from `name`)
   - **Group Attribute**: Claim containing group memberships (default: `groups`)

3. **(Optional)** Configure address mappings for customer auto-population:
   - Billing and shipping address fields (city, state, country, address, phone, zip)
   - Date of birth, gender, phone mappings

4. **Save Config**

### Step 6: Configure Sign-In Settings

1. Navigate to **Stores > Configuration > M2Oidc > OAuth/OIDC > Sign In Settings**

2. Configure auto-provisioning:
   - **Auto Create Customers**: Enable to automatically create customer accounts on first login
   - **Auto Create Admin Users**: Enable to automatically create admin accounts on first login
   - **Default Customer Group**: Group assigned to new customers if no OIDC group mapping matches
   - **Default Admin Role**: Role assigned to new admins if no OIDC group mapping matches

3. Configure SSO buttons:
   - **Show Customer SSO Link**: Display "Login with SSO" button on customer login page
   - **Show Admin SSO Link**: Display "Login with SSO" button on admin login page

4. **(Optional)** Enable OIDC-only mode:
   - **Disable Non-OIDC Admin Logins**: Force all admins to use OIDC (⚠️ create emergency admin via CLI first)
   - **Lockout Guard**: The module automatically reverts this setting if no OIDC admin users exist yet for the provider, preventing accidental lockout

5. **Enable Debug Logging** (recommended for initial setup):
   - Check **Enable debug logging** to write detailed flow logs to `var/log/M2Oidc.log`

6. **Save Config**

---

## Configuration Guide

### OAuth Settings

| Setting | Description | Example |
|---------|-------------|---------|
| App Name | Identifier for your IdP | `authelia`, `keycloak` |
| Client ID | OAuth client ID from IdP | `magento-store` |
| Client Secret | OAuth client secret (encrypted in database) | `your-secret-here` |
| Authorize Endpoint | IdP authorization URL | `https://auth.example.com/api/oidc/authorization` |
| Token Endpoint | IdP token exchange URL | `https://auth.example.com/api/oidc/token` |
| User Info Endpoint | IdP user information URL | `https://auth.example.com/api/oidc/userinfo` |
| Scope | OAuth scopes (space-separated) | `openid profile email groups` |
| JWKS Endpoint | (Optional) JWT verification keys URL | `https://auth.example.com/api/oidc/jwks` |
| Logout URL | (Optional) IdP logout URL | `https://auth.example.com/api/oidc/logout` |
| Claim Encoding | Set to `base64` for providers that Base64-encode claim values (e.g. Zitadel) | `none` (default), `base64` |
| HTTP Timeout (seconds) | Per-provider connect/read timeout for token endpoint and JWKS calls. A single retry fires after 500 ms on empty response. Min 5, Max 300. | `30` (default) |
| Public Client | Enable for providers that use PKCE without a client secret (RFC 6749 §2.1) | `No` (default) |

**Tip**: Most OIDC providers support auto-discovery via `.well-known/openid-configuration`. Check your IdP's documentation.

### Attribute Mapping

Map OIDC claims (from your IdP) to Magento user fields:

#### Core User Attributes

- **Email Attribute**: OIDC claim containing email (required, default: `email`)
- **Username Attribute**: OIDC claim containing username (default: `preferred_username`)
- **First Name Attribute**: OIDC claim for first name (default: `firstName`)
- **Last Name Attribute**: OIDC claim for last name (default: `lastName`)
- **Group Attribute**: OIDC claim containing group memberships (default: `groups`)

#### Customer Profile Attributes (Optional)

- **Date of Birth Attribute**: Claim for DOB, format YYYY-MM-DD (default: `birthdate`)
- **Gender Attribute**: Claim for gender (default: `gender`)
- **Phone Attribute**: Claim for phone number (default: `phone_number`)

#### Customer Address Attributes (Optional)

Map billing and shipping addresses (30+ fields total):

- **Billing Address**: `billing_city`, `billing_state`, `billing_country`, `billing_address`, `billing_phone`, `billing_zip`
- **Shipping Address**: `shipping_city`, `shipping_state`, `shipping_country`, `shipping_address`, `shipping_phone`, `shipping_zip`

**Tip**: Use **Test Configuration** to see actual claim names from your IdP. Claim names are **case-sensitive**.

### Sign-In Settings

#### Auto-Provisioning

- **Auto Create Customers**: Automatically create Magento customer accounts on first OIDC login
- **Auto Create Admin Users**: Automatically create Magento admin accounts on first OIDC login
- **Default Customer Group**: Fallback group if no OIDC group mapping matches
- **Default Admin Role**: Fallback role if no OIDC group mapping matches

#### Group/Role Mapping

**Admin Role Mapping**:
1. Set **Group Attribute Name** to the OIDC claim containing groups (e.g., `groups`, `roles`, `memberOf`)
2. Add mappings: **OIDC Group** → **Magento Admin Role**
   - Example: `Engineering` → `Content Editors`
   - Example: `Finance` → `Sales`
   - Matching is case-insensitive

**Customer Group Mapping**:
- Similar to admin role mapping, maps OIDC groups to Magento customer groups
- Example: `VIP` → `VIP Customer Group` (with special pricing)

#### SSO Button Visibility

- **Show Customer SSO Link**: Display "Login with SSO" button on customer login page
- **Show Admin SSO Link**: Display "Login with SSO" button on admin login page

### Passkey Settings

Passkeys (WebAuthn/FIDO2) are a passwordless login method independent of any external IdP — a device's fingerprint reader, face recognition, screen lock, or a security key stands in for a password. Configure them at **M2 OIDC > Passkey Settings**:

- **Enable Passkey Login for Admin**: Shows a "Login with Passkey" button on the admin login page and lets admins register their own passkeys from their user profile
- **Enable Passkey Login for Customers**: Shows a "Login with Passkey" button on the customer login page and adds a **My Account > Passkeys** self-service page
- **Relying Party Name**: The name shown by the browser/OS passkey prompt (defaults to the store name)
- **Relying Party ID (domain) Override**: The registrable domain passkeys are bound to (defaults to the store base URL's host). A single RP ID ties passkeys to one domain — see the multi-store note below
- **Registered Passkeys**: A grid listing every passkey registered across both admin and customer accounts, with a Delete action — intended for lockout recovery when a user loses their device, not day-to-day management (users manage their own passkeys from their profile / My Account page)

**Multi-store domains**: a passkey registered under one domain will not authenticate on a different domain. Multi-store installs using different domains per store view must either share one primary domain for passkey login or accept that passkeys don't carry across them.

**Requirements**: HTTPS (WebAuthn requires a secure context; `localhost` is exempted for local development) and a browser with WebAuthn support (current Chrome, Edge, Safari, Firefox). The "Login with Passkey" button stays hidden automatically in unsupported browsers.

#### OIDC-Only Mode (Advanced)

- **Disable Non-OIDC Admin Logins**: Force all admins to use OIDC authentication
- ⚠️ **Warning**: Create an emergency admin via CLI before enabling this:
  ```bash
  bin/magento admin:user:create
  ```

#### Debug Logging

- **Enable debug logging**: Writes detailed flow logs to `var/log/M2Oidc.log`
- **Auto-expires**: A daily cron job (`m2oidc_log_rotation`, implemented by `Cron\LogCleanup`) cleans up the log at 03:00 server time — deletes the file when older than 7 days or when debug logging is disabled
- **JSON Lines mode**: Enable `oidc/logging/json_lines` in `core_config_data` to emit raw newline-delimited JSON (`{"ts":"...","level":"debug","message":"..."}`) for structured log ingestion (e.g., Loki, Elasticsearch, Datadog)
- **Auto-refresh discovery**: A separate cron job (`m2oidc_refresh_oidc_discovery`) re-fetches `.well-known/openid-configuration` for all active providers every 6 hours, keeping endpoints up-to-date automatically
- **Privacy**: Contains user emails and OIDC claims — handle securely

---

## Usage Examples

### Customer Login Flow

1. **Customer clicks "Login with SSO"** on frontend login page
2. **Magento redirects** to IdP authorization endpoint
3. **Customer authenticates** at IdP (username/password, MFA if configured)
4. **IdP redirects back** to Magento callback URL with authorization code
5. **Magento exchanges** authorization code for access token and ID token
6. **Magento extracts** user information from tokens (email, name, groups, etc.)
7. **Magento creates or matches** customer account:
   - If email exists: logs in existing customer
   - If email doesn't exist and auto-create enabled: creates new customer with mapped attributes
8. **Customer session established**, redirected to original page (shopping cart, checkout preserved)

### Admin Login Flow

1. **Admin navigates** to `/admin` and clicks "Login with SSO"
2. **Magento redirects** to IdP (same as customer flow)
3. **Admin authenticates** at IdP
4. **IdP redirects back** to Magento callback URL
5. **Magento exchanges** authorization code for tokens
6. **Magento checks** if email exists in `admin_user` table:
   - If exists: proceeds to admin login
   - If doesn't exist and auto-create enabled: creates admin user with role mapping
7. **Magento calls** native `Auth::login()` with OIDC marker
8. **Security plugins activate**:
   - CAPTCHA automatically bypassed (user already authenticated at IdP)
   - All standard Magento authentication events fire correctly
9. **Admin session established**, redirected to admin dashboard

### Passwordless Login (Passkeys)

Passkeys are configured and enabled independently of OIDC — no external IdP required.

**Customer flow:**
1. A logged-in customer opens **My Account > Passkeys** and clicks **Register a New Passkey**
2. The browser prompts for the device's fingerprint, face recognition, screen lock, or a security key; the customer names the passkey (e.g. "My Phone")
3. On a later visit, the customer clicks **Login with Passkey** on the login page — no email/username needed (usernameless/discoverable login); the browser resolves the matching passkey and the customer is signed in

**Admin flow:**
1. An already-authenticated admin registers a passkey from their user profile page
2. On a later visit, the admin enters their email and clicks **Login with Passkey** on the admin login page; login is scoped to that admin's own registered passkeys
3. All standard Magento admin auth events fire, exactly as with OIDC login — CAPTCHA, password re-verification, password expiration, and forced password-change prompts are all bypassed for passkey logins the same way they are for OIDC

**Lockout recovery**: an administrator with access to **M2 OIDC > Passkey Settings** can delete any user's passkey (e.g. after a lost device) from the Registered Passkeys grid, without needing database access.

### Custom SSO Button in Your Theme

Add an SSO button to your custom theme:

**Layout XML** (`app/design/frontend/YourVendor/YourTheme/Magento_Customer/layout/customer_account_login.xml`):

```xml
<referenceBlock name="customer_form_login">
    <arguments>
        <argument name="oauth_helper" xsi:type="object">M2Oidc\OAuth\Helper\OAuthUtility</argument>
    </arguments>
</referenceBlock>
```

**Template** (`.phtml` file):

```php
<?php
/** @var \M2Oidc\OAuth\Helper\OAuthUtility $oauthHelper */
$oauthHelper = $block->getData('oauth_helper');
$customerLoginUrl = $oauthHelper->getSPInitiatedUrl();
?>

<div class="sso-login-button">
    <a href="<?= $escaper->escapeUrl($customerLoginUrl) ?>"
       class="action primary">
        <span><?= $escaper->escapeHtml(__('Login with SSO')) ?></span>
    </a>
</div>
```

---

## Troubleshooting

### Issue 1: "Callback URL mismatch" Error

**Symptom**: After IdP authentication, you see an error about callback URL mismatch.

**Cause**: Callback URL registered in IdP doesn't match the URL Magento is sending.

**Solution**:
1. Verify callback URL in IdP configuration matches exactly:
   ```
   https://your-magento-site.com/m2oidc/actions/ReadAuthorizationResponse
   ```
2. Check protocol (HTTP vs HTTPS)—**must use HTTPS in production**
3. Check for trailing slashes (some IdPs are strict about this)
4. If behind a load balancer, verify `X-Forwarded-Proto` header handled correctly

---

### Issue 2: "Attribute not found" / Empty User Profile

**Symptom**: User logs in successfully but profile fields are empty, or you see "attribute not found" errors.

**Cause**: OIDC claim names from IdP don't match your attribute mapping configuration.

**Solution**:
1. **Enable debug logging**: **Stores > Configuration > M2Oidc > OAuth/OIDC > Sign In Settings > Enable debug logging**
2. Click **Test Configuration** to see actual claim names from your IdP
3. Check `var/log/M2Oidc.log` for lines like:
   ```
   Received OIDC claims: {"email": "user@example.com", "preferred_username": "user", ...}
   ```
4. Update **Attribute Mapping** to match actual claim names (**case-sensitive**)
5. Common mismatches:
   - `email` vs `Email`
   - `firstName` vs `given_name` vs `name`
   - `groups` vs `roles` vs `memberOf`

---

### Issue 3: Admin Auto-Creation Fails

**Symptom**: OIDC authentication succeeds but admin account not created, error "Admin account not found".

**Cause**: Role mapping failed—no suitable Magento admin role found for user's OIDC groups.

**Solution**:
1. **Check logs**: `var/log/M2Oidc.log` will show:
   ```
   AdminUserCreator: No suitable role found for user. Creation aborted.
   ```
2. Verify **Group Attribute Name** configured correctly (e.g., `groups`, `roles`)
3. Verify user's IdP profile includes group information in that claim
4. Check **Admin Role Mapping**:
   - At least one mapping should match user's groups
   - Or set a **Default Admin Role** as fallback
5. Test with **Test Configuration** to see if groups claim present

**Example configuration**:
- IdP sends: `{"groups": ["Engineering", "Developers"]}`
- Attribute Mapping: **Group Attribute Name** = `groups`
- Role Mapping: `Engineering` → `Content Editors` role

---

### Issue 4: "Session expired" on Callback

**Symptom**: After IdP authentication, you're redirected back to Magento but see "session expired" or login page again.

**Cause**: Cross-origin cookie issues—session cookie not preserved across IdP redirect.

**Solution**:
1. **Verify HTTPS enabled**—SameSite=None cookies require Secure flag (HTTPS only)
2. Check browser console for cookie warnings:
   ```
   Cookie "PHPSESSID" has been rejected because it is in a cross-site context and its "SameSite" is "Lax" or "Strict"
   ```
3. Module automatically sets `SameSite=None; Secure; HttpOnly` on all cookies
4. If behind a load balancer, verify SSL termination configured correctly
5. Test in different browser (some browsers block third-party cookies by default)

---

### Issue 5: Non-OIDC Logins Stopped Working

**Symptom**: Password-based admin logins no longer work, only OIDC login accepted.

**Cause**: "Disable non-OIDC admin logins" setting enabled.

**Solution**:
1. **Check setting**: **Stores > Configuration > M2Oidc > OAuth/OIDC > Sign In Settings > Disable Non-OIDC Admin Logins**
2. If enabled and you need password login:
   - Temporarily disable via database:
     ```sql
     UPDATE m2oidc_oauth_client_apps SET disableNonOidcAdminLogin = 0;
     ```
   - Or create emergency admin via CLI:
     ```bash
     bin/magento admin:user:create
     ```
3. **Safety net**: If "Show Admin SSO Link" is disabled, password logins automatically allowed (prevents lockout)

---

### Issue 6: "Login failed — different identity provider"

**Symptom**: Login is rejected with "this account was created with a different identity provider."

**Cause**: Per-user IdP binding is enforced. The account was originally authenticated (or created) via a different OIDC provider than the one currently being used.

**Solution**:
1. Direct the user to log in via the provider that originally created their account. Check `m2oidc_oauth_user_provider` to see which provider (`provider_id`) is bound to their account.
2. If a migration to a new IdP is intended, update the binding in the database:
   ```sql
   UPDATE m2oidc_oauth_user_provider SET provider_id = <new_id>
   WHERE user_type = 'customer' AND user_id = <magento_customer_id>;
   ```
3. If you want to clear all bindings and let the next login re-claim them:
   ```sql
   DELETE FROM m2oidc_oauth_user_provider WHERE provider_id = <old_id>;
   ```
4. Check `var/log/M2Oidc.log` for "Provider mismatch" entries to identify affected users.

---

### Issue 7: Post-Logout Redirect Rejected by IdP

**Symptom**: After clicking "Sign Out", the IdP shows an error about an invalid or unregistered `post_logout_redirect_uri`.

**Cause**: The IdP requires all Post Logout Redirect URIs to be pre-registered, and the URL being sent is not in the allowed list.

**Solution**:
1. Register the unified callback URL in your IdP's client configuration:
   ```
   https://your-magento-site.com/m2oidc/actions/postlogout
   ```
2. If your IdP only allows one Post Logout Redirect URI, this single URL handles both admin and customer flows automatically.
3. Verify in `var/log/M2Oidc.log` — look for `redirect=` in the logout log line to see the exact URL being sent.
4. If using Authelia (`endsession_endpoint` path ends with `/logout`), no Post Logout Redirect URI registration is needed — Authelia uses the `?rd=` parameter instead.

---

---

### Issue 8: "Login with Passkey" Button Doesn't Appear

**Symptom**: The Passkey login button is missing from the admin or customer login page even though it's enabled.

**Cause**: Either the corresponding toggle is off, or the browser doesn't support WebAuthn (the button hides itself via feature detection).

**Solution**:
1. Verify **Enable Passkey Login for Admin** / **Enable Passkey Login for Customers** is checked at **M2 OIDC > Passkey Settings**
2. Confirm the site is served over HTTPS — WebAuthn requires a secure context (`localhost` is exempted for local development)
3. Test in a current version of Chrome, Edge, Safari, or Firefox; older browsers without `PublicKeyCredential` support will never show the button
4. If a customer or admin registered a passkey on a different domain (e.g. staging vs. production), it will not appear as usable here — see Issue 9

---

### Issue 9: Passkey Registered But Login Fails ("This passkey is not registered")

**Symptom**: A user registered a passkey successfully but can't log in with it later, or the browser doesn't offer it as an option.

**Cause**: Passkeys are bound to a single Relying Party ID (domain). If the **Relying Party ID (domain) Override** was changed after registration, or the site is reached via a different domain (e.g. `www.` vs. bare domain, or a staging URL), previously registered passkeys will not validate.

**Solution**:
1. Keep the **Relying Party ID** stable — set it explicitly at **M2 OIDC > Passkey Settings** rather than relying on the auto-detected store base URL if the site is reachable under multiple hostnames
2. Have the user re-register a passkey under the current domain
3. For multi-store installs with different domains per store view, either standardize on one domain for passkey login or accept that passkeys don't carry across store domains

---

### Where to Get Help

1. **Check logs**:
   - `var/log/M2Oidc.log` — OIDC flow details (enable debug logging first)
   - `var/log/system.log` — General Magento system logs
   - `var/log/exception.log` — PHP exceptions and errors

2. **Enable debug logging**:
   - **Stores > Configuration > M2Oidc > OAuth/OIDC > Sign In Settings > Enable debug logging**
   - Logs auto-expire after 7 days

3. **Test Configuration**:
   - Click **Test Configuration** in OAuth Settings to verify IdP connectivity and claim structure

4. **Review documentation**:
   - [TECHNICAL_DOCUMENTATION.md](TECHNICAL_DOCUMENTATION.md) — Detailed API reference and architecture
   - [CLAUDE.md](CLAUDE.md) — Developer guidance for code modifications

5. **Contact support**:
   - GitHub Issues: (repository URL)
   - Commercial Support: M2Oidc support portal

---

## High Availability / Multi-Server Deployments

When running Magento on multiple web nodes behind a load balancer, OIDC state (tokens, nonces, PKCE verifiers) must be shared across all nodes.

### Required: Redis Cache Backend

Configure Magento's cache backend to use Redis (`app/etc/env.php`):

```php
'cache' => [
    'frontend' => [
        'default' => [
            'backend' => 'redis',
            'backend_options' => ['server' => '127.0.0.1', 'port' => '6379', 'database' => '0'],
        ],
    ],
],
```

The module's `RedisAtomicCache` (default `AtomicCacheInterface` implementation) opens its own dedicated Redis connection directly from `cache/frontend/default/backend_options` above — independent of whichever cache backend/frontend class Magento constructs internally — and uses `GETDEL` (or an equivalent Lua script) for **true atomic** read-and-delete of one-time tokens across all nodes. When that connection is unavailable it transparently falls back to sequential load + remove and logs a CRITICAL warning — **no configuration change required** for single-server/non-Redis setups.

### Required: Redis Session Backend

OIDC session data (id_token, access_token, provider_id) must also be shared:

```php
'session' => [
    'save'  => 'redis',
    'redis' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '2'],
],
```

### Rate Limiter

The default `FixedWindowStrategy` reads from Magento's shared cache — it becomes HA-safe automatically once the Redis cache backend is configured. No DI change needed.

---

## Security Considerations

### HTTPS is Required

OAuth/OIDC **requires HTTPS** in production:
- IdP callback URLs must use HTTPS protocol
- SameSite=None cookies (required for cross-origin flows) only work with Secure flag
- Unencrypted HTTP will fail with most IdPs

**Development exception**: Some IdPs allow HTTP for `localhost` testing only.

### Token Storage

- **Access tokens**, **ID tokens**, and **refresh tokens** stored in PHP session only, never in database
- **Session lifetime**: Standard Magento session timeout (default: 86400 seconds = 24 hours)
- **Refresh tokens**: Stored in session (`oidc_refresh_token`) and used by `TokenRefreshService` / `AdminTokenRefreshService` for silent token renewal
- **Client secrets**: The OAuth `client_secret` is always Magento-encrypted at rest, regardless of which admin page saved it. A setup patch runs automatically on `bin/magento setup:upgrade` and encrypts any provider row that still has a plaintext secret — no manual action needed.

### JWT Verification

Module validates JWT tokens using:
- **Signature verification**: RS256/384/512 algorithms with JWKS keys from IdP
- **Expiration validation**: Rejects expired tokens
- **Issuer validation**: Verifies token issued by configured IdP
- **Audience validation**: Ensures token intended for this Magento instance
- **Public client support**: PKCE flows without `client_secret` are supported (RFC 6749 §2.1) — enable the **Public Client** setting per provider

### CSRF Protection

OAuth `state` parameter includes session ID to prevent CSRF attacks:
- State format: `encodedRelayState|sessionId|encodedAppName|loginType`
- Session ID validated on callback—rejects requests with mismatched session

### Rate Limiting

Authentication endpoints are protected by IP-based rate limiting via the `OidcRateLimiter` strategy pattern:
- **Default strategy**: Fixed-window — 10 attempts per 60-second window (`FixedWindowStrategy`; safe for all cache backends)
- **Redis deployments**: Inject `OidcSlidingWindowRateLimiter` DI virtual type to switch to a true sliding-window strategy (`SlidingWindowStrategy`, Lua-based) for burst tolerance
- **Protected endpoints**: Customer callback (`ReadAuthorizationResponse`), admin callback (`Oidccallback`), back-channel logout (`BackChannelLogout`), front-channel logout (`FrontChannelLogout`), IdP-initiated login (`IdpInitiatedLogin`), and the headless callback (`HeadlessOidcCallback`)
- Requests that exceed the limit receive an error response before any token processing occurs

### Emergency Access

**Before enabling OIDC-only mode**:
1. Create emergency admin account via CLI:
   ```bash
   bin/magento admin:user:create
   ```
2. Test OIDC login flow thoroughly
3. Document emergency access procedure

**During IdP outage**:
- Emergency admin account can still log in via password (if OIDC-only mode not enabled)
- Or temporarily disable OIDC-only via database query

### Per-User IdP Binding

When multiple OIDC providers are configured, each Magento account is permanently bound to the IdP that first authenticated it:

- **First login wins**: the IdP that creates (or first claims via OIDC login) an account is recorded in `m2oidc_oauth_user_provider`.
- **Cross-IdP rejection**: if the same email exists in two IdPs (e.g., Zitadel and Authelia), login via the second IdP is rejected with a clear error message.
- **Pre-existing accounts**: a Magento account created before OIDC was set up has no binding — the first IdP to authenticate it claims the binding permanently.
- **Why this matters**: without binding, the effective security level of an account is the lowest common denominator of all IdPs. Revoking a user in one IdP would leave them able to log in via another.
- **Manual override**: an admin can change a user's binding by updating the `provider_id` column in `m2oidc_oauth_user_provider` directly.

### Passkey (WebAuthn) Security

- **Public-key cryptography, not shared secrets**: the server only ever stores a public key and a signature counter — there is no password or shared secret to leak, and credentials are phishing-resistant by design (bound to the origin, so a look-alike domain cannot obtain a valid assertion)
- **Discoverable credentials only**: registration requires a resident/discoverable key (`residentKey=required`), enabling usernameless customer login and allowing the server to resolve the account from the credential the browser presents rather than trusting client-supplied identity up front
- **Attestation not verified**: attestation conveyance is `none` — the module only needs the public key, not a hardware provenance/trust chain; this is a deliberate trade-off for broad authenticator compatibility, not a gap in the login itself
- **Single Relying Party ID per install**: passkeys are cryptographically bound to one domain (the RP ID) and cannot be used across different domains — see [Configuration Guide > Passkey Settings](#passkey-settings)
- **Ephemeral bridging tokens**: like OIDC, a successful passkey assertion is bridged into native Magento authentication via a single-use, cache-backed token (5-minute TTL, `PKEY_`-prefixed) — never a real password — so `Auth::login()`, all its events, and CAPTCHA/password-flow bypasses behave identically to the OIDC path, with a distinct token format so the two methods can never be confused for one another
- **Rate limiting**: passkey login and assertion-option endpoints share the same IP-based rate limiter as the OIDC callback (10 attempts / 60s)
- **Automatic cleanup**: all of a user's registered passkeys are deleted when their Magento account (admin or customer) is deleted

### IdP Security is Your Security

Module inherits IdP's security policies:
- **MFA**: Configure at IdP level (TOTP, SMS, push, biometric)
- **Conditional Access**: IdP can enforce device trust, location-based access, etc.
- **Audit Logs**: IdP maintains authentication audit trail
- **Access Revocation**: Disable user at IdP—immediately affects all integrated systems

---

## Known Limitations

### SP-Initiated and IdP-Initiated Flows Supported

- **SP-initiated** SSO (user starts at Magento) is the default flow
- **IdP-initiated** SSO (user starts at IdP portal) is now supported via `Controller/Actions/IdpInitiatedLogin.php`; register `https://<store>/m2oidc/actions/idpInitiatedLogin?provider_id=<id>` as the redirect URL in your IdP. Enable per provider in the Login Options tab (`idp_initiated_enabled` setting).

### Token Auto-Refresh

- Access tokens are automatically refreshed before expiry on every controller dispatch (frontend: `TokenAutoRefreshObserver`; adminhtml: `AdminTokenAutoRefreshObserver`)
- Refresh happens 60 seconds before expiry using the stored refresh token

### Passkeys Are Single-Domain and Unattested

- A passkey is bound to one Relying Party ID (domain) and cannot be used to log in on a different domain — see [Configuration Guide > Passkey Settings](#passkey-settings)
- Attestation is not verified (conveyance `none`), so the module cannot distinguish which make/model of authenticator registered a credential — only that a valid public key was presented
- There is no admin-configurable challenge timeout, attestation policy, or per-endpoint rate-limit tuning for passkeys; login/registration endpoints reuse the same fixed-window rate limiter as OIDC

---

## Advanced Configuration

### Role Mapping Example

**Scenario**: Map IdP group "Engineering" to Magento admin role "Content Editors".

**Steps**:
1. Navigate to **Stores > Configuration > M2Oidc > OAuth/OIDC > Attribute Mapping**
2. Set **Group Attribute Name** to `groups` (or your IdP's group claim name)
3. Click **Add Role Mapping**:
   - **OIDC Group**: `Engineering`
   - **Magento Admin Role**: Select "Content Editors" from dropdown
4. (Optional) Set **Default Admin Role** to "Administrators" as fallback
5. Enable **Auto Create Admin users while SSO**
6. **Save Config**
7. Test login with a user who has "Engineering" group in IdP

**Result**: User automatically created as admin with "Content Editors" role on first login.

### Login Restriction Mode

**OIDC-Only Mode** forces all users to authenticate via OIDC, blocking password logins entirely.

**Enable**:
1. **Create emergency admin** via CLI first:
   ```bash
   bin/magento admin:user:create
   ```
2. **Stores > Configuration > M2Oidc > OAuth/OIDC > Sign In Settings**
3. Enable **Disable non-OIDC admin logins**
4. **Save Config**

**Warning**: If IdP is unavailable, you'll need the emergency admin or database access to restore password login.

**Safety net**: If "Show Admin SSO Link" is disabled, password logins automatically allowed (prevents accidental lockout).

---

## Contributing & Support

### Documentation

- **Technical Reference**: [TECHNICAL_DOCUMENTATION.md](TECHNICAL_DOCUMENTATION.md) — API reference, architecture, implementation details
- **Developer Guide**: [CLAUDE.md](CLAUDE.md) — Developer commands, common modifications, testing checklist

### Support

- **GitHub Issues**: Report bugs or request features (repository URL)

### Version

- **Module Version**: 1.0.0
- **Package**: `martinkuhl/magento2-oidc-sso`
- **License**: MIT
- **Minimum Requirements**: PHP 8.2 – 8.5, Magento 2.4.7+

---

**For detailed technical documentation and API reference, see [TECHNICAL_DOCUMENTATION.md](TECHNICAL_DOCUMENTATION.md).**
