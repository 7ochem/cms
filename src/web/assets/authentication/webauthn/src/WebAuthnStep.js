"use strict";
class WebAuthnStep extends AuthenticationStep {
    constructor() {
        super('craft\\authentication\\type\\mfa\\WebAuthn');
        this.$button = $('#verify-webauthn');
        this.$loginForm.trigger('submit');
        this.$button.on('click', () => { this.$loginForm.trigger('submit'); });
        this.$submit.hide();
    }
    validate() {
        this.$button.addClass('hidden');
        return true;
    }
    async returnFormData() {
        const optionData = this.$button.data('request-options');
        // Sort-of deep copy
        const requestOptions = Object.assign({}, optionData);
        if (optionData.allowCredentials) {
            requestOptions.allowCredentials = [...optionData.allowCredentials];
        }
        // proprietary base 64 decode, for some reason
        requestOptions.challenge = atob(requestOptions.challenge.replace(/-/g, '+').replace(/_/g, '/'));
        // Unpack to binary data
        requestOptions.challenge = Uint8Array.from(requestOptions.challenge, c => c.charCodeAt(0));
        for (const idx in requestOptions.allowCredentials) {
            let allowed = requestOptions.allowCredentials[idx];
            requestOptions.allowCredentials[idx] = {
                id: Uint8Array.from(atob(allowed.id.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0)),
                type: allowed.type
            };
        }
        let credential;
        try {
            credential = await navigator.credentials.get({
                publicKey: requestOptions
            });
        }
        catch (error) {
            this.$button.removeClass('hidden');
            throw Craft.t('app', 'Failed to authenticate');
        }
        const response = credential.response;
        return {
            credentialResponse: {
                id: credential.id,
                rawId: credential.id,
                response: {
                    authenticatorData: btoa(String.fromCharCode(...new Uint8Array(response.authenticatorData))),
                    clientDataJSON: btoa(String.fromCharCode(...new Uint8Array(response.clientDataJSON))),
                    signature: btoa(String.fromCharCode(...new Uint8Array(response.signature))),
                    userHandle: response.userHandle ? btoa(String.fromCharCode(...new Uint8Array(response.userHandle))) : null,
                },
                type: credential.type,
            }
        };
    }
}
new WebAuthnStep();
