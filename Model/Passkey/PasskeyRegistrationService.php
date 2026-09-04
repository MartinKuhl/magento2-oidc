<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Passkey;

use M2Oidc\OAuth\Helper\PasskeySecurityHelper;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredentialRepository;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn registration (credential creation) ceremony, shared by the admin
 * and customer self-service "register a passkey" controllers — the ceremony
 * itself doesn't differ between the two areas, only who is allowed to call it
 * (an already-authenticated admin or customer) and which user_type/user_id
 * the resulting credential is stored against.
 */
class PasskeyRegistrationService
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
     * Build WebAuthn attestation (creation) options for a registration attempt.
     *
     * @param string $userType
     * @param int $userId
     * @param string $email
     * @param string $displayName
     * @return array{optionsJson: string, nonce: string}
     */
    public function buildCreationOptions(string $userType, int $userId, string $email, string $displayName): array
    {
        $userHandle = $this->securityHelper->deriveUserHandle($userType, $userId);
        $userEntity = PublicKeyCredentialUserEntity::create($email, $userHandle, $displayName);

        $options = PublicKeyCredentialCreationOptions::create(
            rp: $this->ceremonyFactory->getRpEntity(),
            user: $userEntity,
            challenge: random_bytes(32),
            pubKeyCredParams: $this->ceremonyFactory->getPublicKeyCredentialParameters(),
            authenticatorSelection: $this->ceremonyFactory->getAuthenticatorSelectionCriteria(),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $this->credentialRepository->getDescriptorsForUser($userType, $userId),
        );

        $optionsJson = $this->ceremonyFactory->getSerializer()->serialize($options, 'json');
        $nonce = $this->securityHelper->createChallengeNonce($optionsJson);

        return ['optionsJson' => $optionsJson, 'nonce' => $nonce];
    }

    /**
     * Verify the browser's attestation response and persist the new credential.
     *
     * @param string $nonce
     * @param string $credentialJson
     * @param string $userType
     * @param int $userId
     * @param string|null $nickname
     * @throws \RuntimeException on an expired/consumed challenge or a failed verification
     */
    public function verifyAndPersist(
        string $nonce,
        string $credentialJson,
        string $userType,
        int $userId,
        ?string $nickname
    ): void {
        $optionsJson = $this->securityHelper->redeemChallengeNonce($nonce);
        if ($optionsJson === null) {
            throw new \RuntimeException('Registration challenge has expired. Please try again.');
        }

        $serializer = $this->ceremonyFactory->getSerializer();
        /** @var PublicKeyCredentialCreationOptions $options */
        $options = $serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
        /** @var PublicKeyCredential $publicKeyCredential */
        $publicKeyCredential = $serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');

        if (!$publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            throw new \RuntimeException('Expected an attestation response.');
        }

        $validator = AuthenticatorAttestationResponseValidator::create(
            $this->ceremonyFactory->getCreationCeremonyStepManager()
        );
        $record = $validator->check(
            $publicKeyCredential->response,
            $options,
            $this->ceremonyFactory->getRpId()
        );

        $this->credentialRepository->saveNewCredential($record, $userType, $userId, $nickname);
    }
}
