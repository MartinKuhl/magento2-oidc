<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Passkey;

use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use M2Oidc\OAuth\Helper\PasskeyConfig;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;

/**
 * Single seam that constructs every webauthn-lib object this module needs,
 * configured from PasskeyConfig (RP name/ID, allowed origin).
 *
 * Registration only ever asks for 'none' attestation conveyance — this module
 * has no use for attestation trust chains, it only needs the public key, so
 * CheckAttestationFormatIsKnownAndValid only needs to know the 'none' format.
 */
class WebauthnCeremonyFactory
{
    /** @var SerializerInterface|null */
    private ?SerializerInterface $serializer = null;

    /**
     * @param PasskeyConfig $passkeyConfig
     */
    public function __construct(
        private readonly PasskeyConfig $passkeyConfig
    ) {
    }

    /**
     * Get the memoized webauthn-lib serializer, configured for 'none' attestation only.
     */
    public function getSerializer(): SerializerInterface
    {
        if (!$this->serializer instanceof \Symfony\Component\Serializer\SerializerInterface) {
            $attestationStatementSupportManager = new AttestationStatementSupportManager([
                new NoneAttestationStatementSupport(),
            ]);
            $this->serializer = (new WebauthnSerializerFactory($attestationStatementSupportManager))->create();
        }
        return $this->serializer;
    }

    /**
     * Get the Relying Party entity (name + ID) for WebAuthn ceremonies.
     */
    public function getRpEntity(): PublicKeyCredentialRpEntity
    {
        return PublicKeyCredentialRpEntity::create($this->passkeyConfig->getRpName(), $this->passkeyConfig->getRpId());
    }

    /**
     * Get the Relying Party ID (registrable domain) for WebAuthn ceremonies.
     */
    public function getRpId(): string
    {
        return $this->passkeyConfig->getRpId();
    }

    /**
     * Get the allowed public-key algorithms for credential creation.
     *
     * @return PublicKeyCredentialParameters[]
     */
    public function getPublicKeyCredentialParameters(): array
    {
        return [
            PublicKeyCredentialParameters::createPk(ES256::ID),
            PublicKeyCredentialParameters::createPk(RS256::ID),
        ];
    }

    /**
     * Require a discoverable (resident) credential — mandatory for usernameless
     * customer login and for the admin flow to be able to locate a credential
     * by public key ID alone.
     */
    public function getAuthenticatorSelectionCriteria(): AuthenticatorSelectionCriteria
    {
        return AuthenticatorSelectionCriteria::create(
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED
        );
    }

    /**
     * Get the ceremony step manager for credential creation (registration).
     */
    public function getCreationCeremonyStepManager(): CeremonyStepManager
    {
        return $this->buildFactory()->creationCeremony();
    }

    /**
     * Get the ceremony step manager for credential requests (login).
     */
    public function getRequestCeremonyStepManager(): CeremonyStepManager
    {
        return $this->buildFactory()->requestCeremony();
    }

    /**
     * Build a ceremony step manager factory scoped to the configured origin.
     */
    private function buildFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$this->passkeyConfig->getOrigin()]);
        return $factory;
    }
}
