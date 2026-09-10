<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Miscellaneous;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthMessages;

use M2Oidc\OAuth\Controller\Actions\BaseAdminAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Customer\Model\ResourceModel\Group\Collection;
use Magento\Framework\Message\ManagerInterface;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Validation\ProviderDataValidator;
use Psr\Log\LoggerInterface;

/**
 * This class handles the action for endpoint: m2oidc/miscellaneous/Index
 * (Miscellaneous page — Debug Logs + Import/Export).
 *
 * Login / Logout Options have moved to the per-provider Login Options tab
 * in Manage Providers → Edit Provider.
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 * @psalm-suppress DeprecatedInterface
 */
class Index extends BaseAdminAction implements HttpPostActionInterface, HttpGetActionInterface
{
    /**
     * ACL resource checked by \Magento\Backend\App\Action::_isAllowed().
     *
     * @var string
     */
    public const ADMIN_RESOURCE = 'M2Oidc_OAuth::miscellaneous';

    /**
     * Runtime/diagnostic provider fields excluded from configuration exports.
     *
     * @var string[]
     */
    private const EXPORT_EXCLUDED_FIELDS = ['received_oidc_claims', 'last_test_status', 'last_test_at'];

    /** @var \Magento\Framework\App\Response\Http\FileFactory */
    protected \Magento\Framework\App\Response\Http\FileFactory $fileFactory;

    /** @var \Magento\Store\Model\StoreManagerInterface */
    protected \Magento\Store\Model\StoreManagerInterface $_storeManager;

    /** @var \Magento\Framework\App\ProductMetadataInterface */
    private readonly \Magento\Framework\App\ProductMetadataInterface $productMetadata;

    /** @var \Magento\Framework\Filesystem\Io\File */
    private readonly \Magento\Framework\Filesystem\Io\File $ioFile;

    /** @var \Magento\Framework\Filesystem\Driver\File */
    private readonly \Magento\Framework\Filesystem\Driver\File $fileDriver;

    /** @var ProviderDataValidator */
    private readonly ProviderDataValidator $providerDataValidator;

    /**
     * Initialize miscellaneous settings controller.
     *
     * @param Context                                          $context
     * @param PageFactory                                      $resultPageFactory
     * @param OAuthUtility                                     $oauthUtility
     * @param ManagerInterface                                 $messageManager
     * @param LoggerInterface                                  $logger
     * @param \Magento\Framework\App\Response\Http\FileFactory $fileFactory
     * @param \Magento\Store\Model\StoreManagerInterface       $storeManager
     * @param \Magento\Framework\App\ProductMetadataInterface  $productMetadata
     * @param \Magento\Framework\Filesystem\Io\File            $ioFile
     * @param \Magento\Framework\Filesystem\Driver\File        $fileDriver
     * @param ProviderDataValidator                            $providerDataValidator
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        OAuthUtility $oauthUtility,
        ManagerInterface $messageManager,
        LoggerInterface $logger,
        \Magento\Framework\App\Response\Http\FileFactory $fileFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata,
        \Magento\Framework\Filesystem\Io\File $ioFile,
        \Magento\Framework\Filesystem\Driver\File $fileDriver,
        ProviderDataValidator $providerDataValidator
    ) {
        parent::__construct($context, $resultPageFactory, $oauthUtility, $messageManager, $logger);
        $this->_storeManager = $storeManager;
        $this->fileFactory = $fileFactory;
        $this->productMetadata = $productMetadata;
        $this->ioFile = $ioFile;
        $this->fileDriver = $fileDriver;
        $this->providerDataValidator = $providerDataValidator;
    }

    /**
     * Main controller entry-point for Miscellaneous page.
     *
     * Handles debug log toggling, clearing, downloading logs,
     * and OIDC configuration import/export.
     *
     * @return \Magento\Framework\View\Result\Page|\Magento\Framework\App\ResponseInterface
     */
    #[\Override]
    public function execute()
    {
        try {
            $params = $this->getRequest()->getParams();

            if ($this->isFormOptionBeingSaved($params)) {
                if ($params['option'] == 'enable_debug_log') {
                    $debug_log_on = isset($params['debug_log_on']) ? 1 : 0;
                    $log_file_time = time();
                    $this->oauthUtility->setStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG, $debug_log_on);
                    $this->oauthUtility->flushCache();
                    $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                    $this->oauthUtility->reinitConfig();
                    if ($debug_log_on == '1') {
                        $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, $log_file_time);
                    } elseif ($debug_log_on == '0' && $this->oauthUtility->isCustomLogExist()) {
                        $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, null);
                        $this->oauthUtility->deleteCustomLogFile();
                    }
                } elseif ($params['option'] == 'clear_download_logs') {
                    if (isset($params['download_logs'])) {
                        $result = $this->handleDownloadLogs();
                        if ($result !== null) {
                            return $result;
                        }
                    } elseif (isset($params['clear_logs'])) {
                        $this->handleClearLogs();
                    }
                } elseif ($params['option'] === 'export_oidc_config') {
                    $result = $this->handleExportConfig();
                    if ($result instanceof \Magento\Framework\App\ResponseInterface) {
                        return $result;
                    }
                } elseif ($params['option'] === 'import_oidc_config') {
                    $this->handleImportConfig();
                }
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->oauthUtility->customlog($e->getMessage());
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend((string)__('Miscellaneous'));
        return $resultPage;
    }

    /**
     * Export all OIDC provider configurations as a JSON file download.
     *
     * Sensitive fields (client_secret) are re-encrypted for safe transport.
     */
    private function handleExportConfig(): ?\Magento\Framework\App\ResponseInterface
    {
        $collection = $this->oauthUtility->getOAuthClientApps();

        if ($collection->getSize() === 0) {
            $this->messageManager->addErrorMessage((string)__('No providers found to export.'));
            return null;
        }

        $encryptor = $this->oauthUtility->getEncryptor();

        $exportData = [
            'exported_at'    => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
            'module_version' => OAuthConstants::VERSION,
            'providers'      => [],
        ];

        foreach ($collection as $provider) {
            $data = $provider->getData();
            // Remove internal DB primary key
            unset($data['id']);
            // Strip runtime/diagnostic fields from the export
            foreach (self::EXPORT_EXCLUDED_FIELDS as $excludedField) {
                unset($data[$excludedField]);
            }
            // Re-encrypt sensitive fields for safe transport
            if (!empty($data['client_secret'])) {
                $plain = (string) $data['client_secret'];
                // Value is already Magento-encrypted in the DB — unwrap before re-encrypting
                if (preg_match('/^\d+:\d+:/', $plain)) {
                    $plain = $encryptor->decrypt($plain);
                }
                $data['client_secret'] = $encryptor->encrypt($plain);
            }
            $exportData['providers'][] = $data;
        }

        $json = json_encode(
            $exportData,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $fileName = 'oidc_config_' . date('Ymd_His') . '.json';

        return $this->fileFactory->create(
            $fileName,
            $json,
            DirectoryList::VAR_DIR,
            'application/json'
        );
    }

    /**
     * Import OIDC provider configurations from an uploaded JSON file.
     *
     * Existing providers (matched by app_name) are skipped to prevent duplicates.
     * Encrypted client_secret values are decrypted and stored via Magento's encryptor.
     */
    private function handleImportConfig(): void
    {
        /** @psalm-suppress UndefinedInterfaceMethod */
        // @phpstan-ignore-next-line
        $files = $this->getRequest()->getFiles();
        $file  = $files['import_config_file'] ?? null;

        if (!$file || empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            $this->messageManager->addErrorMessage((string)__('Please select a valid JSON file.'));
            return;
        }

        // Validate file extension
        $pathInfo = $this->ioFile->getPathInfo((string) $file['name']);
        $ext = strtolower((string) ($pathInfo['extension'] ?? ''));
        if ($ext !== 'json') {
            $this->messageManager->addErrorMessage((string)__('Only .json files are allowed.'));
            return;
        }

        try {
            $content = $this->fileDriver->fileGetContents($file['tmp_name']);
        } catch (\Magento\Framework\Exception\FileSystemException $e) {
            $this->messageManager->addErrorMessage((string)__('Could not read uploaded file.'));
            return;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->messageManager->addErrorMessage((string)__('Invalid JSON: %1', $e->getMessage()));
            return;
        }

        if (!isset($data['providers']) || !is_array($data['providers'])) {
            $this->messageManager->addErrorMessage((string)__('Invalid format: "providers" key missing.'));
            return;
        }

        $encryptor   = $this->oauthUtility->getEncryptor();
        $appFactory  = $this->oauthUtility->getClientAppsFactory();
        $appResource = $this->oauthUtility->getAppResource();
        $imported    = 0;
        $skipped     = 0;

        foreach ($data['providers'] as $providerData) {
            if (empty($providerData['app_name'])) {
                $skipped++;
                continue;
            }

            // Check if provider already exists — skip to avoid duplicates
            $existing = $this->oauthUtility->getOAuthClientApps()
                ->addFieldToFilter('app_name', $providerData['app_name'])
                ->getFirstItem();

            if ($existing->getId()) {
                $skipped++;
                continue;
            }

            // Decrypt sensitive fields if they are in Magento encryption format
            if (!empty($providerData['client_secret'])) {
                if (preg_match('/^\d+:\d+:/', (string) $providerData['client_secret'])) {
                    $decrypted = $encryptor->decrypt($providerData['client_secret']);
                    $providerData['client_secret'] = $decrypted ?: $providerData['client_secret'];
                }
                // Re-encrypt for storage
                $providerData['client_secret'] = $encryptor->encrypt($providerData['client_secret']);
            }

            // Remove DB-specific fields
            unset($providerData['id']);

            // Shared whitelist / SSRF / lockout validation before persisting
            $validation = $this->providerDataValidator->validate($providerData, 0);
            foreach ($validation->getWarnings() as $warning) {
                $this->messageManager->addWarningMessage($warning);
            }
            if (!$validation->isValid()) {
                foreach ($validation->getErrors() as $error) {
                    $this->messageManager->addErrorMessage($error);
                }
                $skipped++;
                continue;
            }
            $providerData = $validation->getData();

            $model = $appFactory->create();
            $model->setData($providerData);
            $appResource->save($model);
            $imported++;
        }

        $this->messageManager->addSuccessMessage(
            (string)__('Import complete: %1 provider(s) imported, %2 skipped.', $imported, $skipped)
        );
    }

    /**
     * Handle downloading of debug log files.
     *
     * @return \Magento\Framework\App\ResponseInterface|null
     */
    private function handleDownloadLogs()
    {
        $fileName = "M2Oidc.log";
        $filePath = '../var/log/' . $fileName;
        $content = [
            'type' => 'filename',
            'value' => $filePath,
            'rm' => 0,
        ];

        if ($this->oauthUtility->isLogEnable()) {
            $this->logClientConfigurationIfAvailable();
        }

        if ($this->oauthUtility->isCustomLogExist() && $this->oauthUtility->isLogEnable()) {
            return $this->fileFactory->create($fileName, $content, DirectoryList::VAR_DIR);
        }

        $this->messageManager->addErrorMessage('Please Enable Debug Log Setting First');
        return null;
    }

    /**
     * Log client configuration settings for diagnostics.
     */
    private function logClientConfigurationIfAvailable(): void
    {
        $appName = $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        $clientDetails = $this->oauthUtility->getClientDetailsByAppName($appName);

        if (!$clientDetails) {
            return;
        }

        $showCustomerLink = $clientDetails['show_customer_link']
            ?? $this->oauthUtility->getStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK);
        $attributeEmail = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL);
        $attributeUsername = $this->oauthUtility->getStoreConfig(
            OAuthConstants::MAP_USERNAME
        );
        $customerEmail = $this->oauthUtility->getStoreConfig(
            OAuthConstants::DEFAULT_MAP_EMAIL
        );

        $values = [
            $appName,
            $clientDetails["scope"],
            $clientDetails['authorize_endpoint'],
            $clientDetails['access_token_endpoint'],
            $clientDetails['user_info_endpoint'],
            $clientDetails["values_in_header"],
            $clientDetails["values_in_body"],
            $clientDetails['well_known_config_url'],
            $showCustomerLink,
            $attributeEmail,
            $attributeUsername,
            $customerEmail,
            OAuthConstants::VERSION,
            $this->productMetadata->getVersion(),
            phpversion()
        ];

        $this->customerConfigurationSettings($values);
    }

    /**
     * Handle clearing of debug log files.
     */
    private function handleClearLogs(): void
    {
        if ($this->oauthUtility->isCustomLogExist() !== 0) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, null);
            $this->oauthUtility->deleteCustomLogFile();
            $this->messageManager->addSuccessMessage('Logs Cleared Successfully');
        } else {
            $this->messageManager->addSuccessMessage('Logs Have Already Been Removed');
        }
    }

    /**
     * Log the client configuration values for diagnostics.
     *
     * @param (false|mixed|string)[] $values
     * @psalm-param list{string, mixed, mixed, mixed, mixed, mixed, mixed, mixed, mixed, mixed, mixed, mixed,
     *                    '1.0.0', string, false|string} $values
     */
    private function customerConfigurationSettings(array $values): void
    {
        $this->oauthUtility->customlog("......................................................................");
        $this->oauthUtility->customlog("Plugin: OAuth Free : " . $values[12]);
        $this->oauthUtility->customlog(
            "Plugin: Magento version : " . $values[13] . " ; Php version: " . (string)$values[14]
        );
        $this->oauthUtility->customlog("Appname: " . $values[0]);
        $this->oauthUtility->customlog("Scope: " . $values[1]);
        $this->oauthUtility->customlog("Authorize_url: " . $values[2]);
        $this->oauthUtility->customlog("Accesstoken_url: " . $values[3]);
        $this->oauthUtility->customlog("Getuserinfo_url: " . $values[4]);
        $this->oauthUtility->customlog("Header: " . $values[5]);
        $this->oauthUtility->customlog("Body: " . $values[6]);
        $this->oauthUtility->customlog("Well known config url: " . $values[7]);
        $this->oauthUtility->customlog("Show_customer_link: " . $values[8]);
        $this->oauthUtility->customlog("Attribute_email: " . $values[9]);
        $this->oauthUtility->customlog("Attribute_username: " . $values[10]);
        $this->oauthUtility->customlog("Customer_email: " . $values[11]);
        $this->oauthUtility->customlog("Enable login redirect: " . $values[12]);
        $this->oauthUtility->customlog("......................................................................");
    }
}
