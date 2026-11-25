(() => {
    'use strict';

    const root = document.documentElement;
    let frame;
    let nonce;
    let confirming = false;

    function relaySelection(text) {
        const quote = document.querySelector('#broadside-comment-quote');
        const button = document.querySelector('#broadside-quote-selection');
        if (!quote || !button) {
            return;
        }

        const selection = text.trim();
        button.hidden = selection === '';
        if (button.hidden) {
            button.onclick = null;
            return;
        }
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

    // Frame script can post a submit at any time, so only a trusted click in this page sends it.
    function confirmSubmit(message) {
        return new Promise((resolve) => {
            const panel = document.createElement('div');
            panel.className = 'broadside-confirm';
            panel.setAttribute('role', 'alertdialog');
            const question = document.createElement('p');
            question.textContent = 'Send your response to ' + message.formKey + '?';
            const summary = document.createElement('pre');
            const json = JSON.stringify(message.data, null, 2) ?? '';
            summary.textContent = json.length > 2000 ? json.slice(0, 2000) + '\n…' : json;
            const send = document.createElement('button');
            send.type = 'button';
            send.className = 'btn btn-blue';
            send.textContent = 'Send';
            // Arm after a pause so a click already aimed at the frame cannot land on Send.
            send.disabled = true;
            window.setTimeout(() => { send.disabled = false; }, 500);
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'btn';
            cancel.textContent = 'Cancel';
            const answer = (confirmed) => (event) => {
                if (!event.isTrusted || (confirmed && send.disabled)) {
                    return;
                }
                panel.remove();
                resolve(confirmed);
            };
            send.addEventListener('click', answer(true));
            cancel.addEventListener('click', answer(false));
            panel.append(question, summary, send, cancel);
            frame.before(panel);
            cancel.focus();
        });
    }

    async function relayRequest(message) {
        const reply = { type: 'broadside:response', nonce, id: message.id };
        const submit = message.type === 'broadside:submit';
        if (submit) {
            if (confirming) {
                reply.error = 'Another response is waiting for confirmation.';
                frame.contentWindow.postMessage(reply, '*');
                return;
            }
            confirming = true;
            const confirmed = await confirmSubmit(message);
            confirming = false;
            if (!confirmed) {
                reply.error = 'You cancelled the response.';
                frame.contentWindow.postMessage(reply, '*');
                return;
            }
        }
        try {
            const url = new URL(submit ? frame.dataset.submitUrl : frame.dataset.resultsUrl, document.baseURI);
            const options = { method: submit ? 'POST' : 'GET', mode: 'same-origin' };
            if (submit) {
                options.body = new URLSearchParams({
                    csrf_token: frame.closest('.broadside-viewer').dataset.csrf,
                    version: frame.dataset.version,
                    form_key: message.formKey,
                    payload: JSON.stringify(message.data),
                    once: message.opts?.once === true ? '1' : '0',
                });
            } else {
                url.searchParams.set('form_key', message.formKey);
            }
            const response = await window.fetch(url, options);
            const body = await response.json();
            if (response.ok) {
                reply.result = body;
            } else {
                reply.error = body.message || 'Broadside request failed.';
            }
        } catch {
            reply.error = 'Broadside request failed.';
        }
        frame.contentWindow.postMessage(reply, '*');
    }

    window.addEventListener('message', (event) => {
        const message = event.data;
        if (frame) {
            if (event.source !== frame.contentWindow || message?.nonce !== nonce) {
                return;
            }
            if (message.type === 'broadside:height' && Number.isFinite(message.height)) {
                const height = Math.min(20000, Math.max(480, message.height));
                frame.style.height = height + 'px';
            }
            if (message.type === 'broadside:selection' && typeof message.text === 'string') {
                relaySelection(message.text);
            }
            if ((message.type === 'broadside:submit' || message.type === 'broadside:results')
                && Number.isSafeInteger(message.id) && typeof message.formKey === 'string') {
                relayRequest(message);
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
