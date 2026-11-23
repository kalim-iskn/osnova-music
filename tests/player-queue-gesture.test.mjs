import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../resources/js/Components/PlayerQueueList.vue', import.meta.url), 'utf8')
    .match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*;\r?\n/gm, '');

function fixture() {
    const listeners = new Map(), timers = new Map(), frames = new Map();
    const moves = [];
    let clock = 1000, id = 0;
    const scroll = { scrollTop: 0, scrollHeight: 400, clientHeight: 400, getBoundingClientRect: () => ({ top: 0, bottom: 400, height: 400 }) };
    const player = { queue: [{ id: 1 }, { id: 2 }, { id: 3 }], moveQueueItem: (...args) => moves.push(args) };
    const context = vm.createContext({
        ref: value => ({ value }), computed: getter => ({ get value() { return getter(); } }),
        usePlayerStore: () => player, onBeforeUnmount() {},
        performance: { now: () => clock },
        document: { body: { style: {} }, scrollingElement: scroll },
        requestAnimationFrame: cb => { frames.set(++id, cb); return id; }, cancelAnimationFrame: key => frames.delete(key),
        window: {
            setTimeout(cb, delay) { timers.set(++id, { cb, at: clock + delay }); return id; },
            clearTimeout: key => timers.delete(key),
            addEventListener(name, cb) { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(cb); },
            removeEventListener(name, cb) { listeners.get(name)?.delete(cb); },
            getComputedStyle: () => ({ overflowY: 'visible' }),
        },
    });
    vm.runInContext(source + `
        listRef.value = { parentElement: null };
        for (let i = 0; i < 3; i++) itemRefs.set(i, { getBoundingClientRect: () => ({ top: 40 + i * 70, left: 0, width: 300, height: 60 }) });
        globalThis.api = { beginMouseGesture, beginTouchGesture, captureRowClick, onPointerCancel,
            get dragging() { return isPointerDragging.value; } };
    `, context);
    const fire = (name, event) => [...(listeners.get(name) || [])].forEach(cb => cb(event));
    const advance = ms => { clock += ms; for (const [key, timer] of [...timers]) if (timer.at <= clock) { timers.delete(key); timer.cb(); } };
    const target = { closest: () => null };
    const mouse = (y, x = 10) => ({ pointerType: 'mouse', button: 0, pointerId: 1, clientX: x, clientY: y, target, currentTarget: { setPointerCapture() {} }, preventDefault() {} });
    const touch = (y, x = 10) => ({ touches: [{ clientX: x, clientY: y }], changedTouches: [{ clientX: x, clientY: y }], target, currentTarget: {}, cancelable: true, prevented: false, preventDefault() { this.prevented = true; } });
    return { api: context.api, player, moves, fire, advance, mouse, touch, listeners };
}

// A short click must retain playback semantics and never reorder.
{
    const f = fixture(); f.api.beginMouseGesture(0, f.mouse(45)); f.fire('pointerup', f.mouse(45));
    assert.equal(f.api.dragging, false); assert.deepEqual(f.moves, []);
    const click = { preventDefault() { throw Error('ordinary click suppressed'); }, stopPropagation() {} }; f.api.captureRowClick(click);
}
// Row dragging retains the existing drop calculation and suppresses the following click.
{
    const f = fixture(); f.api.beginMouseGesture(0, f.mouse(45)); f.fire('pointermove', f.mouse(220));
    assert.equal(f.api.dragging, true); f.fire('pointerup', f.mouse(220));
    assert.deepEqual(f.moves, [[0, 2]]);
    let suppressed = false; f.api.captureRowClick({ preventDefault() { suppressed = true; }, stopPropagation() {} }); assert.equal(suppressed, true);
}
// Vertical swiping before the hold threshold stays native scrolling.
{
    const f = fixture(); f.api.beginTouchGesture(0, f.touch(45)); const event = f.touch(80);
    f.fire('touchmove', event); f.advance(400);
    assert.equal(event.prevented, false); assert.equal(f.api.dragging, false); assert.deepEqual(f.moves, []);
}
// A tap ends before the hold threshold without pickup.
{
    const f = fixture(); f.api.beginTouchGesture(0, f.touch(45)); f.fire('touchend', f.touch(45)); f.advance(400);
    assert.equal(f.api.dragging, false); assert.deepEqual(f.moves, []);
}
// A held touch picks up the row; subsequent movement prevents scrolling and reorders.
{
    const f = fixture(); f.api.beginTouchGesture(0, f.touch(45)); f.advance(200);
    assert.equal(f.api.dragging, false); f.advance(10);
    assert.equal(f.api.dragging, true); const event = f.touch(220); f.fire('touchmove', event);
    assert.equal(event.prevented, true); f.fire('touchend', f.touch(220));
    assert.deepEqual(f.moves, [[0, 2]]); assert.equal(f.api.dragging, false);
    assert.equal([...f.listeners.values()].reduce((n, set) => n + set.size, 0), 0);
}
// Cancellation and remove-button presses cannot commit a reorder.
{
    const f = fixture(); f.api.beginTouchGesture(0, f.touch(45)); f.advance(210); f.fire('touchcancel', f.touch(80));
    assert.deepEqual(f.moves, []); assert.equal(f.api.dragging, false);
    const event = f.mouse(45); event.target = { closest: () => ({}) }; f.api.beginMouseGesture(0, event); f.fire('pointermove', f.mouse(220));
    assert.equal(f.api.dragging, false);
}
console.log('Queue row drag, touch hold, scroll, cancellation and click suppression passed.');
