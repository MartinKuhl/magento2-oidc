<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Passkeysettings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Passkey Settings admin page: global enable toggles + RP configuration,
 * plus a support/lockout-recovery listing of every registered credential
 * (the passkey equivalent of the OIDC "Sessions" activity page).
 */
class Index extends Action implements HttpGetActionInterface
{
    /** @var string */
    public const ADMIN_RESOURCE = 'M2Oidc_OAuth::passkey_settings';

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
     * Render the Passkey Settings admin page.
     */
    #[\Override]
    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage->setActiveMenu('M2Oidc_OAuth::OAuth');
        $resultPage->getConfig()->getTitle()->prepend((string) __('Passkey Settings'));
        return $resultPage;
    }
}
