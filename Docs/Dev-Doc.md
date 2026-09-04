## ToDo

- code check: https://medium.com/data-science-collective/youre-using-ai-to-write-code-you-re-not-using-it-to-review-code-728e5ec2576e

Review this code as a senior developer.
Check for:
1. Bugs: Logic errors, off-by-one, null handling, race conditions
2. Security: Injection risks, auth issues, data exposure
3. Performance: N+1 queries, unnecessary loops, memory leaks
4. Maintainability: Naming, complexity, duplication
5. Edge cases: What inputs would break this?
6. Unused sections: unused classes nad functions
7. Code conventions: Are Magento best practise for coding used
8. What can be future improvements to opitmize the plugin

For each issue:
- Severity: Critical / High / Medium / Low
- Line number or section
- What's wrong
- How to fix it

Be harsh. I'd rather fix issues now than in production.

Write an update the exisiting review into @github/magento2-oidc-sso/Docs/Code-Review.md 

################

Update @github/magento2-oidc-sso/Docs/TECHNICAL_DOCUMENTATION.md for this code:

Include:
1. Overview: What this module does and why it exists
2. Structure: How is the plugin structured 
3. Quick Start: How to use it in 3 steps or less
4. Functionalities and Use Case what is the plugin for
5. Gotchas: Edge cases, limitations, and things that will bite you
6. What can be future improvements to opitmize the plugin

Write for a developer who's new to this codebase but not new to coding.

####################################################################################

# Prüfen, ob phpcs verfügbar ist
vendor/bin/phpcs --version
vendor/bin/phpcbf --version

# Verfügbare Standards anzeigen
/var/www/html/vendor/bin/phpcs -i
# Ausgabe sollte enthalten: Magento2, PSR1, PSR2, PSR12, ...

/var/www/html/vendor/bin/phpcs  --extensions=php,phtml /var/www/html/github/magento2-oidc-sso/Model/Auth/OidcCredentialAdapter.php
/var/www/html/vendor/bin/phpcbf  --extensions=php,phtml /var/www/html/github/magento2-oidc-sso/Model/Auth/OidcCredentialAdapter.php

## Rector

# Dry-Run (nur anzeigen):
/var/www/html/vendor/bin/rector process --dry-run --config=Test/rector.php

# Tatsächlich fixen:
/var/www/html/vendor/bin/rector process --config=Test/rector.php

## AI command
please look at @github/magento2-oidc-sso/Docs/Code-Review.md here you find phpcs issues mentioned for different files. Fix them all. Use sub-agents where applicable. DO not create new issue for PHPStan or PHPCS scans.


# root level

####### check all ######
Please run PHPStan, Psalm, PHPCS, PHPUnit and Rector via:

## Run PHPStan:
cd /var/www/html/github/magento2-oidc-sso && /var/www/html/vendor/bin/phpstan analyse --memory-limit=1G --configuration=Test/phpstan.local.neon

## Run Psalm:
cd /var/www/html/ && /var/www/html/vendor/bin/psalm --no-cache --config=/var/www/html/github/magento2-oidc-sso/Test/psalm.xml --root=/var/www/html --force-jit

## Run PHPCS:
cd /var/www/html/github/magento2-oidc-sso && /var/www/html/vendor/bin/phpcs --extensions=php,phtml --standard=Test/phpcs.xml .

## Run Rector:
cd /var/www/html/github/magento2-oidc-sso && /var/www/html/vendor/bin/rector process --dry-run --config=Test/rector.php

## Run PHPUnit:
cd /var/www/html/github/magento2-oidc-sso && /var/www/html/vendor/bin/phpunit --configuration Test/phpunit.xml --testsuite "M2Oidc OIDC Unit Tests"


Fix all the mentioned issue and warnings. Use sub-agents where applicable. DO not create new issue for PHPStan, PHPUnit, Psalm, PHPCS and Rector.

##############################################

### ToDo ###

### FIX ###
- 

### TESTING ###
- Passkey (WebAuthn) feature added in 1.0.0 (Model/Passkey/*, Helper/PasskeyConfig.php, Helper/PasskeySecurityHelper.php, PasskeyCredentialAdapter/Plugin, Controller/**/Passkey/*) has no dedicated PHPUnit coverage yet — only incidental coverage via Test/Unit/Observer/CustomerDeleteObserverTest.php (passkey cleanup on customer delete). Write Unit tests for PasskeyRegistrationService/PasskeyAuthenticationService (mock webauthn-lib validators), PasskeySecurityHelper (token/nonce format + TTL), and an Integration test for the full admin + customer passkey login flow, mirroring AdminOidcLoginFlowTest.php / CustomerOidcLoginFlowTest.php.
- Manually verify passkey login end-to-end after any change: see the "Test passkey registration and login" item in @github/magento2-oidc-sso/CLAUDE.md's Testing Checklist.

### LATER - more complex ###:
-

Nächster Schritt (nach Stabilisierung):
PHPStan: Level 4
Psalm:   Level 4

Langfristig (Ziel für sauberen Code):
PHPStan: Level 6
Psalm:   Level 3


# install testing dependencies

# Magento Coding Standard installieren (bringt phpcs/phpcbf automatisch mit)

#composer config minimum-stability dev
#composer config minimum-stability stable

composer require magento/magento-coding-standard bitexpert/phpstan-magento vimeo/psalm phpunit/phpunit:^10.5 rector/rector --no-update #phpstan/phpstan
composer update --no-dev



https://m2-local.casa-kuhl.de/m2oidc/actions/idpInitiatedLogin?provider_id=1

&login_hint=martin_kuhl@gmx.net