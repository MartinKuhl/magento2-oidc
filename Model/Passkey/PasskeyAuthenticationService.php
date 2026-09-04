<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Passkey;

use M2Oidc\OAuth\Helper\PasskeySecurityHelper;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredentialRepository;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * WebAuthn authentication (assertion) ceremony, shared by the admin and
 * customer login controllers.
 *
 * The $userHandle argument passed to the underlying validator is always left
 * null here: CheckUserHandle then falls back to verifying the assertion's own
 * response.userHandle against the stored credential's userHandle, which is
 * the correct check for both flows — customer login never knows the user in
 * advance (discoverable/usernameless), and admin login, even though it scopes
 * allowCredentials to the typed email, still resolves the authoritative
 * account from the credential row the browser actually presented, not from
 * the (unverified at this point) email input.
 */
class PasskeyAuthenticationService
{
    /**
     * @param WebauthnCeremonyFactory $ceremonyFactory
     * @param PasskeyCredentialRepository $credentialRepository
     * @param PasskeySecurityHelper $securityHelper
     */
    public function __construct(
        private readonly WebauthnCeremonyFactory $ceremonyFactory,
        private readonly PasskeyCredentialRepository $credentialRepository,
        private readonly PasskeySecurityHelper $securityHelper
    ) {
    }

    /**
     * Build WebAuthn assertion (request) options for a login attempt.
     *
     * @param PublicKeyCredentialDescriptor[] $allowCredentials Empty for usernameless/discoverable login
     * @return array{optionsJson: string, nonce: string}
     */
    public function buildRequestOptions(array $allowCredentials = []): array
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->ceremonyFactory->getRpId(),
            allowCredentials: $allowCredentials,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
        );

        $optionsJson = $this->ceremonyFactory->getSerializer()->serialize($options, 'json');
        $nonce = $this->securityHelper->createChallengeNonce($optionsJson);

        return ['optionsJson' => $optionsJson, 'nonce' => $nonce];
    }

    /**
     * Verify the browser's assertion response, bump the credential's counter,
     * and return the owning StoredCredential so the caller can bridge into
     * native Magento auth for the right user_type/user_id.
     *
     * @param string $nonce
     * @param string $credentialJson
     * @throws \RuntimeException on an expired challenge, unknown credential, or failed verification
     */
    public function verifyAssertion(string $nonce, string $credentialJson): StoredCredential
    {
        $optionsJson = $this->securityHelper->redeemChallengeNonce($nonce);
        if ($optionsJson === null) {
            throw new \RuntimeException('Login challenge has expired. Please try again.');
        }

        $serializer = $this->ceremonyFactory->getSerializer();
        /** @var PublicKeyCredentialRequestOptions $options */
        $options = $serializer->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
        /** @var PublicKeyCredential $publicKeyCredential */
        $publicKeyCredential = $serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');

        if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            throw new \RuntimeException('Expected an assertion response.');
        }

        $stored = $this->credentialRepository->findByRawCredentialId($publicKeyCredential->rawId);
        if (!$stored instanceof \M2Oidc\OAuth\Model\Passkey\StoredCredential) {
            throw new \RuntimeException('This passkey is not registered.');
        }

        $validator = AuthenticatorAssertionResponseValidator::create(
            $this->ceremonyFactory->getRequestCeremonyStepManager()
        );
        $updatedRecord = $validator->check(
            $stored->record,
            $publicKeyCredential->response,
            $options,
            $this->ceremonyFactory->getRpId(),
            null
        );

        $this->credentialRepository->updateAfterAuthentication($stored, $updatedRecord);

        return $stored;
    }
}
