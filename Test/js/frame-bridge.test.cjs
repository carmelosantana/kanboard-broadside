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
            addEventListener: (type, listener) => listeners.set(type, listener),
        },
        window: {
            location: { origin },
            parent: { postMessage: (...args) => messages.push(args) },
            addEventListener() {},
            getSelection: () => selection,
        },
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
