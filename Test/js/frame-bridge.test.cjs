const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const vm = require('node:vm');

test('frame bridge relays non-empty mouse and keyboard selections to the viewer', () => {
    const script = readFileSync(require.resolve('../../Assets/js/frame-bridge.js'), 'utf8');
    const origin = 'https://kanboard.example';
    const nonce = 'frame-nonce';
    const messages = [];
    const listeners = new Map();
    let selection = '';

    vm.runInNewContext(script, {
        document: {
            currentScript: { dataset: { nonce } },
            documentElement: {},
            addEventListener: (type, listener) => listeners.set(type, listener),
        },
        window: {
            location: { origin },
            parent: { postMessage: (...args) => messages.push(args) },
            addEventListener() {},
            getSelection: () => selection,
        },
        ResizeObserver: class { observe() {} },
    });

    function dispatch(type) {
        listeners.get(type)?.();
    }

    assert.deepEqual({ ...messages.shift()[0] }, { type: 'broadside:ready', nonce });
    dispatch('mouseup');
    selection = '   ';
    dispatch('keyup');
    assert.equal(messages.length, 0);

    selection = '  Release notes  ';
    dispatch('mouseup');
    selection = '🚢'.repeat(1001);
    dispatch('keyup');

    assert.deepEqual(messages.map(([message, target]) => [{ ...message }, target]), [
        [{ type: 'broadside:selection', nonce, text: 'Release notes' }, origin],
        [{ type: 'broadside:selection', nonce, text: '🚢'.repeat(1000) }, origin],
    ]);
});

test('frame bridge posts the document height on load and when the root resizes', () => {
    const script = readFileSync(require.resolve('../../Assets/js/frame-bridge.js'), 'utf8');
    const origin = 'https://kanboard.example';
    const nonce = 'frame-nonce';
    const messages = [];
    const listeners = new Map();
    const root = { clientHeight: 480, offsetHeight: 1200, scrollHeight: 1250 };
    let onResize;

    vm.runInNewContext(script, {
        document: {
            currentScript: { dataset: { nonce } },
            documentElement: root,
            addEventListener() {},
        },
        window: {
            location: { origin },
            parent: { postMessage: (...args) => messages.push(args) },
            addEventListener: (type, listener) => listeners.set(type, listener),
        },
        ResizeObserver: class {
            constructor(callback) {
                onResize = callback;
            }

            observe(target) {
                assert.equal(target, root);
            }
        },
    });

    assert.equal(messages.shift()[0].type, 'broadside:ready');
    assert.ok(listeners.has('load'));
    listeners.get('load')();
    Object.assign(root, { clientHeight: 1250, offsetHeight: 640, scrollHeight: 1250 });
    onResize();

    assert.deepEqual(messages.map(([message, target]) => [{ ...message }, target]), [
        [{ type: 'broadside:height', nonce, height: 1250 }, origin],
        [{ type: 'broadside:height', nonce, height: 640 }, origin],
    ]);
});
