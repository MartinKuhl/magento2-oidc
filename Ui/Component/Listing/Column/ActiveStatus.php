<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use M2Oidc\OAuth\Ui\Component\Listing\Column\Render\BadgeRenderer;

/**
 * Virtual column: renders a coloured Active/Inactive badge per provider row.
 * Reads is_active directly from the collection data — no extra DB query.
 */
class ActiveStatus extends Column
{
    /** @var BadgeRenderer */
    private BadgeRenderer $badgeRenderer;

    /**
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
            $isActive = (bool)(int)($item['is_active'] ?? 1);
            $item[$fieldName] = $isActive
                ? $this->badgeRenderer->render('success', (string) __('Active'), '&#9679;')
                : $this->badgeRenderer->render('danger', (string) __('Inactive'), '&#9679;');
        }

        return $dataSource;
    }
}
