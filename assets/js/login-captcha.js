(() => {
    const form = document.querySelector('[data-login-captcha]');
    if (!form || !window.crypto?.subtle) return;
    const challengeInput = form.elements.namedItem('captcha_challenge');
    const solutionInput = form.elements.namedItem('captcha_solution');
    const encoder = new TextEncoder();
    let challengeNumber = 0;
    let bestChallengeNumber = 0;
    let proofAt = 0;

    async function refreshChallenge() {
        const current = ++challengeNumber;
        try {
            const response = await fetch('login-challenge.php', { cache: 'no-store', credentials: 'same-origin' });
            if (!response.ok) return;
            const { challenge } = await response.json();
            if (typeof challenge !== 'string') return;
            for (let counter = 0; counter < 0x1000000; counter++) {
                const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', encoder.encode(`${challenge}:${counter}`)));
                if (digest[0] === 0 && digest[1] === 0 && (digest[2] & 0xf0) === 0) {
                    if (current >= bestChallengeNumber) {
                        bestChallengeNumber = current;
                        challengeInput.value = challenge;
                        solutionInput.value = String(counter);
                        proofAt = Date.now();
                    }
                    return;
                }
                if (counter % 128 === 127) {
                    await new Promise(resolve => setTimeout(resolve, 0));
                }
            }
        } catch (_) {
            // Retry on the next silent refresh.
        }
    }

    form.addEventListener('submit', event => {
        if (challengeInput.value && solutionInput.value && Date.now() - proofAt < 12000) return;
        event.preventDefault();
        refreshChallenge();
    });
    refreshChallenge();
    window.setInterval(refreshChallenge, 5000);
})();
