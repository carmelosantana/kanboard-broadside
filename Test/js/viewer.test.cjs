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

class FakeElement {
    constructor(tag) {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.listeners = {};
        this.attributes = {};
        this.textContent = '';
        this.className = '';
        this.parent = null;
    }

    set innerHTML(value) {
        throw new Error('viewer must not use innerHTML');
    }

    append(...nodes) {
        for (const node of nodes) {
            node.parent = this;
            this.children.push(node);
        }
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }

    addEventListener(type, callback) {
        this.listeners[type] = callback;
    }

    focus() {}

    remove() {
        if (this.parent) {
            this.parent.children = this.parent.children.filter((child) => child !== this);
            this.parent = null;
        }
    }

    get text() {
        return this.textContent + this.children.map((child) => child.text).join('');
    }

    find(predicate) {
        if (predicate(this)) {
            return this;
        }
        for (const child of this.children) {
            const found = child.find(predicate);
            if (found) {
                return found;
            }
        }
        return null;
    }
}

function relayHarness() {
    const script = readFileSync(require.resolve('../../Assets/js/viewer.js'), 'utf8');
    const messages = [];
    const requests = [];
    const timers = [];
    const frameWindow = { postMessage: (message) => messages.push({ ...message }) };
    const href = 'https://kanboard.example/subdir/?controller=ArtifactController&action=show';
    const viewer = new FakeElement('div');
    viewer.dataset = { csrf: 'reusable-token' };
    const frame = {
        contentWindow: frameWindow,
        dataset: {
            submitUrl: '/subdir/?controller=BridgeController&action=submit&artifact_id=4',
            resultsUrl: '/subdir/broadside/artifact/4/results',
            version: '2',
        },
        closest: () => viewer,
        before: (node) => viewer.append(node),
    };
    let listener;

    vm.runInNewContext(script, {
        document: {
            documentElement: { classList: { contains: () => false } },
            querySelector: () => frame,
            createElement: (tag) => new FakeElement(tag),
            baseURI: href,
        },
        window: {
            addEventListener: (type, callback) => { listener = callback; },
            setTimeout: (callback) => timers.push(callback),
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

    const button = (label) => viewer.find((node) => node.tagName === 'BUTTON' && node.textContent === label);
    const click = (label, isTrusted = true) => button(label).listeners.click({ isTrusted });
    const armTimers = () => timers.splice(0).forEach((callback) => callback());

    return { listener, frameWindow, nonce, messages, requests, viewer, button, click, armTimers };
}

test('viewer relays only its own iframe requests with the negotiated nonce', async () => {
    const { listener, frameWindow, nonce, messages, requests, click, armTimers } = relayHarness();

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
    armTimers();
    click('Send');
    await new Promise(setImmediate);
    assert.equal(requests.length, 2);
    assert.equal(requests[1].url, 'https://kanboard.example/subdir/?controller=BridgeController&action=submit&artifact_id=4');
    assert.equal(requests[1].options.method, 'POST');
    assert.deepEqual(Object.fromEntries(requests[1].options.body), {
        csrf_token: 'reusable-token', version: '2', form_key: 'poll', payload: '{"choice":"yes"}', once: '1',
    });
    assert.equal(requests[0].url, 'https://kanboard.example/subdir/broadside/artifact/4/results?form_key=poll');
    assert.equal(requests[0].options.method, 'GET');
    assert.deepEqual(messages, [
        { type: 'broadside:response', nonce, id: 2, result: { count: 1 } },
        { type: 'broadside:response', nonce, id: 1, result: { id: 42 } },
    ]);
});

test('viewer asks the viewer to confirm a submission before posting it', async () => {
    const { listener, frameWindow, nonce, messages, requests, viewer, button, click, armTimers } = relayHarness();
    const data = { choice: '<img src=x onerror=alert(1)>' };
    listener({ source: frameWindow, data: { type: 'broadside:submit', nonce, id: 1, formKey: 'poll', data } });
    await new Promise(setImmediate);

    assert.equal(requests.length, 0, 'nothing is posted before the viewer confirms');
    assert.equal(messages.length, 0);
    assert.match(viewer.text, /Send your response to poll\?/);
    assert.ok(viewer.text.includes(JSON.stringify(data, null, 2)), 'the confirmation shows the data as text');

    click('Send');
    await new Promise(setImmediate);
    assert.equal(requests.length, 0, 'the Send button starts disarmed');

    armTimers();
    click('Send', false);
    await new Promise(setImmediate);
    assert.equal(requests.length, 0, 'synthetic clicks do not confirm');

    listener({ source: frameWindow, data: { type: 'broadside:submit', nonce, id: 2, formKey: 'poll', data } });
    await new Promise(setImmediate);
    assert.deepEqual(messages, [
        { type: 'broadside:response', nonce, id: 2, error: 'Another response is waiting for confirmation.' },
    ]);
    messages.length = 0;

    click('Send');
    await new Promise(setImmediate);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(button('Send'), null, 'the confirmation closes');
    assert.deepEqual(messages, [{ type: 'broadside:response', nonce, id: 1, result: { id: 42 } }]);
});

test('viewer rejects a submission the viewer cancels', async () => {
    const { listener, frameWindow, nonce, messages, requests, button, click } = relayHarness();
    listener({ source: frameWindow, data: { type: 'broadside:submit', nonce, id: 7, formKey: 'poll', data: { a: 1 } } });
    await new Promise(setImmediate);

    click('Cancel', false);
    assert.notEqual(button('Cancel'), null, 'synthetic clicks do not cancel either');
    click('Cancel');
    await new Promise(setImmediate);
    assert.equal(requests.length, 0);
    assert.equal(button('Cancel'), null);
    assert.deepEqual(messages, [{ type: 'broadside:response', nonce, id: 7, error: 'You cancelled the response.' }]);
});

test('viewer answers circular submit data with an error and accepts the next submission', async () => {
    const { listener, frameWindow, nonce, messages, requests, viewer } = relayHarness();
    const circular = { name: 'loop' };
    circular.self = circular;
    listener({ source: frameWindow, data: { type: 'broadside:submit', nonce, id: 1, formKey: 'poll', data: circular } });
    await new Promise(setImmediate);

    assert.equal(requests.length, 0);
    assert.deepEqual(messages, [{ type: 'broadside:response', nonce, id: 1, error: 'Broadside request failed.' }]);
    messages.length = 0;

    listener({ source: frameWindow, data: { type: 'broadside:submit', nonce, id: 2, formKey: 'poll', data: { a: 1 } } });
    await new Promise(setImmediate);
    assert.equal(messages.length, 0, 'the next submission is not rejected as already waiting');
    assert.match(viewer.text, /Send your response to poll\?/);
});
