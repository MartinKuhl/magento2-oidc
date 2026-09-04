<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Plugin\Customer\Block;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Customer\Block\Adminhtml\Edit\Tab\View;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Injects an "OIDC Provider" row into the Personal Information table
 * on the Magento admin Customer View tab.
 *
 * Targets: Magento\Customer\Block\Adminhtml\Edit\Tab\View
 */
class OidcInfoPlugin
{
    /** @var UserProviderResource */
    private readonly UserProviderResource $userProviderResource;

    /** @var RequestInterface */
    private readonly RequestInterface $request;

    /** @var Escaper */
    private readonly Escaper $escaper;

    /** @var BackendUrl */
    private readonly BackendUrl $backendUrl;

    /**
     * Constructor.
     *
     * @param UserProviderResource $userProviderResource
     * @param RequestInterface     $request
     * @param Escaper              $escaper
     * @param BackendUrl           $backendUrl
     */
    public function __construct(
        UserProviderResource $userProviderResource,
        RequestInterface $request,
        Escaper $escaper,
        BackendUrl $backendUrl
    ) {
        $this->userProviderResource = $userProviderResource;
        $this->request = $request;
        $this->escaper = $escaper;
        $this->backendUrl = $backendUrl;
    }

    /**
     * After the Customer View tab is rendered, append an OIDC Provider row to the Personal Information table.
     *
     * @param View   $subject
     * @param string $result
     */
    public function afterToHtml(View $subject, string $result): string
    {
        $customerId = (int) $this->request->getParam('id', 0);
        if ($customerId === 0) {
            return $result;
        }

        $info = $this->userProviderResource->getProviderInfo('customer', $customerId);
        $displayName = $info ? $info['display_name'] : '';
        $createdAt   = $info ? $info['created_at'] : '';
        /** @var string $escapedDisplayName */
        $escapedDisplayName = $this->escaper->escapeHtml($displayName);
        /** @var string $escapedCreatedAt */
        $escapedCreatedAt = $this->escaper->escapeHtml($createdAt);
        $providerText = $info
            ? $escapedDisplayName . ' (' . $escapedCreatedAt . ')'
            : 'none';

        $unlinkHtml = '';
        if ($info) {
            $configJson = $this->escaper->escapeHtmlAttr((string) json_encode([
                'unlinkUrl'      => $this->backendUrl->getUrl('m2oidc/provider/unlinkuser'),
                'userType'       => 'customer',
                'userId'         => $customerId,
                'confirmMessage' => (string) __(
                    'Are you sure you want to unlink this customer from their OIDC provider?'
                    . ' They will be able to link to a different provider on next SSO login.'
                ),
                'valueSelector'  => 'tr .value',
            ]));
            $unlinkHtml = '<button type="button"'
                . ' class="action-default m2oidc-unlink-btn"'
                . ' style="margin-left:10px; cursor:pointer;"'
                . ' data-m2oidc-config="' . $configJson . '">'
                . $this->escaper->escapeHtmlAttr((string) __('Unlink IdP'))
                . '</button>';
        }

        $label = __('OIDC Provider');
        /** @var string $escapedLabel */
        $escapedLabel = $this->escaper->escapeHtml((string) $label);
        $row = '<tr>'
            . '<td class="label"><span>'
            . $escapedLabel
            . '</span></td>'
            . '<td class="value">' . $providerText . $unlinkHtml . '</td>'
            . '</tr>';

        $needle = '</tbody>';
        $pos    = strrpos($result, $needle);
        if ($pos !== false) {
            return substr_replace($result, $row . $needle, $pos, strlen($needle));
        }

        return $result;
    }
}
