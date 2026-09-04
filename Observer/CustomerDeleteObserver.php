<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredentialRepository;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider;

/**
 * Removes the OIDC session activity entry and any registered passkeys when
 * a customer is deleted.
 */
class CustomerDeleteObserver implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param UserProvider                $userProviderResource
     * @param PasskeyCredentialRepository $passkeyCredentialRepository
     */
    public function __construct(
        private readonly UserProvider $userProviderResource,
        private readonly PasskeyCredentialRepository $passkeyCredentialRepository
    ) {
    }

    /**
     * Delete the m2oidc_oauth_user_provider row and passkey credentials for
     * the deleted customer.
     *
     * @param Observer $observer
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getCustomer();
        $customerId = (int) $customer->getId();
        if ($customerId > 0) {
            $this->userProviderResource->deleteMapping('customer', $customerId);
            $this->passkeyCredentialRepository->deleteAllForUser('customer', $customerId);
        }
    }
}
