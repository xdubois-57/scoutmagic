// Isolated JavaScript unit test — jsdom-simulated DOM only. jsdom has no
// layout, so getBoundingClientRect() returns zeroes: each test stubs the
// rectangles of the items it drags over, which is the only input the
// « before or after » decision actually reads. Exercises the REAL
// implementation in public/assets/js/sortable.js (imported below, never
// reimplemented here).
//
// This is the toolbox that replaced three hand-written copies of the same
// dragstart / dragover / dragend triple (list-editor.js, gallery.js,
// finance-categories.js).
import { beforeEach, describe, expect, it, vi } from 'vitest';

const LIST = `
    <ul id="list">
        <li class="item" data-id="1" draggable="true"><span>un</span></li>
        <li class="item" data-id="2" draggable="true"><span>deux</span></li>
        <li class="item" data-id="3" draggable="true"><span>trois</span></li>
    </ul>`;

/** Places the items on an imaginary vertical strip, 100px tall each. */
function layOutVertically() {
    [...document.querySelectorAll('.item')].forEach((item, i) => {
        item.getBoundingClientRect = () => ({ top: i * 100, left: 0, height: 100, width: 100 });
    });
}

function layOutHorizontally() {
    [...document.querySelectorAll('.item')].forEach((item, i) => {
        item.getBoundingClientRect = () => ({ top: 0, left: i * 100, height: 100, width: 100 });
    });
}

function dragOver(item, { clientX = 0, clientY = 0 } = {}) {
    const event = new Event('dragover', { bubbles: true, cancelable: true });
    Object.defineProperties(event, {
        clientX: { value: clientX },
        clientY: { value: clientY },
    });
    item.dispatchEvent(event);
    return event;
}

describe('sortable.js', () => {
    let onReorder;

    beforeEach(async () => {
        vi.resetModules();
        document.body.innerHTML = LIST;
        onReorder = vi.fn();
        await import('../../public/assets/js/sortable.js');
    });

    const list = () => document.getElementById('list');
    const item = (id) => document.querySelector(`.item[data-id="${id}"]`);
    const order = () => [...document.querySelectorAll('.item')].map((el) => el.dataset.id);
    const start = (id) => item(id).dispatchEvent(new Event('dragstart', { bubbles: true }));
    const end = (id) => item(id).dispatchEvent(new Event('dragend', { bubbles: true }));

    function bind(extra) {
        window.ScoutMagicSortable.bind(list(), Object.assign(
            { itemSelector: '.item', onReorder },
            extra || {}
        ));
    }

    describe('guards', () => {
        it('does nothing, and throws nothing, without a container', () => {
            expect(() => window.ScoutMagicSortable.bind(null, { itemSelector: '.item' })).not.toThrow();
        });

        it('does nothing without an item selector', () => {
            expect(() => window.ScoutMagicSortable.bind(list(), {})).not.toThrow();
        });

        it('binds a container once, however many times bind() is called', () => {
            bind();
            bind();
            start('1');
            end('1');

            expect(onReorder).toHaveBeenCalledTimes(1);
        });
    });

    describe('the drag', () => {
        it('marks the dragged item and clears the mark when the gesture ends', () => {
            bind();
            start('2');
            expect(item('2').classList.contains('opacity-50')).toBe(true);

            end('2');
            expect(item('2').classList.contains('opacity-50')).toBe(false);
        });

        it('takes the caller\'s own dragging class', () => {
            bind({ draggingClass: 'list-editor-item--dragging' });
            start('2');

            expect(item('2').classList.contains('list-editor-item--dragging')).toBe(true);
        });

        it('cancels dragover — without that the browser refuses the drop', () => {
            bind();
            layOutVertically();
            start('1');

            expect(dragOver(item('2'), { clientY: 150 }).defaultPrevented).toBe(true);
        });
    });

    describe('the vertical decision', () => {
        it('drops the item BEFORE a neighbour whose top half is under the pointer', () => {
            bind();
            layOutVertically();
            start('3');
            dragOver(item('1'), { clientY: 20 });

            expect(order()).toEqual(['3', '1', '2']);
        });

        it('drops it AFTER a neighbour whose bottom half is under the pointer', () => {
            bind();
            layOutVertically();
            start('1');
            dragOver(item('3'), { clientY: 280 });

            expect(order()).toEqual(['2', '3', '1']);
        });

        it('leaves the list alone when the pointer is over the dragged item itself', () => {
            bind();
            layOutVertically();
            start('2');
            dragOver(item('2'), { clientY: 150 });

            expect(order()).toEqual(['1', '2', '3']);
        });

        it('leaves it alone when nothing is being dragged', () => {
            bind();
            layOutVertically();
            dragOver(item('1'), { clientY: 20 });

            expect(order()).toEqual(['1', '2', '3']);
        });
    });

    describe('the horizontal decision — a grid, not a list', () => {
        it('reads the horizontal midpoint under axis:x', () => {
            bind({ axis: 'x' });
            layOutHorizontally();
            start('1');
            dragOver(item('3'), { clientX: 280, clientY: 0 });

            expect(order()).toEqual(['2', '3', '1']);
        });

        it('ignores the vertical position entirely under axis:x', () => {
            bind({ axis: 'x' });
            layOutHorizontally();
            start('3');
            dragOver(item('1'), { clientX: 20, clientY: 9999 });

            expect(order()).toEqual(['3', '1', '2']);
        });
    });

    describe('saving', () => {
        it('saves on dragend — including a release outside any sibling', () => {
            bind();
            layOutVertically();
            start('3');
            dragOver(item('1'), { clientY: 20 });
            expect(onReorder).not.toHaveBeenCalled();

            end('3');
            expect(onReorder).toHaveBeenCalledTimes(1);
            expect(order()).toEqual(['3', '1', '2']);
        });

        it('does not save a dragend that no dragstart began', () => {
            bind();
            end('1');

            expect(onReorder).not.toHaveBeenCalled();
        });

        it('is optional — a list may reorder without persisting anything', () => {
            window.ScoutMagicSortable.bind(list(), { itemSelector: '.item' });
            layOutVertically();
            start('3');
            dragOver(item('1'), { clientY: 20 });

            expect(() => end('3')).not.toThrow();
            expect(order()).toEqual(['3', '1', '2']);
        });
    });

    describe('delegation', () => {
        it('handles an item added after bind() — nothing to re-wire', () => {
            bind();
            list().insertAdjacentHTML('beforeend',
                '<li class="item" data-id="4" draggable="true"></li>');
            layOutVertically();
            start('4');
            dragOver(item('1'), { clientY: 20 });

            expect(order()).toEqual(['4', '1', '2', '3']);
        });

        it('starts a drag from a child of the item, not just the item itself', () => {
            bind();
            item('2').querySelector('span')
                .dispatchEvent(new Event('dragstart', { bubbles: true }));

            expect(item('2').classList.contains('opacity-50')).toBe(true);
        });
    });
});
