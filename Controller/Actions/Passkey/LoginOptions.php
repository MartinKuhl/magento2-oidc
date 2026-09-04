<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions\Passkey;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use M2Oidc\OAuth\Controller\Actions\BaseAction;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Passkey\PasskeyAuthenticationService;
use M2Oidc\OAuth\Model\Security\OidcRateLimiter;

/**
 * Anonymous endpoint: builds WebAuthn request (assertion) options for
 * usernameless customer login. allowCredentials is always empty — the
 * browser resolves the matching discoverable credential itself.
 */
class LoginOptions extends BaseAction implements HttpPostActionInterface
{
    /**
     * @param Context                      $context
     * @param OAuthUtility                 $oauthUtility
     * @param JsonFactory                  $jsonFactory
     * @param PasskeyAuthenticationService $authenticationService
     * @param OidcRateLimiter              $rateLimiter
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        private readonly JsonFactory $jsonFactory,
        private readonly PasskeyAuthenticationService $authenticationService,
        private readonly OidcRateLimiter $rateLimiter
    ) {
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Build usernameless WebAuthn assertion options for customer login.
     */
    #[\Override]
    public function execute()
    {
        $json = $this->jsonFactory->create();

        $clientIp = $this->getRequest()->getClientIp();
        if (!$this->rateLimiter->isAllowed($clientIp)) {
            return $json->setData(['error' => (string) __('Too many attempts. Please try again later.')]);
        }

        try {
            $result = $this->authenticationService->buildRequestOptions([]);
        } catch (\Throwable $e) {
            $this->oauthUtility->customlog('Passkey customer LoginOptions: ' . $e->getMessage());
            return $json->setData(['error' => (string) __('Unable to start passkey login.')]);
        }

        return $json->setData([
            'success' => true,
            'nonce' => $result['nonce'],
            'options' => json_decode($result['optionsJson'], true),
        ]);
    }
}
