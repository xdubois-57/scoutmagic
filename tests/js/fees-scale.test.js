// Exercises the actual browser script; no server or price-policy double.
import { beforeEach, expect, it, vi } from 'vitest';

const markup = `<form id="fees-scale-form">
<input name="scale_year" value="2026-2027">
<input name="guest_from" value="01/06"><input name="guest_until" value="31/08">
<input name="amount_guest" value="18,25"><p id="fees-guest-rule"></p></form>`;
async function load(html = markup) {
    document.body.innerHTML = html;
    vi.resetModules();
    await import('../../public/assets/js/fees-scale.js');
}
beforeEach(() => { document.body.innerHTML = ''; });
it('uses the ending calendar year and the configured amount on initial display', async () => {
    await load();
    const text = document.getElementById('fees-guest-rule').textContent;
    expect(text).toContain('18,25 €');
    expect(text).toContain('du 01/06 au 31/08 inclus');
    expect(text).toContain('année civile 2027 (2026-2027)');
});
it('updates all edited fields, preserving a zero price', async () => {
    await load();
    document.querySelector('[name="guest_until"]').value = '15/09';
    document.querySelector('[name="amount_guest"]').value = '0';
    document.querySelector('form').dispatchEvent(new Event('input'));
    expect(document.getElementById('fees-guest-rule').textContent).toContain('0,00 €');
    expect(document.getElementById('fees-guest-rule').textContent).toContain('15/09 inclus');
});
it('shows unknown amounts explicitly and never turns text into markup', async () => {
    await load();
    document.querySelector('[name="amount_guest"]').value = '';
    document.querySelector('[name="guest_from"]').value = '<img src=x onerror=alert(1)>';
    document.querySelector('[name="guest_until"]').value = '';
    document.querySelector('form').dispatchEvent(new Event('input'));
    const rule = document.getElementById('fees-guest-rule');
    expect(rule.textContent).toContain('montant à compléter');
    expect(rule.textContent).toContain('<img src=x');
    expect(rule.textContent).toContain('au … inclus');
    expect(rule.querySelector('img')).toBeNull();
});
it.each(['', markup.replace('<p id="fees-guest-rule"></p>', ''), markup.replace('name="amount_guest"', 'name="other"')])(
    'leaves unrelated or incomplete pages alone', async (html) => { await expect(load(html)).resolves.toBeUndefined(); }
);
