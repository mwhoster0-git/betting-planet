(() => {
  'use strict';
  function init(root) {
    if (root.dataset.bptReady) return;
    const deck = root.querySelector('.bp-hand-deck');
    if (!deck) return;
    root.dataset.bptReady = '1';
    const cards = Array.from(deck.querySelectorAll('.bp-hand-card'));
    const controls = root.querySelector('.bp-hand-controls');
    const counter = root.querySelector('[data-bpt-current]');
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    let current = 0, busy = false, pointer = null;
    const positions = ['translate3d(0,0,0) rotate(0deg)', 'translate3d(12px,12px,0) rotate(3deg)', 'translate3d(-12px,17px,0) rotate(-3deg)', 'translate3d(4px,23px,0) rotate(1.5deg)'];
    function size() {
      deck.style.height = `${Math.max(...cards.map(card => card.offsetHeight)) + 40}px`;
    }
    function paint() {
      cards.forEach((card, index) => {
        const depth = (index - current + cards.length) % cards.length;
        card.style.zIndex = String(cards.length - depth);
        card.style.transform = positions[Math.min(depth, 3)];
        card.style.opacity = depth < 4 ? '1' : '0';
        card.style.visibility = depth < 4 ? 'visible' : 'hidden';
        card.style.pointerEvents = depth === 0 ? 'auto' : 'none';
        card.setAttribute('aria-hidden', depth === 0 ? 'false' : 'true');
      });
      counter.textContent = String(current + 1);
      size();
    }
    function move(direction) {
      if (busy || cards.length < 2) return;
      busy = true;
      const active = cards[current];
      active.style.transform = `translate3d(${direction > 0 ? '-110%' : '110%'},25px,0) rotate(${direction > 0 ? -15 : 15}deg)`;
      active.style.opacity = '0';
      window.setTimeout(() => {
        current = (current + direction + cards.length) % cards.length;
        paint();
        busy = false;
      }, reduced.matches ? 0 : 280);
    }
    root.querySelector('[data-bpt-prev]').addEventListener('click', () => move(-1));
    root.querySelector('[data-bpt-next]').addEventListener('click', () => move(1));
    deck.addEventListener('keydown', event => {
      if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        move(event.key === 'ArrowRight' ? 1 : -1);
      }
    });
    deck.addEventListener('pointerdown', event => {
      if (busy || cards.length < 2 || !event.isPrimary || event.button !== 0) return;
      pointer = { id: event.pointerId, x: event.clientX, y: event.clientY, dx: 0, dy: 0 };
      deck.setPointerCapture(event.pointerId);
    });
    deck.addEventListener('pointermove', event => {
      if (!pointer || pointer.id !== event.pointerId) return;
      pointer.dx = event.clientX - pointer.x;
      pointer.dy = event.clientY - pointer.y;
      if (Math.abs(pointer.dx) > Math.abs(pointer.dy) && Math.abs(pointer.dx) > 6) {
        root.classList.add('is-dragging');
        cards[current].style.transform = `translate3d(${pointer.dx}px,0,0) rotate(${pointer.dx / 24}deg)`;
      }
    });
    function release(event, cancelled = false) {
      if (!pointer || pointer.id !== event.pointerId) return;
      const drag = pointer;
      pointer = null;
      root.classList.remove('is-dragging');
      if (deck.hasPointerCapture(event.pointerId)) deck.releasePointerCapture(event.pointerId);
      if (!cancelled && Math.abs(drag.dx) > Math.min(65, deck.clientWidth * 0.18) && Math.abs(drag.dx) > Math.abs(drag.dy) * 1.2) {
        move(drag.dx < 0 ? 1 : -1);
      } else {
        paint();
      }
    }
    deck.addEventListener('pointerup', event => release(event));
    deck.addEventListener('pointercancel', event => release(event, true));
    deck.addEventListener('lostpointercapture', event => release(event, true));
    root.classList.add('is-ready');
    controls.hidden = cards.length < 2;
    paint();
    if ('ResizeObserver' in window) {
      const observer = new ResizeObserver(size);
      cards.forEach(card => observer.observe(card));
    } else {
      window.addEventListener('resize', size);
    }
  }
  const scan = () => document.querySelectorAll('[data-bpt-open-bets]').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
  else scan();
  // Supports multiple instances and cards inserted by page-builder previews.
  new MutationObserver(records => {
    if (records.some(record => record.addedNodes.length)) scan();
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
