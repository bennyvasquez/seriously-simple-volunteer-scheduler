(() => {
    // The button stays hidden where the browser can't copy, such as a page not served over HTTPS.
    if (! navigator.clipboard) {
        return;
    }

    const copy = document.getElementById('copy-codes');
    const failed = document.getElementById('copy-failed');
    copy.hidden = false;
    copy.addEventListener('click', () => {
        const text = [...document.querySelectorAll('#recovery-codes .code')].map(c => c.textContent).join('\n');
        navigator.clipboard.writeText(text).then(
            () => { failed.hidden = true; copy.textContent = 'Copied'; },
            () => { failed.hidden = false; },
        );
    });
})();
