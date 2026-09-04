<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Actions\Passkey;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Passkey\PasskeyRegistrationService;

/**
 * Self-service: an already-authenticated admin starts registering a passkey
 * for their own account, from their user profile page.
 */
class RegistrationOptions extends Action implements HttpPostActionInterface
{
    /** @var string */
    public const ADMIN_RESOURCE = 'Magento_Backend::admin';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly PasskeyRegistrationService $registrationService,
        private readonly OAuthUtility $oauthUtility
    ) {
        parent::__construct($context);
    }

    #[\Override]
    public function execute()
    {
        $json = $this->jsonFactory->create();

        $adminUser = $this->_auth->getUser();
        if (!$adminUser instanceof \Magento\User\Model\User || !$adminUser->getId()) {
            return $json->setData(['error' => (string) __('Not authenticated.')]);
        }

        try {
            $result = $this->registrationService->buildCreationOptions(
                'admin',
                (int) $adminUser->getId(),
                (string) $adminUser->getEmail(),
                (string) ($adminUser->getFirstname() ?: $adminUser->getUsername())
            );
        } catch (\Throwable $e) {
            $this->oauthUtility->customlog('Passkey admin RegistrationOptions: ' . $e->getMessage());
            return $json->setData(['error' => (string) __('Unable to start passkey registration.')]);
        }

        return $json->setData([
            'success' => true,
            'nonce' => $result['nonce'],
            'options' => json_decode($result['optionsJson'], true),
        ]);
    }
}
