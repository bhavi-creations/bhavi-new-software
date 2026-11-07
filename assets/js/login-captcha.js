(() => {
    const form = document.querySelector('[data-login-captcha]');
    if (!form || !window.crypto?.subtle) return;
    const challengeInput = form.elements.namedItem('captcha_challenge');
    const solutionInput = form.elements.namedItem('captcha_solution');
    const encoder = new TextEncoder();
    let generation = 0;

    async function refreshChallenge() {
        const current = ++generation;
        challengeInput.value = '';
        solutionInput.value = '';
        try {
            const response = await fetch('login-challenge.php', { cache: 'no-store', credentials: 'same-origin' });
            if (!response.ok) return;
            const { challenge } = await response.json();
            if (current !== generation || typeof challenge !== 'string') return;
            for (let counter = 0; counter < 0x1000000; counter++) {
                const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', encoder.encode(`${challenge}:${counter}`)));
                if (digest[0] === 0 && digest[1] === 0 && (digest[2] & 0xf0) === 0) {
                    if (current === generation) {
                        challengeInput.value = challenge;
                        solutionInput.value = String(counter);
                    }
                    return;
                }
                if (counter % 128 === 127) {
                    await new Promise(resolve => setTimeout(resolve, 0));
                    if (current !== generation) return;
                }
            }
        } catch (_) {
            // Retry on the next silent refresh.
        }
    }

    form.addEventListener('submit', event => {
        if (challengeInput.value && solutionInput.value) return;
        event.preventDefault();
        refreshChallenge();
    });
    refreshChallenge();
    window.setInterval(refreshChallenge, 5000);
})();
