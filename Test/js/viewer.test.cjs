const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const vm = require('node:vm');

test('viewer replies immediately to the first ready from its own iframe and relays class changes', () => {
    const script = readFileSync(require.resolve('../../Assets/js/viewer.js'), 'utf8');
    const messages = [];
    const frameWindow = { postMessage: (...args) => messages.push(args) };
    const frame = { contentWindow: frameWindow, dataset: { theme: 'auto', preset: 'newburgh' } };
    let classes = ['theme-light', 'preset-shadcn'];
    const html = { classList: { contains: (name) => classes.includes(name) } };
    let listener;
    let onMutation;
    vm.runInNewContext(script, {
        document: {
            documentElement: html,
            querySelector: () => frame,
        },
        window: {
            addEventListener(type, callback) {
                assert.equal(type, 'message');
                listener = callback;
            },
        },
        MutationObserver: class {
            constructor(callback) {
                onMutation = callback;
            }

            observe(target, options) {
                assert.equal(target, html);
                assert.equal(options.attributes, true);
                assert.equal(String(options.attributeFilter), 'class');
            }
        },
    });

    function send(source, type, nonce) {
        listener({ source, data: { type, nonce } });
    }

    onMutation();
    send({}, 'broadside:ready', 'foreign');
    send(frameWindow, 'broadside:submit', 'early');
    assert.equal(messages.length, 0);

    send(frameWindow, 'broadside:ready', 'accepted');
    assert.equal(messages.length, 1);
    assert.deepEqual({ ...messages[0][0] }, {
        type: 'broadside:theme', nonce: 'accepted', theme: 'light', preset: 'shadcn',
    });
    assert.equal(messages[0][1], '*');

    send(frameWindow, 'broadside:ready', 'replacement');
    assert.equal(messages.length, 1);
    classes = ['theme-dark', 'preset-newburgh'];
    onMutation();
    assert.equal(messages.length, 2);
    assert.deepEqual({ ...messages[1][0] }, {
        type: 'broadside:theme', nonce: 'accepted', theme: 'dark', preset: 'newburgh',
    });
});

test('viewer resizes only for finite heights from its own iframe with the negotiated nonce', () => {
    const script = readFileSync(require.resolve('../../Assets/js/viewer.js'), 'utf8');
    const nonce = 'frame-nonce';
    const frameWindow = { postMessage() {} };
    const frame = { contentWindow: frameWindow, dataset: {}, style: { height: '' } };
    let listener;

    vm.runInNewContext(script, {
        document: {
            documentElement: { classList: { contains: () => false } },
            querySelector: () => frame,
        },
        window: { addEventListener: (type, callback) => { listener = callback; } },
        MutationObserver: class { observe() {} },
    });

    function send(height, source = frameWindow, token = nonce) {
        listener({ source, data: { type: 'broadside:height', nonce: token, height } });
    }

    send(1200);
    assert.equal(frame.style.height, '');
    listener({ source: frameWindow, data: { type: 'broadside:ready', nonce } });
    send(1200, {});
    send(1200, frameWindow, 'wrong');
    send('1200');
    send(Infinity);
    send(NaN);
    assert.equal(frame.style.height, '');

    send(1200);
    assert.equal(frame.style.height, '1200px');
    send(300);
    assert.equal(frame.style.height, '480px');
    send(25000);
    assert.equal(frame.style.height, '20000px');
});

test('viewer hides the quote control on deselection and keeps a quote already copied', () => {
    const script = readFileSync(require.resolve('../../Assets/js/viewer.js'), 'utf8');
    const nonce = 'frame-nonce';
    const frameWindow = { postMessage() {} };
    const quote = { value: '', focus() {} };
    const button = { hidden: true };
    const elements = {
        '#broadside-frame': { contentWindow: frameWindow, dataset: {} },
        '#broadside-comment-quote': quote,
        '#broadside-quote-selection': button,
    };
    let listener;

    vm.runInNewContext(script, {
        document: {
            documentElement: { classList: { contains: () => false } },
            querySelector: (selector) => elements[selector],
        },
        window: { addEventListener: (type, callback) => { listener = callback; } },
        MutationObserver: class { observe() {} },
    });

    listener({ source: frameWindow, data: { type: 'broadside:ready', nonce } });
    const select = (text) => listener({ source: frameWindow, data: { type: 'broadside:selection', nonce, text } });
    select('Release notes');
    assert.equal(button.hidden, false);
    button.onclick();
    select('');
    assert.equal(button.hidden, true);
    assert.equal(quote.value, 'Release notes');
});

test('viewer relays only its own iframe requests with the negotiated nonce', async () => {
    const script = readFileSync(require.resolve('../../Assets/js/viewer.js'), 'utf8');
    const messages = [];
    const requests = [];
    const frameWindow = { postMessage: (message) => messages.push({ ...message }) };
    const href = 'https://kanboard.example/subdir/?controller=ArtifactController&action=show';
    const frame = {
        contentWindow: frameWindow,
        dataset: {
            submitUrl: '/subdir/?controller=BridgeController&action=submit&artifact_id=4',
            resultsUrl: '/subdir/broadside/artifact/4/results',
            version: '2',
        },
        closest: () => ({ dataset: { csrf: 'reusable-token' } }),
    };
    let listener;

    vm.runInNewContext(script, {
        document: {
            documentElement: { classList: { contains: () => false } },
            querySelector: () => frame,
            baseURI: href,
        },
        window: {
            addEventListener: (type, callback) => { listener = callback; },
            fetch: async (url, options) => {
                requests.push({ url: String(url), options });
                return options.method === 'POST'
                    ? { ok: true, json: async () => ({ id: 42 }) }
                    : { ok: true, json: async () => ({ count: 1 }) };
            },
        },
        MutationObserver: class { observe() {} },
        URL,
        URLSearchParams,
    });

    const nonce = 'frame-nonce';
    listener({ source: frameWindow, data: { type: 'broadside:ready', nonce } });
    messages.length = 0;

    const submit = { type: 'broadside:submit', nonce, id: 1, formKey: 'poll', data: { choice: 'yes' }, opts: { once: true } };
    const results = { type: 'broadside:results', nonce, id: 2, formKey: 'poll' };
    listener({ source: {}, data: submit });
    listener({ source: frameWindow, data: { ...results, nonce: 'wrong' } });
    await new Promise(setImmediate);
    assert.equal(requests.length, 0);
    assert.equal(messages.length, 0);

    listener({ source: frameWindow, data: submit });
    listener({ source: frameWindow, data: results });
    await new Promise(setImmediate);
    assert.equal(requests.length, 2);
    assert.equal(requests[0].url, 'https://kanboard.example/subdir/?controller=BridgeController&action=submit&artifact_id=4');
    assert.equal(requests[0].options.method, 'POST');
    assert.deepEqual(Object.fromEntries(requests[0].options.body), {
        csrf_token: 'reusable-token', version: '2', form_key: 'poll', payload: '{"choice":"yes"}', once: '1',
    });
    assert.equal(requests[1].url, 'https://kanboard.example/subdir/broadside/artifact/4/results?form_key=poll');
    assert.equal(requests[1].options.method, 'GET');
    assert.deepEqual(messages, [
        { type: 'broadside:response', nonce, id: 1, result: { id: 42 } },
        { type: 'broadside:response', nonce, id: 2, result: { count: 1 } },
    ]);
});
