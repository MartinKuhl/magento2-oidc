<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use M2Oidc\OAuth\Ui\Component\Listing\Column\Render\BadgeRenderer;

/**
 * Virtual column: rendert ein farbiges PKCE-Badge pro Provider-Zeile.
 * Liest pkce_flow direkt aus den Collection-Daten — kein extra DB-Query.
 */
class PkceStatus extends Column
{
    /** @var BadgeRenderer */
    private BadgeRenderer $badgeRenderer;

    /**
     * Override to inject dependencies for OIDC PKCE status column rendering.
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
            $pkceFlow = (string) ($item['pkce_flow'] ?? '');
            $item[$fieldName] = $this->renderBadge($pkceFlow);
        }

        return $dataSource;
    }

    /**
     * Render an HTML badge for the given PKCE flow value.
     *
     * @param string $pkceFlow 'S256' | 'plain' | ''
     * @return string HTML-Badge (kein User-Input — sicher ohne escaping)
     */
    private function renderBadge(string $pkceFlow): string
    {
        return match ($pkceFlow) {
            'S256'  => $this->badgeRenderer->render('success', 'S256'),
            'plain' => $this->badgeRenderer->render('warning', (string) __('plain')),
            default => $this->badgeRenderer->render('neutral', (string) __('disabled')),
        };
    }
}
