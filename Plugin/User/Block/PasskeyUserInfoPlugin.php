<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Plugin\User\Block;

use Closure;
use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Backend\Model\Auth\Session as BackendAuthSession;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\Escaper;
use Magento\Framework\Registry;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredentialRepository;

/**
 * Adds a "Passkeys" note field listing the user's own registered passkeys,
 * with register/delete controls, on both the admin User edit page
 * (Magento\User\Block\User\Edit\Tab\Main) and the admin's own My Account
 * page (Magento\Backend\Block\System\Account\Edit\Form) — both extend
 * Generic. Same aroundGetFormHtml + Registry technique as OidcUserInfoPlugin.
 *
 * @psalm-suppress DeprecatedClass
 */
class PasskeyUserInfoPlugin
{
    public function __construct(
        private readonly PasskeyCredentialRepository $credentialRepository,
        private readonly Registry $registry,
        private readonly Escaper $escaper,
        private readonly BackendUrl $backendUrl,
        private readonly BackendAuthSession $authSession
    ) {
    }

    public function aroundGetFormHtml(Generic $subject, Closure $proceed): string
    {
        $form = $subject->getForm();

        if ($form->getElement('m2oidc_passkey_info')) {
            return $proceed();
        }

        $user = $this->registry->registry('permissions_user');
        $userId = $user ? (int) $user->getId() : 0;
        if ($userId === 0) {
            $currentUser = $this->authSession->getUser();
            $userId = $currentUser ? (int) $currentUser->getId() : 0;
        }

        $fieldset = $form->getElement('base_fieldset');
        if ($fieldset && $userId > 0) {
            $credentials = $this->credentialRepository->findAllForUser('admin', $userId);

            if ($credentials === []) {
                $rows = '<div>' . $this->escaper->escapeHtml((string) __('No passkeys registered.')) . '</div>';
            } else {
                $rowsHtml = '';
                foreach ($credentials as $stored) {
                    $label = $stored->nickname !== null && $stored->nickname !== ''
                        ? $stored->nickname
                        : (string) __('Unnamed passkey');
                    $deleteConfig = $this->escaper->escapeHtmlAttr((string) json_encode([
                        'deleteUrl' => $this->backendUrl->getUrl('m2oidc/actions_passkey/delete'),
                        'credentialId' => $stored->dbId,
                        'confirmMessage' => (string) __('Remove this passkey? You will need to register it again to use it for login.'),
                    ]));
                    $rowsHtml .= '<tr class="m2oidc-passkey-row">'
                        . '<td>' . $this->escaper->escapeHtml($label) . '</td>'
                        . '<td>' . $this->escaper->escapeHtml($stored->createdAt) . '</td>'
                        . '<td><button type="button" class="action-default m2oidc-passkey-delete-btn"'
                        . ' style="cursor:pointer;" data-m2oidc-passkey-config="' . $deleteConfig . '">'
                        . $this->escaper->escapeHtmlAttr((string) __('Remove'))
                        . '</button></td>'
                        . '</tr>';
                }
                $rows = '<table class="data-table admin__table-primary">'
                    . '<thead><tr>'
                    . '<th>' . $this->escaper->escapeHtml((string) __('Name')) . '</th>'
                    . '<th>' . $this->escaper->escapeHtml((string) __('Registered')) . '</th>'
                    . '<th>' . $this->escaper->escapeHtml((string) __('Action')) . '</th>'
                    . '</tr></thead>'
                    . '<tbody>' . $rowsHtml . '</tbody>'
                    . '</table>';
            }

            $registerConfig = $this->escaper->escapeHtmlAttr((string) json_encode([
                'optionsUrl' => $this->backendUrl->getUrl('m2oidc/actions_passkey/registrationoptions'),
                'verifyUrl' => $this->backendUrl->getUrl('m2oidc/actions_passkey/registrationverify'),
            ]));
            $registerHtml = '<button type="button" id="m2oidc-passkey-register-btn"'
                . ' class="action-default" style="margin-top:8px;cursor:pointer;"'
                . ' data-m2oidc-passkey-register-config="' . $registerConfig . '">'
                . $this->escaper->escapeHtmlAttr((string) __('Register a New Passkey'))
                . '</button>'
                . '<div id="m2oidc-passkey-register-error" class="message message-error error" style="display:none;margin-top:8px;"></div>';

            $anchorFieldId = $fieldset->getElement('expiration') ? 'expiration' : 'interface_locale';
            $fieldset->addField(
                'm2oidc_passkey_info',
                'note',
                [
                    'label' => __('Passkeys'),
                    'text' => $rows . $registerHtml,
                ],
                $anchorFieldId
            );
        }

        return $proceed();
    }
}
