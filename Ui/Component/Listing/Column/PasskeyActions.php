<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Actions column renderer for the Registered Passkeys grid (Passkey Settings page).
 *
 * Adds a Delete link for each credential row — support/lockout-recovery
 * delete, gated by M2Oidc_OAuth::passkey_settings at the controller level.
 */
class PasskeyActions extends Column
{
    /** @var UrlInterface */
    private UrlInterface $urlBuilder;

    /**
     * @param ContextInterface   $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface       $urlBuilder
     * @param mixed[]            $components
     * @param mixed[]            $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Add Delete URL to each grid row.
     *
     * @param  mixed[] $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            $id = (int) ($item['credential_id'] ?? 0);

            $item[$this->getData('name')] = [
                'delete' => [
                    'href'    => $this->urlBuilder->getUrl('m2oidc/passkeysettings/delete', ['credential_id' => $id]),
                    'label'   => __('Delete'),
                    'confirm' => [
                        'title'   => __('Delete Passkey'),
                        'message' => __(
                            'Are you sure you want to delete passkey #%1? The user will need to register a new one.',
                            $id
                        ),
                    ],
                    'post' => true,
                ],
            ];
        }

        return $dataSource;
    }
}
