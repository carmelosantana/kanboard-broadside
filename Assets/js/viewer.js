(() => {
    'use strict';

    const root = document.documentElement;
    let frame;
    let nonce;

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
        if (frame || message?.type !== 'broadside:ready' || typeof message.nonce !== 'string' || message.nonce === '') {
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
