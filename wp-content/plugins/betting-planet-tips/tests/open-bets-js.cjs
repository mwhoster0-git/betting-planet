// Dependency-free interaction tests with DOM stubs; run with Node.js.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
function element() {
  return { style: {}, dataset: {}, attributes: {}, events: {}, offsetHeight: 500,
    classList: { add() {}, remove() {} },
    setAttribute(key, value) { this.attributes[key] = value; },
    addEventListener(key, callback) { this.events[key] = callback; } };
}
function root(count) {
  const node = element(), deck = element(), counter = {}, controls = {}, prev = element(), next = element();
  const cards = Array.from({ length: count }, element);
  deck.clientWidth = 375;
  deck.querySelectorAll = () => cards;
  deck.setPointerCapture = () => {};
  deck.hasPointerCapture = () => false;
  node.querySelector = selector => ({ '.bp-hand-deck': deck, '.bp-hand-controls': controls, '[data-bpt-current]': counter, '[data-bpt-prev]': prev, '[data-bpt-next]': next }[selector]);
  return Object.assign(node, { deck, counter, controls, prev, next, cards });
}
const first = root(5), second = root(2), single = root(1);
const context = {
  document: { readyState: 'complete', documentElement: {}, querySelectorAll: () => [first, second, single] },
  window: { matchMedia: () => ({ matches: true }), setTimeout: callback => callback(), addEventListener() {} },
  MutationObserver: class { observe() {} }
};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../assets/open-bets.js'), 'utf8'), context);
assert.equal(first.counter.textContent, '1');
first.next.events.click();
assert.equal(first.counter.textContent, '2');
assert.equal(second.counter.textContent, '1');
first.prev.events.click();
assert.equal(first.counter.textContent, '1');
first.prev.events.click();
assert.equal(first.counter.textContent, '5');
first.deck.events.keydown({ key: 'ArrowRight', preventDefault() {} });
assert.equal(first.counter.textContent, '1');
function swipe(type, dx, dy, cancel = false) {
  const event = { pointerId: 1, isPrimary: true, button: 0, pointerType: type, clientX: 200, clientY: 100 };
  first.deck.events.pointerdown(event);
  first.deck.events.pointermove({ ...event, clientX: 200 + dx, clientY: 100 + dy });
  first.deck.events[cancel ? 'pointercancel' : 'pointerup'](event);
}
swipe('touch', -100, 5);
assert.equal(first.counter.textContent, '2');
swipe('touch', 100, 5);
assert.equal(first.counter.textContent, '1');
swipe('mouse', -100, 0);
assert.equal(first.counter.textContent, '2');
swipe('touch', 10, 0);
assert.equal(first.counter.textContent, '2');
swipe('touch', -70, 200);
assert.equal(first.counter.textContent, '2');
swipe('touch', -100, 0, true);
assert.equal(first.counter.textContent, '2');
assert.equal(first.cards[1].attributes['aria-hidden'], 'false');
assert.equal(first.cards[0].attributes['aria-hidden'], 'true');
assert.equal(first.deck.style.height, '540px');
assert.equal(single.controls.hidden, true);
single.next.events.click();
assert.equal(single.counter.textContent, '1');
console.log('PASS: 17 deck interaction checks (touch, mouse, keyboard, cancellation, wrapping, isolation, single-card and sizing).');
