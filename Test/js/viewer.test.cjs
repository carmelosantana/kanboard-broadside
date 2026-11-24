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
