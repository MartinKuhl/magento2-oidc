<?php

declare(strict_types=1);

/**
 * OIDC Identity Field Plugin
 *
 * Removes the "required" validation from the identity verification password field
 * for OIDC-authenticated admin users. This works in conjunction with the server-side
 * OidcIdentityVerificationPlugin to provide a complete bypass of password re-authentication.
 *
 * Applies to:
 * - Magento\User\Block\User\Edit\Tab\Main (User edit form)
 * - Magento\User\Block\Role\Tab\Info (Role edit form)
 * - Magento\Backend\Block\System\Account\Edit\Form (Account settings form)
 *
 * @package M2Oidc\OAuth\Plugin\User\Block
 */
namespace M2Oidc\OAuth\Plugin\User\Block;

use Magento\Framework\Stdlib\CookieManagerInterface;

class OidcIdentityFieldPlugin
{
    /** @var \Magento\Framework\Stdlib\CookieManagerInterface */
    protected \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager;

    /**
     * Initialize OIDC identity field plugin.
     *
     * @param CookieManagerInterface $cookieManager
     */
    public function __construct(
        CookieManagerInterface $cookieManager
    ) {
        $this->cookieManager = $cookieManager;
    }

    /**
     * After setForm - modify the current_password field for OIDC users
     *
     * Removes the required attribute and required-entry CSS class from the
     * identity verification password field when the user is OIDC-authenticated.
     *
     * @param  mixed $subject The form block
     * @param  mixed $result  The result of setForm
     * @return mixed
     */
    public function afterSetForm($subject, $result)
    {
        // Read OIDC/passkey flag from cookie (set by Oidccallback / Passkey\LoginVerify controllers)
        $isOidcAuth = $this->cookieManager->getCookie('oidc_authenticated') === '1'
            || $this->cookieManager->getCookie('passkey_authenticated') === '1';

        // Only modify for OIDC-authenticated users
        if ($isOidcAuth) {
            $form = $subject->getForm();

            if ($form) {
                $field = $form->getElement('current_password');

                if ($field) {
                    // Remove required attribute
                    $field->setRequired(false);

                    // Remove required-entry CSS class (used by Magento's JS validation)
                    $currentClass = $field->getClass();
                    $classStr = (string) $currentClass;
                    $replaced = str_replace('required-entry', '', $classStr);
                    $field->setClass(trim($replaced));
                }
            }
        }

        return $result;
    }
}
