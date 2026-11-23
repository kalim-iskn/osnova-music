import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const script = fs.readFileSync(new URL('../resources/js/Components/TrackActionsMenu.vue', import.meta.url), 'utf8')
    .match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*;\r?\n/gm, '');
const window = { removeEventListener() {} }, document = { removeEventListener() {} };
const context = vm.createContext({
    defineProps: () => ({ track: { id: 1 } }), ref: value => ({ value }),
    computed: getter => ({ get value() { return getter(); } }), nextTick: async () => {},
    onBeforeUnmount() {}, usePlayerStore: () => ({ queue: [] }), window, document,
});
vm.runInContext(script + `
    const inside = {}, outside = {};
    panel.value = { contains: target => target === inside };
    globalThis.check = (type, target) => {
        isOpen.value = true; isChoosingPosition.value = true;
        handleViewportChange({ type, target: target === 'inside' ? inside : target === 'window' ? window : outside });
        return { open: isOpen.value, picking: isChoosingPosition.value };
    };
`, context);
assert.equal(context.check('scroll', 'inside').open, true);
assert.equal(context.check('scroll', 'inside').picking, true);
assert.equal(context.check('scroll', 'outside').open, false);
assert.equal(context.check('scroll', 'window').open, false);
assert.equal(context.check('resize', 'window').open, false);
console.log('Queue picker keeps internal scrolling open and closes on viewport changes.');
