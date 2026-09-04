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
 * Self-service: verifies the browser's attestation and stores the new
 * passkey against the currently authenticated admin's own account.
 */
class RegistrationVerify extends Action implements HttpPostActionInterface
{
    /** @var string */
    public const ADMIN_RESOURCE = 'Magento_Backend::admin';

    private const MAX_NICKNAME_LENGTH = 191;

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

        $nonce = (string) $this->getRequest()->getParam('nonce', '');
        $credentialJson = (string) $this->getRequest()->getParam('credential', '');
        $nickname = trim((string) $this->getRequest()->getParam('nickname', ''));
        $nickname = $nickname !== '' ? mb_substr($nickname, 0, self::MAX_NICKNAME_LENGTH) : null;

        if ($nonce === '' || $credentialJson === '') {
            return $json->setData(['error' => (string) __('Invalid passkey registration request.')]);
        }

        try {
            $this->registrationService->verifyAndPersist(
                $nonce,
                $credentialJson,
                'admin',
                (int) $adminUser->getId(),
                $nickname
            );
        } catch (\Throwable $e) {
            $this->oauthUtility->customlog('Passkey admin RegistrationVerify: ' . $e->getMessage());
            return $json->setData(['error' => (string) __('Passkey registration failed. Please try again.')]);
        }

        return $json->setData(['success' => true]);
    }
}
