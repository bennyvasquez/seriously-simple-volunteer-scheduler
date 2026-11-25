(() => {
    // A control marked data-refreshes-form reloads the form when it changes, so the screen can show what the choice means (the repeat options, the preview). Without JavaScript the form's own "Update the preview" style button does the same.
    document.addEventListener('change', (event) => {
        const control = event.target;
        if (! (control instanceof Element) || ! control.matches('[data-refreshes-form]') || ! control.form) {
            return;
        }

        const refresh = control.form.querySelector('input[name=refresh]');
        if (refresh) {
            refresh.value = '1';
        }
        control.form.submit();
    });
})();
