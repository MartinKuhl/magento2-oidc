<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Passkey;

use Magento\Customer\Controller\AccountInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Customer "My Account > Passkeys" page. Implementing AccountInterface makes
 * Magento's customer auth plugin redirect anonymous visitors to the login
 * page and apply the standard "My Account" layout handles automatically.
 */
class Index extends Action implements AccountInterface, HttpGetActionInterface
{
    /**
     * @param Context     $context
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Render the customer "My Account > Passkeys" management page.
     */
    #[\Override]
    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->set((string) __('Passkeys'));
        return $resultPage;
    }
}
