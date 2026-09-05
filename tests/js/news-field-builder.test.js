// Isolated JavaScript unit test — jsdom only. Exercises the REAL
// implementation in public/assets/js/news-form-builder.js.
//
// **Why a third file for one source file.** That module has two halves.
// The sanitizer half is reachable through its
// `ScoutMagicNewsFormBuilderInternals` seam and is covered exhaustively in
// news-form-builder.test.js — 48 cases, written as an allowlist
// specification because a regression there is a stored-XSS path. The
// builder half is not reachable that way at all: `addField`,
// `removeField`, `moveField` and the serialiser are closures created only
// when the module finds `#news-fields-list` in the DOM AT IMPORT TIME.
// A test that imports first and builds the DOM afterwards can never reach
// them, so the DOM is built at module scope below, before the dynamic
// imports — the same technique news-payment-total.test.js already uses,
// and for the same reason.
//
// **Why it was worth writing.** Measured, that source file sat at 31 %:
// 831 uncovered lines, a third of all uncovered JavaScript in the
// repository. The gap was not "the file is untested" but "the tested half
// is the security half" — everything a chef d'unité actually does on the
// page, composing the form families will fill in, ran in no test at all.
//
// What is pinned here is that composition, in the terms of the usability
// review the code cites: a first block that cannot be moved or deleted,
// fields mandatory by default, settings that appear when they start to
// mean something, and a serialisation the server can read.
import { describe, expect, it, beforeEach } from 'vitest';

document.body.innerHTML = `
    <form id="news-editor-form">
        <input type="hidden" id="fields_json_input" name="fields_json" value="">
        <input type="text" name="title" value="Un titre">
        <div id="news-fields-list"></div>
        <button type="button" id="news-add-field-btn">Ajouter</button>
        <div id="news-field-type-picker" class="d-none">
            <div id="news-field-type-grid"></div>
            <button type="button" id="news-field-picker-close">Fermer</button>
        </div>
        <div id="news-form-settings" class="d-none"></div>
        <h2 id="news-form-box-heading">Article</h2>
        <div id="news-payment-settings" class="d-none"></div>
    </form>
    <script type="application/json" id="news-editor-data">
        {"fields": [{"id": 1, "field_type": "text", "label": "Bloc de texte", "is_required": false,
                     "options_source": null, "options_manual": null, "capacity_max": null,
                     "price_per_unit": null, "confirmation_text": "Bonjour"}]}
    </script>
`;

await import('../../public/assets/js/api.js');
await import('../../public/assets/js/toast.js');
await import('../../public/assets/js/news-form-builder.js');

const list = () => document.getElementById('news-fields-list');
const rows = () => Array.from(list().children);

/** The label a row shows, without the required marker or the price badge. */
const labelOf = (row) => row.querySelector('.flex-grow-1').firstChild.textContent;

/** Adds a field of the given type the way a chef does: through the picker. */
function addField(type) {
    const buttons = Array.from(document.querySelectorAll('#news-field-type-grid button'));
    const button = buttons.find((b) => b.textContent.trim() === type);
    if (!button) {
        throw new Error(`No picker button labelled "${type}"`);
    }
    button.click();
}

/** Back to the one pinned block the page opens with. */
function resetToOneBlock() {
    while (rows().length > 1) {
        rows()[rows().length - 1].querySelector('.btn-outline-danger').click();
    }
}

beforeEach(() => {
    resetToOneBlock();
});

describe('the type picker', () => {
    it('offers every field type the module declares', () => {
        const labels = Array.from(document.querySelectorAll('#news-field-type-grid button'))
            .map((b) => b.textContent.trim());

        expect(labels).toEqual([
            'Texte court', 'Texte long', 'Nombre', 'Date', 'Téléphone', 'Email',
            'Liste déroulante', 'Choix unique', 'Choix multiple', 'Interrupteur',
            'Bloc de confirmation', 'Bloc de texte',
        ]);
    });

    it('opens on the add button and closes again', () => {
        const picker = document.getElementById('news-field-type-picker');

        document.getElementById('news-add-field-btn').click();
        expect(picker.classList.contains('d-none')).toBe(false);

        document.getElementById('news-field-picker-close').click();
        expect(picker.classList.contains('d-none')).toBe(true);
    });

    it('closes once a field has been picked, so the list is what you see next', () => {
        document.getElementById('news-add-field-btn').click();

        addField('Email');

        expect(document.getElementById('news-field-type-picker').classList.contains('d-none')).toBe(true);
    });
});

describe('composing the form', () => {
    it('adds the picked field to the end of the list', () => {
        addField('Email');
        addField('Date');

        expect(rows().map(labelOf)).toEqual(['Bloc de texte', 'Email', 'Date']);
    });

    // Usability review: the author opts OUT per field rather than opting in
    // every time, so a new field is marked with the required asterisk.
    it('makes a new field mandatory by default', () => {
        addField('Email');

        expect(rows()[1].querySelector('.text-danger').textContent).toBe(' *');
    });

    it('does not mark a text block as mandatory, because there is nothing to fill in', () => {
        addField('Bloc de confirmation');

        expect(rows()[1].querySelector('.text-danger')).toBeNull();
    });

    it('deletes the field whose bin was clicked, and only that one', () => {
        addField('Email');
        addField('Date');
        addField('Nombre');

        rows()[2].querySelector('.btn-outline-danger').click();

        expect(rows().map(labelOf)).toEqual(['Bloc de texte', 'Email', 'Nombre']);
    });
});

// « There must always be a bloc de texte on top; it cannot be deleted or
// moved » — the article's own text, which is why the field is pinned.
describe('the pinned first block', () => {
    it('shows no delete button at all', () => {
        addField('Email');

        expect(rows()[0].querySelector('.btn-outline-danger')).toBeNull();
    });

    it('shows no move controls either, and says why it is where it is', () => {
        addField('Email');

        expect(rows()[0].querySelector('.news-move-up')).toBeNull();
        expect(rows()[0].querySelector('[aria-label="Toujours en premier"]')).not.toBeNull();
    });

    it('cannot be pushed down by the field just below it', () => {
        addField('Email');

        expect(rows()[1].querySelector('.news-move-up').disabled).toBe(true);
    });

    it('is not draggable, where the others are', () => {
        addField('Email');

        expect(rows()[0].draggable).toBe(false);
        expect(rows()[1].draggable).toBe(true);
    });
});

describe('reordering', () => {
    it('moves a field up past its neighbour', () => {
        addField('Email');
        addField('Date');

        rows()[2].querySelector('.news-move-up').click();

        expect(rows().map(labelOf)).toEqual(['Bloc de texte', 'Date', 'Email']);
    });

    it('moves a field down past its neighbour', () => {
        addField('Email');
        addField('Date');

        rows()[1].querySelector('.news-move-down').click();

        expect(rows().map(labelOf)).toEqual(['Bloc de texte', 'Date', 'Email']);
    });

    it('cannot move the last field further down', () => {
        addField('Email');
        addField('Date');

        expect(rows()[2].querySelector('.news-move-down').disabled).toBe(true);
    });
});

// The two boxes below are hidden until they start meaning something —
// a page that opens with an empty "Paiement" section invites a chef to
// fill in a price for a form that collects nothing.
describe('the settings that appear when they start to matter', () => {
    const settings = () => document.getElementById('news-form-settings');
    const heading = () => document.getElementById('news-form-box-heading');

    it('calls the box an Article while it holds only text blocks', () => {
        expect(settings().classList.contains('d-none')).toBe(true);
        expect(heading().textContent).toBe('Article');
    });

    it('calls it a Formulaire as soon as one field asks the reader something', () => {
        addField('Email');

        expect(settings().classList.contains('d-none')).toBe(false);
        expect(heading().textContent).toBe('Formulaire');
    });

    it('goes back to an Article when that field is removed again', () => {
        addField('Email');
        rows()[1].querySelector('.btn-outline-danger').click();

        expect(heading().textContent).toBe('Article');
    });

    it('keeps the payment box hidden for a number field with no price', () => {
        addField('Nombre');

        expect(document.getElementById('news-payment-settings').classList.contains('d-none')).toBe(true);
    });
});

describe('what the server receives', () => {
    it('serialises the fields in the order shown', () => {
        addField('Email');
        addField('Date');

        document.getElementById('news-editor-form').dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true })
        );

        const sent = JSON.parse(document.getElementById('fields_json_input').value);
        expect(sent.map((f) => f.field_type)).toEqual(['text', 'email', 'date']);
    });

    it('sends the whole shape of a field, and nothing the browser invented', () => {
        addField('Email');

        document.getElementById('news-editor-form').dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true })
        );

        const sent = JSON.parse(document.getElementById('fields_json_input').value);
        expect(Object.keys(sent[1]).sort()).toEqual([
            'capacity_max', 'confirmation_text', 'field_type', 'id', 'is_required',
            'label', 'options_manual', 'options_source', 'price_per_unit',
        ]);
        // The list key is how the browser tracks a row across a re-render;
        // it means nothing to the server and must not travel.
        expect(sent[1]).not.toHaveProperty('_key');
    });

    it('keeps the id of a field that already exists and sends none for a new one', () => {
        addField('Email');

        document.getElementById('news-editor-form').dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true })
        );

        const sent = JSON.parse(document.getElementById('fields_json_input').value);
        expect(sent[0].id).toBe(1);
        expect(sent[1].id).toBeNull();
    });

    it('gives a choice field its manual option source, and a plain one none', () => {
        addField('Liste déroulante');
        addField('Email');

        document.getElementById('news-editor-form').dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true })
        );

        const sent = JSON.parse(document.getElementById('fields_json_input').value);
        expect(sent[1].options_source).toBe('manual');
        expect(sent[2].options_source).toBeNull();
    });
});

// A label is admin-entered free text that is deliberately NOT
// HTML-sanitized server-side, because it is meant to stay plain text. Two
// separate code paths put it on screen — the live update while typing, and
// the full re-render that happens on the next change to the list — and
// BOTH have to treat it as text. A test that only types would miss the
// second, which is the one a chef sees for the rest of the session.
describe('a label is text, never markup', () => {
    const dangerous = '<img src=x onerror=alert(1)>';

    /** Types into the label input of the field that is currently expanded. */
    function typeLabel(text) {
        const input = list().querySelector('.news-field-label-input');
        expect(input, 'a newly added field opens with its label input').not.toBeNull();
        input.value = text;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    it('shows the characters that were typed, while they are being typed', () => {
        addField('Email');

        typeLabel(dangerous);

        expect(list().querySelector('img')).toBeNull();
        expect(list().textContent).toContain(dangerous);
    });

    it('still shows them as characters after the list is rebuilt', () => {
        addField('Email');
        typeLabel(dangerous);

        // Anything that changes the list rebuilds every row from scratch —
        // this is the path a chef sees for the rest of their session.
        addField('Date');

        expect(list().querySelector('img')).toBeNull();
        expect(labelOf(rows()[1])).toBe(dangerous);
    });

    it('falls back to a placeholder rather than an empty row when the label is cleared', () => {
        addField('Email');

        typeLabel('');

        expect(list().textContent).toContain('Sans libellé');
    });
});
