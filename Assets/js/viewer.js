(() => {
    'use strict';

    const root = document.documentElement;
    let frame;
    let nonce;
    let selection = '';

    function relaySelection(text) {
        const quote = document.querySelector('#broadside-comment-quote');
        const button = document.querySelector('#broadside-quote-selection');
        if (!quote || !button) {
            return;
        }

        selection = text.trim();
        button.hidden = selection === '';
        button.onclick = () => {
            quote.value = selection;
            quote.focus();
        };
    }

    function relayTheme() {
        if (!frame) {
            return;
        }

        const classes = root.classList;
        const theme = classes.contains('theme-light') ? 'light'
            : classes.contains('theme-dark') ? 'dark' : frame.dataset.theme;
        const preset = classes.contains('preset-shadcn') ? 'shadcn'
            : classes.contains('preset-newburgh') ? 'newburgh' : frame.dataset.preset;

        // The sandboxed document has an opaque origin.
        frame.contentWindow.postMessage({ type: 'broadside:theme', nonce, theme, preset }, '*');
    }

    window.addEventListener('message', (event) => {
        const message = event.data;
        if (frame) {
            if (event.source === frame.contentWindow && message?.nonce === nonce
                && message.type === 'broadside:selection' && typeof message.text === 'string') {
                relaySelection(message.text);
            }
            return;
        }

        if (message?.type !== 'broadside:ready' || typeof message.nonce !== 'string' || message.nonce === '') {
            return;
        }

        const ownFrame = document.querySelector('#broadside-frame');
        if (!ownFrame?.contentWindow || event.source !== ownFrame.contentWindow) {
            return;
        }

        frame = ownFrame;
        nonce = message.nonce;
        relayTheme();
    });

    new MutationObserver(relayTheme).observe(root, { attributes: true, attributeFilter: ['class'] });
})();
