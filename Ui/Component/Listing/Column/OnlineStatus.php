<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use M2Oidc\OAuth\Ui\Component\Listing\Column\Render\BadgeRenderer;

/**
 * Renders the is_online field as a coloured HTML badge.
 *
 * Admin users: online when an active row exists in admin_user_session (status = 1).
 * Customer users: online when a visitor row was recorded within the configured
 *                 online-minutes interval.
 */
class OnlineStatus extends Column
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
        if (isset($dataSource['data']['items'])) {
            $fieldName = $this->getData('name');
            foreach ($dataSource['data']['items'] as &$item) {
                $isOnline = (bool) ($item[$fieldName] ?? false);
                $item[$fieldName] = $isOnline
                    ? $this->badgeRenderer->render('success', (string) __('Online'), '&#9679;')
                    : $this->badgeRenderer->render('neutral', (string) __('Offline'), '&#9675;');
            }
        }

        return $dataSource;
    }
}
