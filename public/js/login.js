(() => {
    // Both buttons submit this one form, so the browser sends every field. When it's "Email me a link", leave a typed password out of the request. Without JavaScript the link request ignores it anyway.
    const form = document.getElementById('login-form');
    form.addEventListener('submit', (event) => {
        if (event.submitter && event.submitter.id === 'send-link') {
            form.elements.password.value = '';
            form.elements.remember.checked = false;
        }
    });

    // Pressing Enter submits with the form's first button, "Email me a link". In the password box, Enter means "Log in with my password".
    form.elements.password.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && ! event.isComposing) {
            event.preventDefault();
            const passwordLogin = document.getElementById('password-login');
            form.requestSubmit ? form.requestSubmit(passwordLogin) : passwordLogin.click();
        }
    });
})();
