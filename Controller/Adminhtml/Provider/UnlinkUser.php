<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Provider;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use M2Oidc\OAuth\Helper\AdminAuthHelper;
use M2Oidc\OAuth\Logger\OidcLogger;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Admin controller — Unlink an OIDC provider from a customer or admin user.
 *
 * Route: POST /admin/m2oidc/provider/unlinkuser
 *
 * Expected POST params:
 *   user_type  — 'customer' | 'admin'
 *   user_id    — int
 *
 * Returns JSON: {"success": true} or {"error": "..."}
 *
 * Requires admin ACL resource M2Oidc_OAuth::oidc_sessions (reused from Sessions).
 */
class UnlinkUser extends Action implements HttpPostActionInterface
{
    /**
     * @var string
     */
    public const ADMIN_RESOURCE = 'M2Oidc_OAuth::oidc_sessions';

    /**
     * @var mixed[]
     */
    private const ALLOWED_USER_TYPES = ['customer', 'admin'];

    /**
     * @param Context              $context
     * @param JsonFactory          $jsonFactory
     * @param UserProviderResource $userProviderResource
     * @param OidcLogger           $logger
     * @param AdminAuthHelper      $adminAuthHelper
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly UserProviderResource $userProviderResource,
        private readonly OidcLogger $logger,
        private readonly AdminAuthHelper $adminAuthHelper
    ) {
        parent::__construct($context);
    }

    /**
     * Unlink a user from their OIDC provider binding and return a JSON response.
     */
    #[\Override]
    public function execute(): Json
    {
        $json     = $this->jsonFactory->create();
        $request  = $this->getRequest();
        $userType = (string) $request->getParam('user_type', '');
        $userId   = (int) $request->getParam('user_id', 0);

        if (!in_array($userType, self::ALLOWED_USER_TYPES, true)) {
            return $json->setData(['error' => (string) __('Invalid user_type parameter.')]);
        }
        if ($userId <= 0) {
            return $json->setData(['error' => (string) __('Invalid user_id parameter.')]);
        }

        try {
            $this->userProviderResource->deleteMapping($userType, $userId);
            $adminUser     = $this->adminAuthHelper->resolveAdminUser($this->_auth->getUser());
            $adminUserName = $adminUser instanceof \Magento\User\Model\User
                ? $adminUser->getUserName()
                : '';
            $this->logger->customlog(sprintf(
                'UnlinkUser: %s user #%d unlinked from OIDC provider by admin %s',
                $userType,
                $userId,
                $adminUserName
            ));
            return $json->setData(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->customlog('UnlinkUser: error — ' . $e->getMessage());
            return $json->setData(['error' => (string) __('An error occurred: %1', $e->getMessage())]);
        }
    }
}
