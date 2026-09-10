<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use M2Oidc\OAuth\Ui\Component\Listing\Column\Render\BadgeRenderer;

/**
 * Virtual column: zeigt ob ein JWKS-Endpoint konfiguriert ist.
 * Liest jwks_uri direkt aus den Collection-Daten — kein extra DB-Query.
 */
class JwksStatus extends Column
{
    /** @var BadgeRenderer */
    private BadgeRenderer $badgeRenderer;

    /**
     * Override to inject dependencies for OIDC JWKS status column rendering.
     *
     * @param ContextInterface   $context
     * @param UiComponentFactory $uiComponentFactory
     * @param BadgeRenderer      $badgeRenderer
     * @param mixed[]            $components
     * @param mixed[]            $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        BadgeRenderer $badgeRenderer,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->badgeRenderer = $badgeRenderer;
    }

    /**
     * @inheritDoc
     *
     * @param  mixed[] $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $fieldName = $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            $jwksUri = trim((string) ($item['jwks_endpoint'] ?? ''));
            $item[$fieldName] = $this->renderBadge($jwksUri);
        }

        return $dataSource;
    }

    /**
     * Render an HTML badge indicating whether the JWKS endpoint is configured.
     *
     * @param string $jwksUri configured JWKS endpoint or empty string
     * @return string HTML badge
     */
    private function renderBadge(string $jwksUri): string
    {
        if ($jwksUri !== '') {
            return $this->badgeRenderer->render('success', (string) __('configured'));
        }

        return $this->badgeRenderer->render('neutral', (string) __('not set'));
    }
}
