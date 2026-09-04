<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Passkeysettings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Saves the global Passkey Settings config toggles to core_config_data.
 */
class Save extends Action implements HttpPostActionInterface
{
    /** @var string */
    public const ADMIN_RESOURCE = 'M2Oidc_OAuth::passkey_settings';

    private const PATHS = [
        'enabled_admin' => 'm2oidc_passkey/general/enabled_admin',
        'enabled_customer' => 'm2oidc_passkey/general/enabled_customer',
        'rp_name' => 'm2oidc_passkey/general/rp_name',
        'rp_id' => 'm2oidc_passkey/general/rp_id',
    ];

    /**
     * @param Context             $context
     * @param WriterInterface     $configWriter
     * @param TypeListInterface   $cacheTypeList
     */
    public function __construct(
        Context $context,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList
    ) {
        parent::__construct($context);
    }

    /**
     * Save the global Passkey Settings toggles and RP configuration.
     */
    #[\Override]
    public function execute()
    {
        $request = $this->getRequest();

        $this->configWriter->save(self::PATHS['enabled_admin'], $request->getParam('enabled_admin') ? '1' : '0');
        $this->configWriter->save(self::PATHS['enabled_customer'], $request->getParam('enabled_customer') ? '1' : '0');
        $this->configWriter->save(self::PATHS['rp_name'], trim((string) $request->getParam('rp_name', '')));
        $this->configWriter->save(self::PATHS['rp_id'], trim((string) $request->getParam('rp_id', '')));

        $this->cacheTypeList->cleanType('config');

        $this->messageManager->addSuccessMessage((string) __('Passkey settings saved.'));

        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('m2oidc/passkeysettings/index');
    }
}
