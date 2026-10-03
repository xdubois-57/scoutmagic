import React, { useState } from "react";

/**
 * ScoutMagic — maquette : numéro de téléphone cliquable, partout.
 *
 * Un composant unique remplace les liens tel: copiés-collés : le numéro
 * ressemble à un lien, un tap ouvre un menu, un second tap choisit.
 *
 *   Mobile (écran tactile) : feuille en bas d'écran —
 *       Appeler · SMS · WhatsApp · Signal · Copier
 *   Fixe : Appeler · Copier seulement (ni SMS ni messagerie).
 *   Ordinateur (écran non tactile) : le numéro simple, avec « Copier ».
 *
 * Tous les numéros s'affichent au format international, belges compris.
 * Un seul point de conversion ; chaque cible en tire son format, parce
 * qu'elles n'attendent pas la même chose :
 *   tel: / sms:  → +32478123456
 *   wa.me        → 32478123456   (sans le +)
 *   signal.me    → +32478123456  (le + est obligatoire)
 *
 * Le produit est en Bootstrap 5 ; Tailwind n'est ici que pour dessiner vite.
 */

// Les numéros tels qu'ils sont stockés : trois écritures différentes, que
// l'affichage ramène à une seule forme.
const NUMBERS = [
  { id: 1, raw: "0472546771", note: "mobile belge" },
  { id: 2, raw: "02/123.45.67", note: "fixe belge" },
  { id: 3, raw: "0033612345678", note: "mobile étranger, préfixe 00" },
];

function toE164(raw) {
  const s = raw.replace(/[\s.\-/()]/g, "");
  if (s.startsWith("+")) return s;
  if (s.startsWith("00")) return "+" + s.slice(2);
  if (s.startsWith("0")) return "+32" + s.slice(1);
  return null;
}

// Affichage : tous les numéros s'écrivent au format international, belges
// compris. Un seul format partout, et le lien tel: construit sur ce texte
// (en retirant les espaces) est déjà le bon.
const CC = ["352", "351", "353", "33", "31", "49", "44", "41", "39", "34", "1", "7"];

function displayPhone(raw) {
  const e = toE164(raw);
  if (!e) return raw;
  if (e.startsWith("+32")) {
    const n = e.slice(3);
    if (n.length === 9 && n[0] === "4") return `+32 ${n.slice(0, 3)} ${n.slice(3, 5)} ${n.slice(5, 7)} ${n.slice(7, 9)}`;
    if (n.length === 8 && "2349".includes(n[0])) return `+32 ${n[0]} ${n.slice(1, 4)} ${n.slice(4, 6)} ${n.slice(6, 8)}`;
    if (n.length === 8) return `+32 ${n.slice(0, 2)} ${n.slice(2, 4)} ${n.slice(4, 6)} ${n.slice(6, 8)}`;
    return "+32 " + n;
  }
  const cc = CC.find((c) => e.slice(1).startsWith(c)) || e.slice(1, 3);
  const rest = e.slice(1 + cc.length);
  if (cc === "352") return `+${cc} ${rest.replace(/(\d\d\d)(?=\d)/g, "$1 ")}`;
  const grouped = rest.length % 2 === 1 ? `${rest[0]} ${rest.slice(1).replace(/(\d\d)(?=\d)/g, "$1 ")}` : rest.replace(/(\d\d)(?=\d)/g, "$1 ");
  return `+${cc} ${grouped}`;
}

// Belgique : 04[5-9]x = mobile. Un numéro déjà en « + » hors Belgique est
// proposé tel quel : on ne peut pas savoir s'il est fixe.
function kindOf(e164) {
  if (!e164) return "unknown";
  if (e164.startsWith("+32")) return /^\+324[5-9]/.test(e164) ? "mobile" : "fixed";
  return "mobile";
}

function linksFor(e164) {
  return {
    tel: `tel:${e164}`,
    sms: `sms:${e164}`,
    whatsapp: `https://wa.me/${e164.replace("+", "")}`,
    signal: `https://signal.me/#p/${e164}`,
  };
}

// Icônes : les glyphes de Bootstrap Icons v1.13.1 — celles que le site embarque
// déjà (public/assets/vendor/bootstrap-icons) : bi-telephone, bi-chat-dots,
// bi-whatsapp, bi-signal, bi-clipboard. Aucune dépendance nouvelle. Inlinées ici
// parce que la maquette ne charge pas la police d'icônes.
const ICON_PATHS = {
  "telephone": ["M3.654 1.328a.678.678 0 0 0-1.015-.063L1.605 2.3c-.483.484-.661 1.169-.45 1.77a17.6 17.6 0 0 0 4.168 6.608 17.6 17.6 0 0 0 6.608 4.168c.601.211 1.286.033 1.77-.45l1.034-1.034a.678.678 0 0 0-.063-1.015l-2.307-1.794a.68.68 0 0 0-.58-.122l-2.19.547a1.75 1.75 0 0 1-1.657-.459L5.482 8.062a1.75 1.75 0 0 1-.46-1.657l.548-2.19a.68.68 0 0 0-.122-.58zM1.884.511a1.745 1.745 0 0 1 2.612.163L6.29 2.98c.329.423.445.974.315 1.494l-.547 2.19a.68.68 0 0 0 .178.643l2.457 2.457a.68.68 0 0 0 .644.178l2.189-.547a1.75 1.75 0 0 1 1.494.315l2.306 1.794c.829.645.905 1.87.163 2.611l-1.034 1.034c-.74.74-1.846 1.065-2.877.702a18.6 18.6 0 0 1-7.01-4.42 18.6 18.6 0 0 1-4.42-7.009c-.362-1.03-.037-2.137.703-2.877z"],
  "chat-dots": ["M5 8a1 1 0 1 1-2 0 1 1 0 0 1 2 0m4 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 1a1 1 0 1 0 0-2 1 1 0 0 0 0 2", "m2.165 15.803.02-.004c1.83-.363 2.948-.842 3.468-1.105A9 9 0 0 0 8 15c4.418 0 8-3.134 8-7s-3.582-7-8-7-8 3.134-8 7c0 1.76.743 3.37 1.97 4.6a10.4 10.4 0 0 1-.524 2.318l-.003.011a11 11 0 0 1-.244.637c-.079.186.074.394.273.362a22 22 0 0 0 .693-.125m.8-3.108a1 1 0 0 0-.287-.801C1.618 10.83 1 9.468 1 8c0-3.192 3.004-6 7-6s7 2.808 7 6-3.004 6-7 6a8 8 0 0 1-2.088-.272 1 1 0 0 0-.711.074c-.387.196-1.24.57-2.634.893a11 11 0 0 0 .398-2"],
  "whatsapp": ["M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.9 7.9 0 0 0 13.6 2.326zM7.994 14.521a6.6 6.6 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.71 1.916.81 2.049c.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.171-.48 1.338-.943.164-.464.164-.86.114-.943-.049-.084-.182-.133-.38-.232"],
  "signal": ["m6.08.234.179.727a7.3 7.3 0 0 0-2.01.832l-.383-.643A7.9 7.9 0 0 1 6.079.234zm3.84 0L9.742.96a7.3 7.3 0 0 1 2.01.832l.388-.643A8 8 0 0 0 9.92.234m-8.77 3.63a8 8 0 0 0-.916 2.215l.727.18a7.3 7.3 0 0 1 .832-2.01l-.643-.386zM.75 8a7 7 0 0 1 .081-1.086L.091 6.8a8 8 0 0 0 0 2.398l.74-.112A7 7 0 0 1 .75 8m11.384 6.848-.384-.64a7.2 7.2 0 0 1-2.007.831l.18.728a8 8 0 0 0 2.211-.919M15.251 8q0 .547-.082 1.086l.74.112a8 8 0 0 0 0-2.398l-.74.114q.082.54.082 1.086m.516 1.918-.728-.18a7.3 7.3 0 0 1-.832 2.012l.643.387a8 8 0 0 0 .917-2.219m-6.68 5.25c-.72.11-1.453.11-2.173 0l-.112.742a8 8 0 0 0 2.396 0l-.112-.741zm4.75-2.868a7.2 7.2 0 0 1-1.537 1.534l.446.605a8 8 0 0 0 1.695-1.689zM12.3 2.163c.587.432 1.105.95 1.537 1.537l.604-.45a8 8 0 0 0-1.69-1.691zM2.163 3.7A7.2 7.2 0 0 1 3.7 2.163l-.45-.604a8 8 0 0 0-1.691 1.69l.604.45zm12.688.163-.644.387c.377.623.658 1.3.832 2.007l.728-.18a8 8 0 0 0-.916-2.214M6.913.831a7.3 7.3 0 0 1 2.172 0l.112-.74a8 8 0 0 0-2.396 0zM2.547 14.64 1 15l.36-1.549-.729-.17-.361 1.548a.75.75 0 0 0 .9.902l1.548-.357zM.786 12.612l.732.168.25-1.073A7.2 7.2 0 0 1 .96 9.74l-.727.18a8 8 0 0 0 .736 1.902l-.184.79zm3.5 1.623-1.073.25.17.731.79-.184c.6.327 1.239.574 1.902.737l.18-.728a7.2 7.2 0 0 1-1.962-.811zM8 1.5a6.5 6.5 0 0 0-6.498 6.502 6.5 6.5 0 0 0 .998 3.455l-.625 2.668L4.54 13.5a6.502 6.502 0 0 0 6.93-11A6.5 6.5 0 0 0 8 1.5"],
  "clipboard": ["M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1z", "M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0z"],
};

// Teinte de marque appliquée à la glyphe monochrome — un choix de design,
// facultatif : sans elle, les deux icônes prennent la couleur du texte.
const BRAND = { whatsapp: "#25D366", signal: "#3A76F0" };

function Icon({ name, size = 20 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 16 16" fill={BRAND[name] || "currentColor"} aria-hidden="true" style={{ flexShrink: 0 }}>
      {ICON_PATHS[name].map((d, i) => <path key={i} d={d} />)}
    </svg>
  );
}

const ENTRIES = [
  { key: "tel", label: "Appeler", icon: "telephone", mobileOnly: false },
  { key: "sms", label: "SMS", icon: "chat-dots", mobileOnly: true },
  { key: "whatsapp", label: "WhatsApp", icon: "whatsapp", mobileOnly: true },
  { key: "signal", label: "Signal", icon: "signal", mobileOnly: true },
];

function Phone({ raw, touch, onOpen }) {
  const e164 = toE164(raw);
  const shown = displayPhone(raw);
  const [copied, setCopied] = useState(false);

  function copy() {
    try { navigator.clipboard && navigator.clipboard.writeText(shown); } catch (e) { /* sans effet dans la maquette */ }
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  }

  if (!touch) {
    return (
      <span className="inline-flex items-center gap-2">
        <span>{shown}</span>
        <button onClick={copy} className="rounded border border-slate-300 px-2 text-xs text-slate-600" aria-label={`Copier ${shown}`}>
          {copied ? "Copié" : "Copier"}
        </button>
      </span>
    );
  }
  return (
    <button onClick={() => onOpen(raw, e164)} className="text-blue-600 underline" style={{ minHeight: 44 }}>
      {shown}
    </button>
  );
}

export default function MaquetteTelephone() {
  const [touch, setTouch] = useState(true);
  const [ctx, setCtx] = useState("list");
  const [sheet, setSheet] = useState(null); // { raw, e164 }
  const [copied, setCopied] = useState(false);

  const open = (raw, e164) => { setSheet({ raw, e164 }); setCopied(false); };
  const kind = sheet ? kindOf(sheet.e164) : null;

  function copy() {
    try { navigator.clipboard && navigator.clipboard.writeText(displayPhone(sheet.raw)); } catch (e) { /* sans effet dans la maquette */ }
    setCopied(true);
    setTimeout(() => { setCopied(false); setSheet(null); }, 900);
  }

  return (
    <div className="min-h-screen bg-slate-100 p-4 text-slate-800">
      <div className="mx-auto" style={{ maxWidth: 700 }}>
        <div className="mb-3 flex flex-wrap gap-2">
          {[[true, "Mobile", "écran tactile"], [false, "Ordinateur", "écran non tactile"]].map(([v, l, s]) => (
            <button key={l} onClick={() => { setTouch(v); setSheet(null); }}
              className={`rounded border px-3 py-2 text-xs ${touch === v ? "border-slate-800 bg-slate-800 text-white" : "border-slate-300 bg-white text-slate-600"}`}>
              {l}<span className="block opacity-60">{s}</span>
            </button>
          ))}
        </div>

        <div className="mb-4 flex flex-wrap gap-2">
          {[["list", "Liste"], ["table", "Tableau"], ["page", "Page d'un membre"], ["norm", "Affichage"]].map(([k, l]) => (
            <button key={k} onClick={() => { setCtx(k); setSheet(null); }}
              className={`rounded border px-3 py-2 text-xs ${ctx === k ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>{l}</button>
          ))}
        </div>

        <div className="relative overflow-hidden rounded-lg border bg-white" style={{ minHeight: 360 }}>
          {/* ───────── Liste (trombinoscope, staffs) ───────── */}
          {ctx === "list" && (
            <div className="px-4 py-3">
              <div className="mb-2 text-sm font-semibold">Louveteaux — staff</div>
              {NUMBERS.map((n, i) => (
                <div key={n.id} className="flex items-center gap-3 border-b py-3 last:border-0">
                  <span className="flex items-center justify-center rounded-full bg-slate-200 text-sm" style={{ width: 40, height: 40 }}>
                    {["SM", "MD", "AP"][i]}
                  </span>
                  <div className="flex-1">
                    <div className="text-sm font-medium">{["Sophie Martin", "Marc Dubois", "Anne Petit"][i]}</div>
                    <div className="text-xs text-slate-500">{["Akela", "Baloo", "Raksha"][i]}</div>
                  </div>
                  <div className="text-sm"><Phone raw={n.raw} touch={touch} onOpen={open} /></div>
                </div>
              ))}
            </div>
          )}

          {/* ───────── Tableau (recherche de membres) ───────── */}
          {ctx === "table" && (
            <div className="overflow-x-auto px-2 py-3">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-xs text-slate-500">
                    <th className="px-2 py-2">Nom</th><th className="px-2 py-2">Section</th><th className="px-2 py-2">Téléphone</th>
                  </tr>
                </thead>
                <tbody>
                  {NUMBERS.map((n, i) => (
                    <tr key={n.id} className="border-b last:border-0">
                      <td className="px-2 py-1">{["Tom Leroy", "Kim Nguyen", "Léa Leroy"][i]}</td>
                      <td className="px-2 py-1 text-slate-500">{["Louveteaux", "Baladins", "Louveteaux"][i]}</td>
                      <td className="whitespace-nowrap px-2 py-1"><Phone raw={n.raw} touch={touch} onOpen={open} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <p className="mt-2 px-2 text-xs text-slate-500">
                Dans un tableau, un seul élément cliquable par cellule : c'est ce qui rend le numéro
                plus sûr que deux icônes côte à côte.
              </p>
            </div>
          )}

          {/* ───────── Page d'un membre ───────── */}
          {ctx === "page" && (
            <div className="px-4 py-3">
              <div className="mb-3 text-base font-semibold">Tom Leroy</div>
              <dl className="grid grid-cols-3 gap-y-1 text-sm">
                <dt className="text-slate-500">Mobile</dt>
                <dd className="col-span-2"><Phone raw={NUMBERS[0].raw} touch={touch} onOpen={open} /></dd>
                <dt className="text-slate-500">Téléphone</dt>
                <dd className="col-span-2"><Phone raw={NUMBERS[1].raw} touch={touch} onOpen={open} /></dd>
                <dt className="text-slate-500">Contact d'urgence</dt>
                <dd className="col-span-2"><Phone raw={NUMBERS[2].raw} touch={touch} onOpen={open} /></dd>
              </dl>
            </div>
          )}

          {/* ───────── Affichage ───────── */}
          {ctx === "norm" && (
            <div className="overflow-x-auto px-2 py-3">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-xs text-slate-500">
                    <th className="px-2 py-2">Saisi</th><th className="px-2 py-2">Aujourd'hui</th><th className="px-2 py-2">Proposé</th>
                  </tr>
                </thead>
                <tbody>
                  {[
                    ["0472546771", "+32 472 54 67 71"],
                    ["+32 472 54 67 71", "+32 472 54 67 71"],
                    ["02/123.45.67", "+32 2 123 45 67"],
                    ["+33 6 12 34 56 78", "inchangé"],
                    ["0033 6 12 34 56 78", "lu comme un numéro belge"],
                    ["+352 691 123 456", "préfixe coupé après deux chiffres"],
                  ].map(([raw, now]) => (
                    <tr key={raw} className="border-b last:border-0 align-top">
                      <td className="whitespace-nowrap px-2 py-2" style={{ fontFamily: "ui-monospace, monospace" }}>{raw}</td>
                      <td className="px-2 py-2 text-xs text-slate-500">{now}</td>
                      <td className="whitespace-nowrap px-2 py-2 font-medium">{displayPhone(raw)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <p className="mt-3 px-2 text-xs leading-relaxed text-slate-600">
                Un seul format partout : international, belges compris. Rien n'est modifié en base.
                Les numéros belges s'affichent déjà ainsi ; ce qui change, c'est qu'un numéro écrit
                avec « 00 » ou venant du Luxembourg est enfin lu correctement.
              </p>
            </div>
          )}

          {/* ───────── La feuille ───────── */}
          {sheet && touch && (
            <div className="absolute inset-0 flex items-end" style={{ backgroundColor: "rgba(0,0,0,0.4)" }} onClick={() => setSheet(null)}>
              <div className="w-full rounded-t-2xl bg-white pb-3" onClick={(e) => e.stopPropagation()}>
                <div className="border-b px-4 py-3">
                  <div className="text-base font-semibold">{displayPhone(sheet.raw)}</div>
                  <div className="text-xs text-slate-500">{kind === "fixed" ? "Téléphone fixe" : "Mobile"}</div>
                </div>

                {ENTRIES.filter((e) => !(e.mobileOnly && kind === "fixed")).map((e) => (
                  <a key={e.key} href="#" onClick={(ev) => { ev.preventDefault(); setSheet(null); }}
                    className="flex items-center gap-3 border-b px-4 text-sm" style={{ minHeight: 48 }}>
                    <span style={{ width: 24, display: "inline-flex" }}><Icon name={e.icon} /></span>
                    <span className="flex-1">{e.label}</span>
                  </a>
                ))}

                <button onClick={copy} className="flex w-full items-center gap-3 px-4 text-left text-sm" style={{ minHeight: 48 }}>
                  <span style={{ width: 24, display: "inline-flex" }}><Icon name="clipboard" /></span>
                  <span className="flex-1">{copied ? "Numéro copié" : "Copier le numéro"}</span>
                </button>

                {kind === "fixed" && (
                  <p className="px-4 pt-1 text-xs text-slate-500">
                    Un numéro fixe ne reçoit ni SMS ni message : seuls l'appel et la copie sont proposés.
                  </p>
                )}
              </div>
            </div>
          )}
        </div>

        {!touch && (
          <p className="mt-3 text-xs leading-relaxed text-slate-600">
            Sur un écran non tactile, un SMS n'a pas de sens, et WhatsApp ou Signal s'ouvriraient dans
            leur version web : le numéro reste donc simple, avec « Copier ».
          </p>
        )}

      </div>
    </div>
  );
}
