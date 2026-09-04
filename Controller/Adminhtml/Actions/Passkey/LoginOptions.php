<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Actions\Passkey;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredentialRepository;
use M2Oidc\OAuth\Model\Passkey\PasskeyAuthenticationService;
use M2Oidc\OAuth\Model\Security\OidcRateLimiter;

/**
 * Anonymous endpoint: builds WebAuthn request (assertion) options for admin login.
 *
 * Admin login stays email-first (unlike the customer's usernameless flow — the
 * admin login form already has an email field), so when a matching admin exists,
 * allowCredentials is scoped to that admin's own registered credentials. This
 * controller intentionally does NOT extend Backend\App\Action so it can be
 * reached before any admin session exists, mirroring Oidccallback.php.
 */
class LoginOptions implements ActionInterface, HttpPostActionInterface
{
    /**
     * @param RequestInterface              $request
     * @param JsonFactory                   $jsonFactory
     * @param PasskeyAuthenticationService  $authenticationService
     * @param PasskeyCredentialRepository   $credentialRepository
     * @param UserCollectionFactory         $userCollectionFactory
     * @param OidcRateLimiter               $rateLimiter
     * @param OAuthUtility                  $oauthUtility
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly PasskeyAuthenticationService $authenticationService,
        private readonly PasskeyCredentialRepository $credentialRepository,
        private readonly UserCollectionFactory $userCollectionFactory,
        private readonly OidcRateLimiter $rateLimiter,
        private readonly OAuthUtility $oauthUtility
    ) {
    }

    /**
     * Build WebAuthn assertion options for admin login, scoped to the typed email when it matches an admin.
     */
    #[\Override]
    public function execute()
    {
        $json = $this->jsonFactory->create();

        $clientIp = $this->request->getClientIp();
        if (!$this->rateLimiter->isAllowed($clientIp)) {
            return $json->setData(['error' => (string) __('Too many attempts. Please try again later.')]);
        }

        $email = trim((string) $this->request->getParam('email', ''));
        $allowCredentials = [];
        if ($email !== '') {
            $adminId = $this->findAdminIdByEmail($email);
            if ($adminId !== null) {
                $allowCredentials = $this->credentialRepository->getDescriptorsForUser('admin', $adminId);
            }
        }

        try {
            $result = $this->authenticationService->buildRequestOptions($allowCredentials);
        } catch (\Throwable $e) {
            $this->oauthUtility->customlog('Passkey admin LoginOptions: ' . $e->getMessage());
            return $json->setData(['error' => (string) __('Unable to start passkey login.')]);
        }

        return $json->setData([
            'success' => true,
            'nonce' => $result['nonce'],
            'options' => json_decode($result['optionsJson'], true),
        ]);
    }

    /**
     * Resolve an admin user ID by email, or null if no matching admin exists.
     *
     * @param string $email
     */
    private function findAdminIdByEmail(string $email): ?int
    {
        $collection = $this->userCollectionFactory->create()->addFieldToFilter('email', $email);
        if ($collection->getSize() === 0) {
            return null;
        }
        return (int) $collection->getFirstItem()->getId();
    }
}
