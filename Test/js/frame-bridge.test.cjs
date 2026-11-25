const assert = require('node:assert/strict');
const { execFile } = require('node:child_process');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const { promisify } = require('node:util');
const vm = require('node:vm');

test('the rendered public bridge rejects submit and results calls as read-only', async () => {
    const { stdout: html } = await promisify(execFile)('php', ['-r', String.raw`
        namespace Pimple;
        class Container {} // Rendering HTML does not use the container.

        require $argv[1];
        $renderer = new \Kanboard\Plugin\Broadside\Model\Renderer(new Container(), __DIR__);
        echo $renderer->render('<p>Shared page</p>', 'html', readOnly: true);
    `, require.resolve('../../Model/Renderer.php')]);
    const bridge = html.match(/<script id="broadside-bridge">([\s\S]*?)<\/script>/);
    assert.ok(bridge, 'expected a bridge script in the rendered HTML');

    const window = {};
    vm.runInNewContext(bridge[1], { window, Promise, Error });
    assert.equal(window.broadside.readOnly, true);
    await assert.rejects(window.broadside.submit('poll', { choice: 'a' }, { once: true }), {
        name: 'Error', message: 'read-only link',
    });
    await assert.rejects(window.broadside.results('poll'), {
        name: 'Error', message: 'read-only link',
    });
});

test('frame bridge relays mouse and keyboard selections, including deselection', () => {
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
    selection = '  Release notes  ';
    dispatch('mouseup');
    selection = '';
    dispatch('mouseup');
    selection = '🚢'.repeat(1001);
    dispatch('keyup');
    selection = '   ';
    dispatch('keyup');

    assert.deepEqual(messages.map(([message, target]) => [{ ...message }, target]), [
        [{ type: 'broadside:selection', nonce, text: 'Release notes' }, origin],
        [{ type: 'broadside:selection', nonce, text: '' }, origin],
        [{ type: 'broadside:selection', nonce, text: '🚢'.repeat(1000) }, origin],
        [{ type: 'broadside:selection', nonce, text: '' }, origin],
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

test('frame bridge waits for the viewer to confirm a submit but times out results', async () => {
    const script = readFileSync(require.resolve('../../Assets/js/frame-bridge.js'), 'utf8');
    const timers = [];
    const parent = { postMessage() {} };
    const window = {
        location: { origin: 'https://kanboard.example' },
        parent,
        addEventListener() {},
        setTimeout: (callback) => timers.push(callback),
        clearTimeout() {},
    };
    vm.runInNewContext(script, {
        document: { currentScript: { dataset: { nonce: 'n' } }, documentElement: {}, addEventListener() {} },
        window,
        ResizeObserver: class { observe() {} },
    });

    let submitSettled = false;
    window.broadside.submit('poll', { choice: 'a' }).then(() => { submitSettled = true; }, () => { submitSettled = true; });
    const results = window.broadside.results('poll');
    timers.splice(0).forEach((callback) => callback());
    await assert.rejects(results, { message: 'Broadside viewer did not respond.' });
    assert.equal(submitSettled, false, 'a submit stays pending while the viewer decides');
});
