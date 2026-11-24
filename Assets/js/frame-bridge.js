(() => {
    'use strict';

    const nonce = document.currentScript.dataset.nonce;
    const parentOrigin = window.location.origin;
    const pending = new Map();
    const themeListeners = new Set();
    let nextId = 0;

    function send(type, fields = {}) {
        window.parent.postMessage({ type, nonce, ...fields }, parentOrigin);
    }

    function request(type, fields) {
        if (window.parent === window) {
            return Promise.reject(new Error('Broadside is outside its viewer.'));
        }
        return new Promise((resolve, reject) => {
            const id = ++nextId;
            const timeout = window.setTimeout(() => {
                pending.delete(id);
                reject(new Error('Broadside viewer did not respond.'));
            }, 15000);
            pending.set(id, { resolve, reject, timeout });
            send(type, { id, ...fields });
        });
    }

    window.addEventListener('message', (event) => {
        const message = event.data;
        if (event.source !== window.parent || event.origin !== parentOrigin || !message || message.nonce !== nonce) {
            return;
        }
        if (message.type === 'broadside:theme') {
            const theme = message.theme === 'light' ? 'light' : 'dark';
            const preset = message.preset === 'shadcn' ? 'shadcn' : 'newburgh';
            const classes = document.documentElement.classList;
            classes.remove('theme-dark', 'theme-light', 'preset-newburgh', 'preset-shadcn');
            classes.add('theme-' + theme, 'preset-' + preset);
            themeListeners.forEach((listener) => listener({ theme, preset }));
            return;
        }
        if (message.type === 'broadside:response' && pending.has(message.id)) {
            const { resolve, reject, timeout } = pending.get(message.id);
            pending.delete(message.id);
            window.clearTimeout(timeout);
            if (message.error) {
                reject(new Error(message.error));
            } else {
                resolve(message.result);
            }
        }
    });

    window.broadside = {
        submit(formKey, data, opts = {}) {
            return request('broadside:submit', { formKey, data, opts });
        },
        results(formKey) {
            return request('broadside:results', { formKey });
        },
        onTheme(listener) {
            themeListeners.add(listener);
            return () => themeListeners.delete(listener);
        }
    };

    if (window.parent !== window) {
        const root = document.documentElement;
        const relayHeight = () => {
            // The root's scrollHeight includes the viewport, even after the content shrinks.
            const overflowHeight = root.scrollHeight > root.clientHeight ? root.scrollHeight : 0;
            const height = Math.max(root.offsetHeight, document.body?.scrollHeight ?? 0, overflowHeight);
            send('broadside:height', { height });
        };
        const relaySelection = () => {
            const selection = window.getSelection()?.toString().trim();
            if (selection) {
                const text = Array.from(selection).slice(0, 1000).join('');
                send('broadside:selection', { text });
            }
        };

        document.addEventListener('mouseup', relaySelection);
        document.addEventListener('keyup', relaySelection);
        window.addEventListener('load', relayHeight);
        new ResizeObserver(relayHeight).observe(root);
        send('broadside:ready');
    }
})();
