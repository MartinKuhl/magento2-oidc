<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Ui\Component\DataProvider;

use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredential\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * UI DataProvider for the Registered Passkeys grid on the Passkey Settings page.
 *
 * Unlike SessionDataProvider, this grid reads a single flat table with no
 * cross-table joins, so the standard collection-backed AbstractDataProvider
 * pattern applies as-is — just point it at the existing
 * PasskeyCredential\Collection.
 */
class PasskeyCredentialDataProvider extends AbstractDataProvider
{
    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }
}
