// Isolated JavaScript unit test — jsdom DOM only, fetch never reached:
// window.ScoutMagicApi.postJson is stubbed. Exercises the REAL
// public/assets/js/rental-block-days.js (imported below, never
// reimplemented): blocking dates on the managed calendar (#708, IT-07).
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    daysToChange,
    gestureDays,
    lastDayOf,
    modeFor,
    nextDay,
    summaryFor,
    wireBlockCalendar,
} from '../../public/assets/js/rental-block-days.js';

describe('gestureDays', () => {
    it('covers every day between the first and the current one, either way round', () => {
        expect(gestureDays('2027-07-10', '2027-07-12', '2027-07', '2027-07-01'))
            .toEqual(['2027-07-10', '2027-07-11', '2027-07-12']);
        expect(gestureDays('2027-07-12', '2027-07-10', '2027-07', '2027-07-01'))
            .toEqual(['2027-07-10', '2027-07-11', '2027-07-12']);
    });

    it('stops at the edge of the month', () => {
        expect(gestureDays('2027-07-30', '2027-08-02', '2027-07', '2027-07-01'))
            .toEqual(['2027-07-30', '2027-07-31']);
    });

    it('leaves past days out', () => {
        expect(gestureDays('2027-07-08', '2027-07-11', '2027-07', '2027-07-10'))
            .toEqual(['2027-07-10', '2027-07-11']);
    });
});

describe('modeFor and daysToChange', () => {
    it('lets the first day decide the mode', () => {
        expect(modeFor(false)).toBe('block');
        expect(modeFor(true)).toBe('release');
    });

    it('only changes the days the mode applies to', () => {
        const blocked = new Set(['2027-07-11']);
        const isBlocked = (day) => blocked.has(day);

        expect(daysToChange(['2027-07-10', '2027-07-11'], 'block', isBlocked)).toEqual(['2027-07-10']);
        expect(daysToChange(['2027-07-10', '2027-07-11'], 'release', isBlocked)).toEqual(['2027-07-11']);
    });
});

describe('dates and words', () => {
    it('walks across months and knows their last day', () => {
        expect(nextDay('2027-07-31')).toBe('2027-08-01');
        expect(lastDayOf('2028-02')).toBe('2028-02-29');
    });

    it('says what a gesture did', () => {
        expect(summaryFor(1, 'block')).toBe('1 jour bloqué');
        expect(summaryFor(5, 'block')).toBe('5 jours bloqués');
        expect(summaryFor(2, 'release')).toBe('2 jours remis en location');
        // A gesture the server found nothing to do for is not « 1 jour ».
        expect(summaryFor(0, 'block')).toBe('Rien n\'a changé : ces dates étaient déjà dans cet état.');
    });
});

describe('wireBlockCalendar', () => {
    /** @type {ReturnType<typeof vi.fn>} */
    let postJson;
    /** @type {ReturnType<typeof vi.fn>} */
    let toast;

    function render(blocked = []) {
        const days = ['2027-07-09', '2027-07-10', '2027-07-11', '2027-07-12', '2027-08-01'];
        document.body.innerHTML = `
            <div id="rental-block-calendar" data-month="2027-07" data-today="2027-07-10"
                 data-url="/mes-locations/local/calendrier/jours">
                ${days.map((d) => `<button type="button" data-date="${d}"${blocked.includes(d) ? ' data-unit-block="1"' : ''}>${d}</button>`).join('')}
            </div>
            <div id="rental-block-list"></div>`;
        const root = /** @type {HTMLElement} */ (document.getElementById('rental-block-calendar'));
        wireBlockCalendar(root);

        return root;
    }

    function cell(day) {
        return /** @type {HTMLElement} */ (document.querySelector(`[data-date="${day}"]`));
    }

    function pointer(type, target, extra = {}) {
        const event = new Event(type, { bubbles: true, cancelable: true });
        Object.assign(event, { button: 0, pointerId: 1, pointerType: 'mouse', clientX: 0, clientY: 0 }, extra);
        target.dispatchEvent(event);
    }

    beforeEach(() => {
        postJson = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            data: { success: true, changed: { '2027-07-11': null }, list: '<p>liste</p>' },
        });
        toast = vi.fn();
        window.ScoutMagicApi = { postJson };
        window.ScoutMagicToast = { show: toast };
    });

    it('blocks one day on a click, and the grid shows it at once', async () => {
        render();
        cell('2027-07-11').click();

        expect(cell('2027-07-11').dataset.unitBlock).toBe('1');
        expect(postJson).toHaveBeenCalledWith('/mes-locations/local/calendrier/jours', { mode: 'block', days: ['2027-07-11'] });
        await vi.waitFor(() => expect(toast).toHaveBeenCalled());
        expect(toast.mock.calls[0][0]).toBe('1 jour bloqué');
        expect(toast.mock.calls[0][1].action.label).toBe('Annuler');
        expect(document.getElementById('rental-block-list').innerHTML).toBe('<p>liste</p>');
    });

    it('releases a day the unit already blocks', () => {
        render(['2027-07-11']);
        cell('2027-07-11').click();

        expect(cell('2027-07-11').dataset.unitBlock).toBeUndefined();
        expect(postJson).toHaveBeenCalledWith(expect.any(String), { mode: 'release', days: ['2027-07-11'] });
    });

    it('ignores past days and the next month', () => {
        render();
        cell('2027-07-09').click();
        cell('2027-08-01').click();

        expect(postJson).not.toHaveBeenCalled();
    });

    it('puts the grid back and says why when the server refuses', async () => {
        postJson.mockResolvedValue({ ok: false, status: 422, data: { success: false, error: 'Refusé.' } });
        render();
        cell('2027-07-11').click();

        await vi.waitFor(() => expect(toast).toHaveBeenCalledWith('Refusé.', { variant: 'error' }));
        expect(cell('2027-07-11').dataset.unitBlock).toBeUndefined();
    });

    it('puts the grid back when the request never reaches the server', async () => {
        postJson.mockRejectedValue(new TypeError('Failed to fetch'));
        render();
        cell('2027-07-11').click();

        await vi.waitFor(() => expect(toast).toHaveBeenCalledWith(
            'Erreur réseau : les dates n\'ont pas été enregistrées.',
            { variant: 'error' }
        ));
        expect(cell('2027-07-11').dataset.unitBlock).toBeUndefined();
    });

    it('treats a mouse drag as one gesture, sent once at release', () => {
        const root = render();
        document.elementFromPoint = vi.fn().mockReturnValue(cell('2027-07-12'));

        pointer('pointerdown', cell('2027-07-10'));
        pointer('pointermove', root, { buttons: 1 });
        pointer('pointerup', root);
        // The click the browser fires after the drag must not toggle again.
        cell('2027-07-12').click();

        expect(postJson).toHaveBeenCalledTimes(1);
        expect(postJson).toHaveBeenCalledWith(expect.any(String), {
            mode: 'block',
            days: ['2027-07-10', '2027-07-11', '2027-07-12'],
        });
    });

    it('ends a mouse drag released past the edge of the calendar', () => {
        const root = render();
        document.elementFromPoint = vi.fn().mockReturnValue(cell('2027-07-12'));

        pointer('pointerdown', cell('2027-07-10'));
        pointer('pointermove', root, { buttons: 1 });
        pointer('pointerup', document.body);

        expect(postJson).toHaveBeenCalledWith(expect.any(String), {
            mode: 'block',
            days: ['2027-07-10', '2027-07-11', '2027-07-12'],
        });
        expect(root.classList.contains('is-selecting')).toBe(false);
        // Nothing to swallow: the next click on a day is its own.
        cell('2027-07-11').click();
        expect(postJson).toHaveBeenCalledTimes(2);
    });

    it('drops a mouse gesture whose button is no longer held', () => {
        const root = render();
        document.elementFromPoint = vi.fn().mockReturnValue(cell('2027-07-12'));

        pointer('pointerdown', cell('2027-07-10'));
        pointer('pointermove', root, { buttons: 0 });

        expect(root.classList.contains('is-selecting')).toBe(false);
        expect(root.querySelector('.is-gesture')).toBeNull();
    });

    it('swallows no tap after a finger drag, which raises no click', () => {
        vi.useFakeTimers();
        const root = render();
        document.elementFromPoint = vi.fn().mockReturnValue(cell('2027-07-12'));

        pointer('pointerdown', cell('2027-07-10'), { pointerType: 'touch' });
        vi.advanceTimersByTime(400);
        pointer('pointermove', root, { pointerType: 'touch', clientY: 40 });
        pointer('pointerup', root, { pointerType: 'touch' });
        vi.useRealTimers();
        cell('2027-07-11').click();

        expect(postJson).toHaveBeenCalledTimes(2);
        expect(postJson).toHaveBeenLastCalledWith(expect.any(String), { mode: 'release', days: ['2027-07-11'] });
    });

    it('offers no undo when the server changed nothing', async () => {
        postJson.mockResolvedValueOnce({ ok: true, status: 200, data: { success: true, changed: {}, list: '' } });
        render();
        cell('2027-07-11').click();

        await vi.waitFor(() => expect(toast).toHaveBeenCalled());
        expect(toast.mock.calls[0][1]).toEqual({ variant: 'info' });
    });

    it('lets a quick finger swipe scroll instead of selecting', () => {
        const root = render();
        document.elementFromPoint = vi.fn().mockReturnValue(cell('2027-07-12'));

        pointer('pointerdown', cell('2027-07-10'), { pointerType: 'touch', clientY: 0 });
        pointer('pointermove', root, { pointerType: 'touch', clientY: 40 });
        pointer('pointerup', root, { pointerType: 'touch' });

        expect(postJson).not.toHaveBeenCalled();
    });

    it('starts a selection after a long press of the finger', () => {
        vi.useFakeTimers();
        const root = render();
        document.elementFromPoint = vi.fn().mockReturnValue(cell('2027-07-12'));

        pointer('pointerdown', cell('2027-07-10'), { pointerType: 'touch' });
        vi.advanceTimersByTime(400);
        expect(root.classList.contains('is-selecting')).toBe(true);
        pointer('pointermove', root, { pointerType: 'touch', clientY: 40 });
        pointer('pointerup', root, { pointerType: 'touch' });
        vi.useRealTimers();

        expect(postJson).toHaveBeenCalledWith(expect.any(String), {
            mode: 'block',
            days: ['2027-07-10', '2027-07-11', '2027-07-12'],
        });
    });

    it('undoes a gesture with the reasons the server reported', async () => {
        postJson.mockResolvedValueOnce({
            ok: true,
            status: 200,
            data: { success: true, changed: { '2027-07-11': 'Camp' }, list: '' },
        });
        render(['2027-07-11']);
        cell('2027-07-11').click();
        await vi.waitFor(() => expect(toast).toHaveBeenCalled());

        toast.mock.calls[0][1].action.onClick();

        expect(postJson).toHaveBeenLastCalledWith(expect.any(String), {
            mode: 'block',
            days: ['2027-07-11'],
            reasons: { '2027-07-11': 'Camp' },
        });
    });
});
