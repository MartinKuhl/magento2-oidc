/**
 * Event-delegation handlers for the "Register a New Passkey" / "Remove"
 * buttons injected into the admin User edit page by PasskeyUserInfoPlugin.
 *
 * Loaded automatically via requirejs-config deps, same pattern as unlink-button.js.
 */
define(['jquery'], function ($) {
    'use strict';

    $(document).on('click', '.m2oidc-passkey-delete-btn', function () {
        var btn = this,
            config = $(btn).data('m2oidcPasskeyConfig');

        if (!config || !window.confirm(config.confirmMessage)) {
            return;
        }

        var fd = new FormData();
        fd.append('credential_id', config.credentialId);
        fd.append('form_key', window.FORM_KEY || '');

        fetch(config.deleteUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) {
                    $(btn).closest('.m2oidc-passkey-row').remove();
                } else {
                    window.alert(d.error || 'Unable to remove passkey.');
                }
            })
            .catch(function () { window.alert('Request failed.'); });
    });

    $(document).on('click', '#m2oidc-passkey-register-btn', function () {
        var btn = this,
            config = $(btn).data('m2oidcPasskeyRegisterConfig'),
            $error = $('#m2oidc-passkey-register-error');

        if (!config) {
            return;
        }
        if (!window.PublicKeyCredential || typeof PublicKeyCredential.parseCreationOptionsFromJSON !== 'function') {
            window.alert('This browser does not support passkeys.');
            return;
        }

        $error.hide();
        $(btn).prop('disabled', true);

        var fd = new FormData();
        fd.append('form_key', window.FORM_KEY || '');

        fetch(config.optionsUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    throw new Error(data.error || 'Unable to start passkey registration.');
                }
                var options = PublicKeyCredential.parseCreationOptionsFromJSON(data.options);
                return navigator.credentials.create({ publicKey: options }).then(function (credential) {
                    var nickname = window.prompt('Name this passkey (e.g. "MacBook Touch ID"):', '') || '';
                    var verifyFd = new FormData();
                    verifyFd.append('form_key', window.FORM_KEY || '');
                    verifyFd.append('nonce', data.nonce);
                    verifyFd.append('nickname', nickname);
                    verifyFd.append('credential', JSON.stringify(credential.toJSON()));
                    return fetch(config.verifyUrl, { method: 'POST', body: verifyFd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.json(); });
                });
            })
            .then(function (result) {
                if (result.success) {
                    window.location.reload();
                    return;
                }
                $error.text(result.error || 'Passkey registration failed.').show();
                $(btn).prop('disabled', false);
            })
            .catch(function (err) {
                $(btn).prop('disabled', false);
                if (err && err.name === 'NotAllowedError') {
                    return;
                }
                $error.text(err && err.message ? err.message : 'Passkey registration failed.').show();
            });
    });
});
