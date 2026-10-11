import React, { useState } from "react";

/**
 * ScoutMagic — maquette : Formulaire d'inscription en quatre sous-pages
 * (ticket #843).
 *
 *   Tableau de bord — quatre cartes + vérification des capacités.
 *   Demandes        — notes, états, liste avec « Décider », demandes non clôturées.
 *                     La fiche d'une demande est une sous-page de Demandes.
 *   Gabarits        — les quatre e-mails du flux de demande.
 *   Réglages        — formulaire, code, capacités (case + deux interrupteurs +
 *                     slider), priorités, copie des e-mails.
 *
 * Le rail est le page_picker partagé (onglets soulignés). Le fil d'Ariane porte
 * tous les niveaux. Rien n'est repliable.
 *
 * Règle de fond (v2) : les places d'une année de parcours se remplissent dans
 * l'ordre de priorité, de gauche (haute priorité) à droite. Les demandes
 * « Toujours Oui » (la fratrie, par défaut) passent en premier et peuvent
 * dépasser la capacité ; les autres sont classées par les critères, et leur
 * niveau dépend du taux d'occupation AVANT elles : Oui jusqu'à X % de places
 * occupées, Peut-être jusqu'à Y %, Non au-delà.
 *
 * Tout est branché sur les mêmes réglages : bouger le slider change le niveau
 * dans le tableau de bord et la recommandation dans le dialogue. Dans la barre
 * du haut : l'aperçu mobile, et un bouton qui simule la rédaction des gabarits
 * d'acceptation et de refus (sans rédaction, les e-mails automatiques ne
 * peuvent pas être activés).
 *
 * Le produit est en Bootstrap 5 ; Tailwind n'est ici que pour dessiner vite.
 */

/* ---------------------------------------------------------------- données */

const TODAY = "11/10/2026";

// Capacité par « branche × année de parcours ». cap null = pas de limite.
// proj = animés qui montent dans ce créneau ; silent = parmi eux, ceux dont la famille n'a pas encore
// répondu à la réinscription (comptés comme restants).
const SLOTS = [
  { id: "bal1", label: "Baladins · 1re année", cap: 15, proj: 4, acc: 2, silent: 1 },
  { id: "bal2", label: "Baladins · 2e année", cap: 15, proj: 9, acc: 4, silent: 2 },
  { id: "lou1", label: "Louveteaux · 1re année", cap: 15, proj: 4, acc: 2, silent: 1 },
  { id: "lou2", label: "Louveteaux · 2e année", cap: 15, proj: 8, acc: 0, silent: 2 },
  { id: "ecl1", label: "Éclaireurs · 1re année", cap: 12, proj: 8, acc: 3, silent: 1 },
  { id: "ecl2", label: "Éclaireurs · 2e année", cap: null, proj: 6, acc: 0, silent: 0 },
  { id: "pio1", label: "Pionniers · 1re année", cap: 12, proj: 12, acc: 0, silent: 1 },
];

const REQUESTS = [
  { id: 412, date: "06/10", child: "Léa Martin", slot: "bal1", section: "Colonie Les Marmottes", sib: true, status: "pending", email: null },
  { id: 416, date: "08/10", child: "Emma Vidal", slot: "lou1", section: "Meute Seeonee", sib: true, status: "pending", email: null },
  { id: 417, date: "04/10", child: "Lucas Mertens", slot: "lou1", section: "Meute Seeonee", sib: false, prevNoRoom: "2025-2026", status: "pending", email: null },
  { id: 418, date: "05/10", child: "Sofia Claes", slot: "lou1", section: "Meute Seeonee", sib: false, status: "pending", email: null },
  { id: 419, date: "09/10", child: "Arthur Noël", slot: "lou1", section: "Meute Seeonee", sib: false, status: "pending", email: null },
  { id: 409, date: "03/10", child: "Noah Peeters", slot: "lou1", section: "Meute Seeonee", sib: false, status: "pending", email: null },
  { id: 414, date: "07/10", child: "Noah Peeters", slot: "lou1", section: "Meute Seeonee", sib: false, status: "pending", dupOf: 409, email: null },
  { id: 405, date: "01/10", child: "Jade Lambert", slot: "ecl1", section: "Troupe Saint-Michel", sib: false, prevNoRoom: "2025-2026", status: "pending", email: null },
  { id: 398, date: "28/09", child: "Hugo Renard", slot: "lou2", section: "Meute Seeonee", sib: true, status: "pending", email: null },
  { id: 401, date: "29/09", child: "Inès Bouchat", slot: null, section: "—", sib: false, status: "pending", email: null },
  { id: 390, date: "26/09", child: "Tom Gérard", slot: "lou1", section: "Meute Seeonee", sib: false, status: "accepted", email: { kind: "planned", at: "12/10 à 14:32" } },
  { id: 388, date: "24/09", child: "Zoé Hanin", slot: "bal2", section: "Colonie Les Marmottes", sib: false, status: "accepted", email: { kind: "sent", at: "09/10" } },
  { id: 380, date: "20/09", child: "Eliott Collin", slot: "ecl1", section: "Troupe Saint-Michel", sib: false, status: "refused", email: { kind: "failed", at: "08/10" } },
  { id: 371, date: "15/09", child: "Anaïs Dubois", slot: "lou1", section: "Meute Seeonee", sib: false, status: "encoded", email: { kind: "sent", at: "02/10" } },
];

const STATUS_LABEL = {
  pending: "En attente",
  accepted: "Acceptée",
  refused: "Refusée",
  withdrawn: "Retirée",
  encoded: "Encodée dans Desk",
};
const STATUS_STYLE = {
  pending: "bg-amber-100 text-amber-800",
  accepted: "bg-sky-100 text-sky-800",
  refused: "bg-red-100 text-red-800",
  withdrawn: "bg-slate-200 text-slate-700",
  encoded: "bg-emerald-100 text-emerald-800",
};

const LEVEL = {
  yes: { label: "Oui", cls: "bg-emerald-100 text-emerald-800 border-emerald-300" },
  maybe: { label: "Peut-être", cls: "bg-amber-100 text-amber-800 border-amber-300" },
  no: { label: "Non", cls: "bg-red-100 text-red-800 border-red-300" },
};

/* ---------------------------------------------------------------- calculs */

const remaining = (s) => (s.cap === null ? null : s.cap - s.proj - s.acc);
const occupied = (s) => s.proj + s.acc;
const tsOf = (r) => {
  const [d, m] = r.date.split("/").map(Number);
  return m * 100 + d;
};

// Les règles qui peuvent classer une demande ou la mettre d'office en « Oui ».
const RULES = {
  sib: {
    label: "Fratrie déjà dans l'unité",
    help: "Un frère ou une sœur est déjà membre de l'unité.",
    canAlways: true,
    test: (r) => !!r.sib,
  },
  prev: {
    label: "Ancienne demande restée sans place",
    help: "Une demande d'une année précédente refusée avec le motif « pas de place ».",
    canAlways: true,
    test: (r) => !!r.prevNoRoom,
  },
  arrival: {
    label: "Ordre d'arrivée de la demande",
    help: "La demande déposée en premier passe en premier.",
    canAlways: false,
    test: () => false,
  },
};

// Taux d'occupation → niveau. Bornes incluses : « Oui jusqu'à 50 % » compte 50 %.
// Capacité vide = sans limite (toujours Oui) ; capacité 0 = fermé (toujours Non).
function tierByOcc(cap, occ, cfg) {
  if (cap === null) return "yes";
  if (cap <= 0) return "no";
  const ratio = occ / cap;
  if (ratio <= cfg.yesTo / 100) return "yes";
  if (ratio <= cfg.maybeTo / 100) return "maybe";
  return "no";
}
const levelOf = (slot, cfg) => tierByOcc(slot.cap, occupied(slot), cfg);
// Ce que voient les familles : exactement les mêmes seuils que la recommandation
// (Oui = Disponible, Peut-être = Limitée, Non = Complet).
const publicLevel = levelOf;

// Plus grand nombre de places occupées qui reste dans la limite — par essai, jamais
// par multiplication, pour ne pas dépendre d'un arrondi flottant.
function maxOccupiedFor(cap, pct) {
  for (let o = Math.ceil(cap * 1.2) + 1; o >= 0; o--) if (o / cap <= pct / 100) return o;
  return -1;
}

// La file d'un créneau : « Toujours Oui » d'abord (par date de dépôt), puis les autres
// classées par les critères actifs. Chaque demande est jugée sur l'occupation AVANT elle,
// comme si toutes celles qui la précèdent étaient acceptées. Les doublons et les demandes
// hors des tranches d'âge n'y figurent pas.
function queueFor(slotId, cfg, order) {
  const slot = SLOTS.find((s) => s.id === slotId);
  if (!slot) return null;
  const pend = REQUESTS.filter((r) => r.status === "pending" && r.slot === slotId && !r.dupOf);
  const isAlways = (r) => order.always.some((id) => RULES[id].test(r));
  const always = pend.filter(isAlways).sort((a, b) => tsOf(a) - tsOf(b));
  const rest = pend.filter((r) => !isAlways(r)).sort((a, b) => {
    for (const id of order.rank) {
      if (id === "arrival") {
        if (tsOf(a) !== tsOf(b)) return tsOf(a) - tsOf(b);
      } else {
        const ta = RULES[id].test(a);
        const tb = RULES[id].test(b);
        if (ta !== tb) return ta ? -1 : 1;
      }
    }
    return tsOf(a) - tsOf(b);
  });
  let occ = occupied(slot);
  const out = [];
  always.forEach((r) => {
    out.push({ r, kind: "always", occBefore: occ, level: "yes" });
    occ++;
  });
  rest.forEach((r, i) => {
    out.push({ r, kind: "rank", rank: i + 1, of: rest.length, occBefore: occ, level: tierByOcc(slot.cap, occ, cfg) });
    occ++;
  });
  return { slot, out, base: occupied(slot) };
}

function recommend(req, cfg, order) {
  const slot = SLOTS.find((s) => s.id === req.slot);
  if (!slot) {
    return { level: null, text: "Pas de recommandation : l'année de naissance est hors des tranches d'âge de l'unité." };
  }
  if (req.dupOf) {
    return { level: null, text: `Pas de recommandation tant que le doublon possible de la demande #${req.dupOf} n'est pas réglé.` };
  }
  const q = queueFor(req.slot, cfg, order);
  const e = q.out.find((x) => x.r.id === req.id);
  if (!e) return { level: null, text: "Cette demande n'est plus en attente." };
  const pct = slot.cap ? Math.round((e.occBefore / slot.cap) * 100) : null;
  const matched = [];
  if (order.always.includes("sib") && req.sib) matched.push("fratrie déjà présente dans l'unité");
  if (order.always.includes("prev") && req.prevNoRoom) matched.push(`déjà demandée en ${req.prevNoRoom} et restée sans place`);
  let text;
  if (e.kind === "always") {
    text = `Oui — règle « Toujours Oui » : ${matched.join(", ")}.`;
    if (slot.cap !== null && e.occBefore >= slot.cap)
      text += ` Attention : ${slot.label} est déjà complète, cette acceptation dépasse la capacité.`;
  } else if (slot.cap === null) {
    text = `Oui — aucune limite n'est fixée pour ${slot.label}.`;
  } else {
    const lead = `${e.rank}${e.rank === 1 ? "re" : "e"} demande classée sur ${e.of} pour ${slot.label}. Avant elle, ${e.occBefore} place${e.occBefore > 1 ? "s" : ""} sur ${slot.cap} seraient occupées (${pct} %)`;
    if (e.level === "yes") text = `Oui — ${lead}, dans la limite du « Oui » (${cfg.yesTo} %).`;
    else if (e.level === "maybe") text = `Peut-être — ${lead}, au-delà de la limite du « Oui » (${cfg.yesTo} %).`;
    else if (e.occBefore >= slot.cap && cfg.maybeTo <= 100) text = `Non — ${slot.label} serait complète avant elle.`;
    else text = `Non — ${lead}, au-delà de la limite du « Peut-être » (${cfg.maybeTo} %).`;
    if (e.level !== "no" && e.occBefore >= slot.cap) text += " Accepter cette demande dépasse la capacité.";
    const ranked = [];
    if (order.rank.includes("prev") && req.prevNoRoom) ranked.push(`ancienne demande restée sans place (${req.prevNoRoom})`);
    if (order.rank.includes("sib") && req.sib) ranked.push("fratrie dans l'unité");
    if (ranked.length) text += ` Classement favorisé par : ${ranked.join(", ")}.`;
  }
  return { level: e.level, text, slot, entry: e };
}

function nextOccurrence(md) {
  // « MM-JJ » récurrent → la prochaine date réelle (démo : aujourd'hui = 11/10/2026).
  const [m, d] = md.split("-").map(Number);
  const today = new Date(2026, 9, 11);
  let y = 2026;
  if (new Date(y, m - 1, d) < today) y += 1;
  const p = (n) => String(n).padStart(2, "0");
  return `${y}-${p(m)}-${p(d)}`;
}
const frDate = (iso) => iso.split("-").reverse().join("/");

function plusHours(h) {
  // Démo : 11/10 à 15:00 + h
  const base = new Date(2026, 9, 11, 15, 0);
  const d = new Date(base.getTime() + h * 3600 * 1000);
  const p = (n) => String(n).padStart(2, "0");
  return `${p(d.getDate())}/${p(d.getMonth() + 1)} à ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/* ------------------------------------------------------------- petits blocs */

function Pill({ cls, children }) {
  return <span className={`inline-block rounded px-2 py-0.5 text-xs ${cls}`}>{children}</span>;
}

function Card({ title, children, right, className = "" }) {
  return (
    <section className={`mb-4 rounded-lg border bg-white p-4 ${className}`}>
      {(title || right) && (
        <div className="mb-3 flex items-start justify-between gap-2">
          <h2 className="text-base font-semibold">{title}</h2>
          {right}
        </div>
      )}
      {children}
    </section>
  );
}

function Switch({ checked, onChange, disabled, label, help, id }) {
  return (
    <div className={`mb-3 ${disabled ? "opacity-60" : ""}`}>
      <label htmlFor={id} className="flex min-h-[44px] items-center gap-3 text-sm">
        <input
          id={id}
          type="checkbox"
          role="switch"
          checked={checked}
          disabled={disabled}
          onChange={(e) => onChange(e.target.checked)}
          className="h-5 w-9 cursor-pointer"
        />
        <span className="font-medium">{label}</span>
      </label>
      {help && <div className="ml-12 text-xs text-slate-500">{help}</div>}
    </div>
  );
}

function Btn({ children, kind = "outline", onClick, disabled, small = true, title }) {
  const kinds = {
    primary: "border-blue-600 bg-blue-600 text-white",
    outline: "border-slate-300 bg-white text-slate-700",
    danger: "border-red-500 bg-white text-red-700",
    success: "border-emerald-600 bg-emerald-600 text-white",
  };
  return (
    <button
      type="button"
      title={title}
      onClick={onClick}
      disabled={disabled}
      className={`min-h-[44px] rounded border px-3 ${small ? "text-sm" : "text-base"} ${kinds[kind]} ${disabled ? "opacity-50" : ""}`}
    >
      {children}
    </button>
  );
}

function Modal({ title, children, onClose, footer }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-3">
      <div className="max-h-[92vh] w-full max-w-lg overflow-auto rounded-lg bg-white shadow-xl">
        <div className="flex items-center justify-between border-b px-4 py-3">
          <h3 className="text-base font-semibold">{title}</h3>
          <button onClick={onClose} aria-label="Fermer" className="min-h-[44px] min-w-[44px] text-xl text-slate-500">
            ×
          </button>
        </div>
        <div className="p-4">{children}</div>
        {footer && <div className="flex flex-wrap justify-end gap-2 border-t px-4 py-3">{footer}</div>}
      </div>
    </div>
  );
}

/* ---------------------------------------------------- slider à deux curseurs */

const SLIDER_MAX = 120;

function DualSlider({ yesTo, maybeTo, onChange, disabled }) {
  const setY = (v) => onChange({ yesTo: Math.max(1, Math.min(v, maybeTo - 1)), maybeTo });
  const setM = (v) => onChange({ yesTo, maybeTo: Math.min(SLIDER_MAX, Math.max(v, yesTo + 1)) });
  const w = (v) => `${(v / SLIDER_MAX) * 100}%`;
  return (
    <div className={disabled ? "opacity-50" : ""}>
      <style>{`
        .dual{position:absolute;left:0;top:0;width:100%;height:36px;margin:0;background:transparent;pointer-events:none;-webkit-appearance:none;appearance:none}
        .dual::-webkit-slider-thumb{pointer-events:all;-webkit-appearance:none;width:26px;height:26px;border-radius:50%;background:#fff;border:3px solid #1d4ed8;cursor:pointer}
        .dual::-moz-range-thumb{pointer-events:all;width:20px;height:20px;border-radius:50%;background:#fff;border:3px solid #1d4ed8;cursor:pointer}
        .dual::-webkit-slider-runnable-track{background:transparent}
        .dual::-moz-range-track{background:transparent}
      `}</style>
      <div className="relative h-9">
        <div className="absolute left-0 right-0 top-3 flex h-3 overflow-hidden rounded">
          <div className="bg-emerald-300" style={{ width: w(yesTo) }} />
          <div className="bg-amber-300" style={{ width: w(maybeTo - yesTo) }} />
          <div className="bg-red-300" style={{ width: w(SLIDER_MAX - maybeTo) }} />
        </div>
        <div className="absolute top-1 h-7 border-l-2 border-slate-700" style={{ left: w(100) }} aria-hidden />
        <input
          className="dual"
          type="range"
          min={1}
          max={SLIDER_MAX}
          value={yesTo}
          disabled={disabled}
          aria-label="Oui jusqu'à ce pourcentage de places occupées"
          onChange={(e) => setY(Number(e.target.value))}
        />
        <input
          className="dual"
          type="range"
          min={1}
          max={SLIDER_MAX}
          value={maybeTo}
          disabled={disabled}
          aria-label="Peut-être jusqu'à ce pourcentage de places occupées"
          onChange={(e) => setM(Number(e.target.value))}
        />
      </div>
      <div className="flex text-xs font-medium">
        <div style={{ width: w(yesTo) }} className="text-emerald-800">
          Oui
        </div>
        <div style={{ width: w(maybeTo - yesTo) }} className="text-amber-800">
          Peut-être
        </div>
        <div style={{ width: w(SLIDER_MAX - maybeTo) }} className="text-red-800">
          Non
        </div>
      </div>
      <div className="relative mt-1 h-4 text-[11px] text-slate-500">
        <span className="absolute left-0">0 %</span>
        <span className="absolute -translate-x-1/2" style={{ left: w(100) }}>
          100 % · toutes les places
        </span>
        <span className="absolute right-0">120 %</span>
      </div>
      <div className="mt-1 text-xs text-slate-500">
        La barre est l'ensemble des places d'une année de parcours : elles se remplissent dans l'ordre de priorité, de gauche à droite.
        Plus la zone verte est grande, plus on dit « Oui ». Au-delà de 100 %, on accepte plus de demandes qu'il n'y a de places.
      </div>
    </div>
  );
}

/* ------------------------------------------------------------- priorités */

const ZONES = {
  always: { title: "Toujours Oui", sub: "Ces demandes passent en premier, sont toujours recommandées « Oui » et peuvent dépasser la capacité." },
  rank: { title: "Classement", sub: "Les autres demandes sont classées par ces critères, de haut en bas, puis acceptées dans cet ordre jusqu'au nombre de places." },
  off: { title: "Désactivés", sub: "" },
};

function PriorityLists({ order, setOrder, mobile }) {
  const [drag, setDrag] = useState(null);
  const [notice, setNotice] = useState(null);

  const move = (list, i, d) => {
    const arr = [...order[list]];
    const j = i + d;
    if (j < 0 || j >= arr.length) return;
    [arr[i], arr[j]] = [arr[j], arr[i]];
    setOrder({ ...order, [list]: arr });
  };
  const put = (id, toList, beforeId) => {
    if (toList === "always" && !RULES[id].canAlways) {
      setNotice(`« ${RULES[id].label} » ne peut pas être « Toujours Oui » : elle ne sert qu'à classer.`);
      return;
    }
    setNotice(null);
    const without = { always: order.always.filter((x) => x !== id), rank: order.rank.filter((x) => x !== id), off: order.off.filter((x) => x !== id) };
    const arr = [...without[toList]];
    const idx = beforeId ? arr.indexOf(beforeId) : arr.length;
    arr.splice(idx < 0 ? arr.length : idx, 0, id);
    setOrder({ ...without, [toList]: arr });
  };
  const drop = (toList, beforeId) => {
    if (!drag) return;
    put(drag, toList, beforeId);
    setDrag(null);
  };

  const renderRow = (id, list, i, n) => (
    <li
      key={id}
      draggable={!mobile}
      onDragStart={() => setDrag(id)}
      onDragOver={(e) => e.preventDefault()}
      onDrop={(e) => {
        e.stopPropagation();
        drop(list, id);
      }}
      className="flex items-center gap-2 border-b bg-white px-2 py-2 last:border-0"
    >
      {!mobile ? (
        <span className="cursor-grab select-none px-1 text-lg text-slate-400" aria-hidden>
          ⋮⋮
        </span>
      ) : (
        <span className="flex flex-col">
          <button disabled={i === 0} onClick={() => move(list, i, -1)} aria-label="Monter" className="min-h-[22px] min-w-[44px] text-xs disabled:opacity-30">
            ▲
          </button>
          <button disabled={i === n - 1} onClick={() => move(list, i, 1)} aria-label="Descendre" className="min-h-[22px] min-w-[44px] text-xs disabled:opacity-30">
            ▼
          </button>
        </span>
      )}
      <div className="flex-1">
        <div className="text-sm font-medium">{RULES[id].label}</div>
        <div className="text-xs text-slate-500">{RULES[id].help}</div>
      </div>
      {mobile && (
        <select
          aria-label={`Déplacer — ${RULES[id].label}`}
          value={list}
          onChange={(e) => put(id, e.target.value, null)}
          className="min-h-[44px] rounded border px-1 text-xs"
        >
          <option value="always">Toujours Oui</option>
          <option value="rank">Classement</option>
          <option value="off">Désactivé</option>
        </select>
      )}
    </li>
  );

  const zone = (key, tone, empty) => (
    <div className="mt-3">
      <div className={`mb-1 text-xs font-semibold uppercase tracking-wide ${key === "off" ? "text-slate-400" : "text-slate-600"}`}>{ZONES[key].title}</div>
      {ZONES[key].sub && <div className="mb-1 text-xs text-slate-500">{ZONES[key].sub}</div>}
      <ul
        className={`min-h-[48px] overflow-hidden rounded border ${tone}`}
        onDragOver={(e) => e.preventDefault()}
        onDrop={() => drop(key, null)}
      >
        {order[key].map((id, i) => renderRow(id, key, i, order[key].length))}
        {order[key].length === 0 && <li className="px-3 py-3 text-sm text-slate-400">{empty}</li>}
      </ul>
    </div>
  );

  return (
    <div>
      {zone("always", "border-emerald-300", "Aucune règle : toutes les demandes sont classées.")}
      {zone("rank", "", "Aucun critère : les demandes sont acceptées dans l'ordre de dépôt.")}
      <div className="mt-2 flex items-start gap-2 rounded border border-slate-300 bg-slate-50 px-3 py-2">
        <span className="text-slate-500" aria-hidden>
          🔒
        </span>
        <div className="flex-1">
          <div className="text-sm font-medium">Limite : le nombre de places de l'année de parcours</div>
          <div className="text-xs text-slate-500">
            Toujours appliquée, en fin de classement. Une fois les places occupées, les demandes classées au-delà sont recommandées « Non ».
            Deux demandes à égalité sont départagées par leur date de dépôt.
          </div>
        </div>
      </div>
      {zone("off", "border-dashed bg-slate-50", "Glissez un critère ici pour le désactiver.")}
      {notice && <div className="mt-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">{notice}</div>}
    </div>
  );
}

function DecideDialog({ r, cfg, order, onClose, flash }) {
    const rec = recommend(r, cfg, order);
    const [note, setNote] = useState("");
    const [refusing, setRefusing] = useState(false);
    const [reason, setReason] = useState(rec.level === "no" ? "room" : "");
    const slot = SLOTS.find((s) => s.id === r.slot);
    const inAuto =
      cfg.autoAccept && cfg.waitlist && rec.level === "yes" && !r.dupOf && slot;
    const mailIfAccept = cfg.autoEmails
      ? `Si vous acceptez : l'e-mail d'acceptation partira le ${plusHours(cfg.delay)}. D'ici là, vous pouvez changer d'avis sans que rien ne soit envoyé.`
      : "Si vous acceptez : aucun e-mail ne part tout seul, vous l'envoyez depuis la fiche.";
    const mailIfRefuse = cfg.autoEmails
      ? `Si vous refusez : l'e-mail de refus partira le ${plusHours(cfg.delay)}.`
      : "Si vous refusez : aucun e-mail ne part tout seul, vous l'envoyez depuis la fiche.";
    return (
      <Modal
        title={`Décider — ${r.child}`}
        onClose={() => onClose()}
        footer={
          <>
            <Btn onClick={() => onClose()}>Annuler</Btn>
            <Btn
              kind="danger"
              onClick={() => {
                if (!refusing) setRefusing(true);
                else if (reason) { onClose(); flash("Demande refusée."); }
              }}
              disabled={refusing && !reason}
            >
              {refusing ? "Confirmer le refus" : "Refuser"}
            </Btn>
            <Btn kind="success" onClick={() => { onClose(); flash("Demande acceptée."); }}>
              Accepter
            </Btn>
          </>
        }
      >
        <div className="mb-3 text-sm text-slate-600">
          {slot ? slot.label : "Hors des tranches d'âge"} · {r.section} · reçue le {r.date}
        </div>

        {cfg.waitlist && (
          <div className={`mb-3 rounded border p-3 ${rec.level ? LEVEL[rec.level].cls : "border-slate-300 bg-slate-50 text-slate-700"}`}>
            <div className="mb-1 text-xs font-semibold uppercase tracking-wide">Recommandation</div>
            <div className="text-sm">{rec.text}</div>
          </div>
        )}
        {cfg.waitlist && r.dupOf && (
          <div className="mb-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
            Doublon possible de la demande #{r.dupOf} : même enfant, même date de naissance. Le traitement automatique ne la prendra pas.
          </div>
        )}
        {cfg.waitlist && !r.dupOf && !slot && cfg.autoAccept && (
          <div className="mb-3 text-xs text-slate-500">Le traitement automatique ne prendra pas cette demande.</div>
        )}
        {inAuto && (
          <div className="mb-3 text-xs text-slate-500">
            Sans décision de votre part, cette demande sera acceptée automatiquement à partir du {cfg.acceptFrom.split("-").reverse().join("/")}.
          </div>
        )}

        <label className="mb-3 block text-sm">
          <span className="mb-1 block text-slate-600">Note interne</span>
          <textarea value={note} onChange={(e) => setNote(e.target.value)} className="min-h-[72px] w-full rounded border p-2" placeholder="Visible par le Staff d'U seulement" />
        </label>

        {refusing && (
          <label className="mb-3 block text-sm">
            <span className="mb-1 block text-slate-600">Motif du refus</span>
            <select value={reason} onChange={(e) => setReason(e.target.value)} className="min-h-[44px] w-full rounded border px-2">
              <option value="">Choisir…</option>
              <option value="room">Pas de place</option>
              <option value="other">Autre</option>
            </select>
            <span className="mt-1 block text-xs text-slate-500">Un refus « pas de place » compte pour le critère « Ancienne demande restée sans place » l'année suivante.</span>
          </label>
        )}

        <div className="rounded bg-slate-50 p-3 text-xs text-slate-600">
          <div>{mailIfAccept}</div>
          <div className="mt-1">{mailIfRefuse}</div>
        </div>
      </Modal>
    );
  }

/* =================================================================== app */

export default function MaquetteFormulaireInscription() {
  const [page, setPage] = useState("dashboard");
  const [detailId, setDetailId] = useState(null);
  const [mobile, setMobile] = useState(false);
  const [deciding, setDeciding] = useState(null);
  const [confirm, setConfirm] = useState(null);
  const [toast, setToast] = useState(null);

  const [cfg, setCfg] = useState({
    formOpen: true,
    openMD: "09-30",
    closeMD: "08-31",
    waitlist: true,
    yesTo: 70,
    maybeTo: 100,
    autoEmails: false,
    delay: 24,
    autoAccept: false,
    acceptFrom: "2026-10-15",
    ccOn: true,
    ccAddr: "",
  });
  const set = (patch) => setCfg((c) => ({ ...c, ...patch }));

  const [tpl, setTpl] = useState({ receipt: "default", alert: "default", accepted: "missing", refused: "missing" });
  const decisionTplReady = tpl.accepted !== "missing" && tpl.refused !== "missing";

  const [order, setOrder] = useState({ always: ["sib"], rank: ["prev", "arrival"], off: [] });
  const [exampleCap, setExampleCap] = useState(15);

  const flash = (m) => {
    setToast(m);
    setTimeout(() => setToast(null), 2600);
  };

  const pending = REQUESTS.filter((r) => r.status === "pending");
  const toSend = REQUESTS.filter((r) => r.email && r.email.kind === "planned").length;
  const notInDesk = REQUESTS.filter((r) => r.status === "accepted").length;

  const pages = [
    ["dashboard", "Tableau de bord"],
    ["requests", "Demandes"],
    ["templates", "Gabarits"],
    ["settings", "Réglages"],
  ];
  const pickerCurrent = page === "detail" ? "requests" : page;
  const crumbs =
    page === "detail"
      ? ["Espace chefs d'U", "Formulaire d'inscription", "Demandes", `Demande #${detailId}`]
      : ["Espace chefs d'U", "Formulaire d'inscription", pages.find((p) => p[0] === page)[1]];

  /* ----------------------------------------------------------- dashboard */

  const Dashboard = () => (
    <>
      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        {[
          ["Formulaire", cfg.formOpen ? "Ouvert" : "Fermé", cfg.formOpen ? "text-emerald-700" : "text-red-700"],
          ["Demandes en attente", pending.length, ""],
          ["E-mails de décision à envoyer", toSend, ""],
          ["Acceptées, pas encore dans Desk", notInDesk, ""],
        ].map(([l, v, c]) => (
          <div key={l} className="rounded-lg border bg-white p-3">
            <div className="text-xs text-slate-500">{l}</div>
            <div className={`mt-1 text-2xl font-semibold ${c}`}>{v}</div>
          </div>
        ))}
      </div>

      <Card title="Vérification des capacités — année 2027-2028">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[620px] text-sm">
            <thead className="text-left text-xs text-slate-500">
              <tr>
                <th className="py-1">Branche · année</th>
                <th>Capacité</th>
                <th>Effectif projeté</th>
                <th>Demandes acceptées</th>
                {cfg.waitlist && <th>Restant</th>}
                {cfg.waitlist && <th>Niveau public</th>}
              </tr>
            </thead>
            <tbody>
              {SLOTS.map((s) => {
                const lv = publicLevel(s, cfg);
                const rem = remaining(s);
                return (
                  <tr key={s.id} className="border-t">
                    <td className="py-2">{s.label}</td>
                    <td>{s.cap === null ? <span className="text-slate-500">sans limite</span> : s.cap}</td>
                    <td>
                      {s.proj}
                      {s.silent > 0 && <span className="text-xs text-slate-500"> (dont {s.silent} sans réponse)</span>}
                    </td>
                    <td>{s.acc}</td>
                    {cfg.waitlist && <td>{rem === null ? "—" : rem}</td>}
                    {cfg.waitlist && (
                      <td>
                        <Pill cls={LEVEL[lv].cls}>
                          {lv === "yes" ? "Disponible" : lv === "maybe" ? "Limitée" : "Complet"}
                        </Pill>
                      </td>
                    )}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        <p className="mt-3 text-xs text-slate-500">
          L'effectif projeté place chaque animé selon son année de parcours réelle, y compris ceux qui avancent ou restent d'une année en
          plus. Un animé dont la famille n'a rien répondu à la réinscription est compté comme restant : seuls les départs annoncés (par la
          famille ou par un chef) le retirent. Les demandes acceptées comptent tant qu'elles occupent une place, qu'elles soient déjà
          encodées dans Desk ou non.
        </p>
      </Card>
    </>
  );

  /* ------------------------------------------------------------ demandes */

  const emailChip = (r) => {
    if (!r.email) return <span className="text-slate-400">—</span>;
    const e = r.email;
    if (e.kind === "planned") return <Pill cls="bg-sky-100 text-sky-800">Prévu le {e.at}</Pill>;
    if (e.kind === "sent") return <Pill cls="bg-emerald-100 text-emerald-800">Envoyé le {e.at}</Pill>;
    return <Pill cls="bg-red-100 text-red-800">Échec le {e.at}</Pill>;
  };

  const Requests = () => (
    <>
      <Card title="Notes internes">
        <div className="rounded border bg-slate-50 p-3 text-sm text-slate-600">
          Priorité aux fratries pour les Louveteaux cette année. Appeler les parents de Hugo avant d'accepter.
        </div>
        <div className="mt-2 text-xs text-slate-500">Visibles par le Staff d'U seulement. Modifiables avec le crayon.</div>
      </Card>

      <Card title="États d'une demande">
        <ul className="grid gap-1 text-sm md:grid-cols-2">
          <li>
            <Pill cls={STATUS_STYLE.pending}>En attente</Pill> à décider
          </li>
          <li>
            <Pill cls={STATUS_STYLE.accepted}>Acceptée</Pill> acceptée, pas encore encodée dans Desk
          </li>
          <li>
            <Pill cls={STATUS_STYLE.encoded}>Encodée dans Desk</Pill> inscription reliée à un membre
          </li>
          <li>
            <Pill cls={STATUS_STYLE.refused}>Refusée</Pill> / <Pill cls={STATUS_STYLE.withdrawn}>Retirée</Pill> états finaux
          </li>
        </ul>
      </Card>

      <Card
        title="Demandes — année 2027-2028"
        right={
          <div className="flex gap-2">
            <select className="min-h-[44px] rounded border px-2 text-sm" defaultValue="2027-2028">
              <option>2027-2028</option>
              <option>2026-2027</option>
            </select>
            <Btn>Exporter</Btn>
          </div>
        }
      >
        <div className="mb-3 flex flex-wrap gap-2">
          {["Toutes", "En attente", "Acceptées", "Refusées", "Encodées"].map((c, i) => (
            <span key={c} className={`rounded-full border px-3 py-2 text-sm ${i === 0 ? "border-blue-600 bg-blue-50 text-blue-700" : "bg-white text-slate-600"}`}>
              {c}
            </span>
          ))}
          <input placeholder="Rechercher un enfant" className="min-h-[44px] flex-1 rounded border px-3 text-sm" />
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[820px] text-sm">
            <thead className="text-left text-xs text-slate-500">
              <tr>
                <th className="py-1">Reçue le</th>
                <th>Enfant</th>
                <th>Créneau</th>
                <th>Section prévue</th>
                <th>Fratrie</th>
                <th>État</th>
                <th>E-mail de décision</th>
                <th>Action rapide</th>
              </tr>
            </thead>
            <tbody>
              {REQUESTS.map((r) => {
                const slot = SLOTS.find((s) => s.id === r.slot);
                return (
                  <tr key={r.id} className="border-t align-top">
                    <td className="py-2">{r.date}</td>
                    <td>
                      <button className="text-blue-700 underline" onClick={() => { setDetailId(r.id); setPage("detail"); }}>
                        {r.child}
                      </button>
                      {r.dupOf && (
                        <div>
                          <Pill cls="bg-amber-100 text-amber-800">Doublon possible de #{r.dupOf}</Pill>
                        </div>
                      )}
                    </td>
                    <td>{slot ? slot.label : <span className="text-slate-400">hors tranches</span>}</td>
                    <td>{r.section}</td>
                    <td>{r.sib ? "Oui" : "—"}</td>
                    <td>
                      <Pill cls={STATUS_STYLE[r.status]}>{STATUS_LABEL[r.status]}</Pill>
                    </td>
                    <td>{emailChip(r)}</td>
                    <td>
                      {r.status === "pending" ? (
                        <Btn kind="primary" onClick={() => setDeciding(r)}>
                          Décider
                        </Btn>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </Card>

      <Card title="Demandes non clôturées">
        <p className="mb-2 text-sm text-slate-600">
          Demandes d'années précédentes encore en attente ou acceptées sans être encodées dans Desk.
        </p>
        <div className="mb-3 divide-y rounded border text-sm">
          {[
            ["2026-2027", "Max Lejeune", "Acceptée"],
            ["2026-2027", "Lola Vandenberghe", "En attente"],
          ].map(([y, n, s]) => (
            <label key={n} className="flex min-h-[44px] items-center gap-3 px-3">
              <input type="checkbox" />
              <span className="flex-1">
                {n} <span className="text-slate-500">· {y}</span>
              </span>
              <span className="text-slate-500">{s}</span>
            </label>
          ))}
        </div>
        <div className="flex flex-wrap gap-2">
          <Btn kind="danger" onClick={() => setConfirm({ title: "Refuser la sélection", lines: ["Un motif de refus est demandé : « pas de place » ou « autre »."], bulk: true })}>
            Refuser la sélection
          </Btn>
          <Btn>Retirer la sélection</Btn>
        </div>
      </Card>
    </>
  );

  /* ------------------------------------------------------------ gabarits */

  const TPLS = [
    ["receipt", "Accusé de réception de la demande", "Envoyé à la famille dès le dépôt."],
    ["alert", "Alerte à l'unité", "Envoyé à l'unité à chaque nouvelle demande."],
    ["accepted", "Acceptation de la demande", "Envoyé à la famille quand la demande est acceptée."],
    ["refused", "Refus de la demande", "Envoyé à la famille quand la demande est refusée."],
  ];
  const TPL_STATE = {
    default: ["Par défaut", "bg-slate-100 text-slate-700"],
    custom: ["Personnalisé", "bg-blue-100 text-blue-800"],
    missing: ["À rédiger", "bg-red-100 text-red-800"],
  };
  const Templates = () => (
    <Card title="Gabarits des e-mails de demande">
      {!decisionTplReady && (
        <div className="mb-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          Les gabarits d'acceptation et de refus doivent être rédigés avant le premier envoi. Tant que l'un manque, aucun e-mail de
          décision ne part, ni à la main ni automatiquement.
        </div>
      )}
      <ul className="divide-y rounded border">
        {TPLS.map(([k, name, desc]) => (
          <li key={k} className="flex items-center gap-3 px-3 py-3">
            <div className="flex-1">
              <div className="text-sm font-medium">{name}</div>
              <div className="text-xs text-slate-500">{desc}</div>
            </div>
            <Pill cls={TPL_STATE[tpl[k]][1]}>{TPL_STATE[tpl[k]][0]}</Pill>
            <button
              aria-label={`Modifier — ${name}`}
              title={`Modifier — ${name}`}
              onClick={() => setTpl({ ...tpl, [k]: "custom" })}
              className="min-h-[44px] min-w-[44px] rounded border border-slate-300 text-slate-700"
            >
              ✎
            </button>
          </li>
        ))}
      </ul>
      <p className="mt-3 text-xs text-slate-500">Les e-mails de réinscription se règlent dans la gestion des réinscriptions.</p>
    </Card>
  );

  /* ------------------------------------------------------------ réglages */

  const wl = cfg.waitlist;
  const exYes = maxOccupiedFor(exampleCap, cfg.yesTo);
  const exMaybe = maxOccupiedFor(exampleCap, cfg.maybeTo);

  const saveAutomation = () => {
    const lines = [];
    if (cfg.autoEmails) {
      lines.push(
        `Les e-mails d'acceptation et de refus partiront ${cfg.delay} h après chaque décision. Tant qu'ils ne sont pas partis, vous pouvez changer la décision et rien n'est envoyé.`
      );
      lines.push("Oui, des e-mails vont partir : uniquement pour les décisions prises à partir de maintenant. Les 1 décision déjà prise sans envoi reste à envoyer à la main depuis sa fiche.");
    } else {
      lines.push("Non, aucun e-mail ne partira tout seul : chaque envoi se fait depuis la fiche de la demande.");
    }
    if (cfg.autoAccept) {
      const n = ["bal1", "bal2", "lou1", "lou2", "ecl1", "ecl2", "pio1"]
        .map((id) => queueFor(id, cfg, order))
        .reduce((t, q) => t + q.out.filter((e) => e.level === "yes").length, 0);
      lines.push(
        `À partir du ${cfg.acceptFrom.split("-").reverse().join("/")}, les demandes recommandées « Oui » seront acceptées par le site : ${n} demande${n > 1 ? "s" : ""} en attente concerné${n > 1 ? "es" : "e"} aujourd'hui.`
      );
      lines.push(`Leurs familles recevront l'e-mail d'acceptation ${cfg.delay} h plus tard.`);
    }
    setConfirm({ title: "Enregistrer les décisions automatiques ?", lines, onOk: () => flash("Réglages enregistrés.") });
  };

  const Settings = () => (
    <>
      <Card title="Formulaire d'inscription">
        <Switch id="open" checked={cfg.formOpen} onChange={(v) => set({ formOpen: v })} label={cfg.formOpen ? "Formulaire ouvert" : "Formulaire fermé"} />
        <div className="grid gap-3 md:grid-cols-2">
          <label className="text-sm">
            <span className="mb-1 block text-slate-600">Ouverture programmée</span>
            <input
              type="date"
              value={nextOccurrence(cfg.openMD)}
              onChange={(e) => e.target.value && set({ openMD: e.target.value.slice(5) })}
              className="min-h-[44px] w-full rounded border px-3"
            />
            <span className="mt-1 block text-xs text-slate-500">Se répète chaque année. Prochaine ouverture : {frDate(nextOccurrence(cfg.openMD))}.</span>
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-slate-600">Fermeture programmée</span>
            <input
              type="date"
              value={nextOccurrence(cfg.closeMD)}
              onChange={(e) => e.target.value && set({ closeMD: e.target.value.slice(5) })}
              className="min-h-[44px] w-full rounded border px-3"
            />
            <span className="mt-1 block text-xs text-slate-500">Se répète chaque année. Prochaine fermeture : {frDate(nextOccurrence(cfg.closeMD))}.</span>
          </label>
        </div>
        <div className="mt-3"><Btn kind="primary" onClick={() => flash("Réglages enregistrés.")}>Enregistrer</Btn></div>
      </Card>

      <Card title="Code d'inscription en cours d'année — année 2027-2028">
        <p className="mb-2 text-sm text-slate-600">Ce code permet à une famille d'accéder au formulaire même lorsque les inscriptions sont fermées.</p>
        <div className="flex flex-wrap items-center gap-2">
          <code className="rounded bg-slate-100 px-3 py-2 text-sm">K7P-4QX2</code>
          <Btn>Régénérer</Btn>
          <Btn kind="danger">Désactiver</Btn>
        </div>
      </Card>

      <Card title="Capacités par branche">
        <Switch
          id="wl"
          checked={wl}
          onChange={(v) => set(v ? { waitlist: true } : { waitlist: false, autoAccept: false })}
          label="Gérer les listes d'attente"
          help="Actif : les familles voient pour chaque année de naissance si c'est disponible, limité ou complet, et vous voyez une recommandation Oui / Peut-être / Non sur chaque demande."
        />

        <div className="rounded border border-slate-200 bg-slate-50 p-3">
          <Switch
            id="autoEmails"
            checked={cfg.autoEmails}
            disabled={!decisionTplReady}
            onChange={(v) => set(v ? { autoEmails: true } : { autoEmails: false, autoAccept: false })}
            label="Envoyer automatiquement les e-mails de décision"
            help={
              decisionTplReady
                ? "L'e-mail d'acceptation ou de refus part après le délai ci-dessous, ce qui laisse le temps de corriger une erreur."
                : "Indisponible : les gabarits d'acceptation et de refus ne sont pas encore rédigés (voir Gabarits)."
            }
          />
          {cfg.autoEmails && (
            <label className="mb-3 ml-12 flex items-center gap-2 text-sm">
              <span>Délai avant envoi</span>
              <input type="number" min={0} value={cfg.delay} onChange={(e) => set({ delay: Number(e.target.value) })} className="min-h-[44px] w-24 rounded border px-3" />
              <span>heures</span>
            </label>
          )}

          <Switch
            id="autoAccept"
            checked={cfg.autoAccept}
            disabled={!cfg.autoEmails || !wl}
            onChange={(v) => set({ autoAccept: v })}
            label="Accepter automatiquement les demandes « Oui »"
            help={
              !wl
                ? "Indisponible : activez d'abord la gestion des listes d'attente, la recommandation en dépend."
                : !cfg.autoEmails
                ? "Indisponible : activez d'abord l'envoi automatique des e-mails de décision, sans quoi la famille resterait sans réponse."
                : "Seules les demandes recommandées « Oui » sont acceptées, dans l'ordre de priorité. « Peut-être » et « Non » restent à décider à la main. Les doublons possibles (sauf la plus ancienne demande d'un même enfant) et les demandes hors des tranches d'âge sont laissés de côté."
            }
          />
          {cfg.autoAccept && (
            <label className="mb-3 ml-12 flex flex-wrap items-center gap-2 text-sm">
              <span>À partir du</span>
              <input type="date" value={cfg.acceptFrom} onChange={(e) => set({ acceptFrom: e.target.value })} className="min-h-[44px] rounded border px-3" />
              <span className="text-xs text-slate-500">Avant cette date, rien n'est accepté automatiquement.</span>
            </label>
          )}
          <Btn kind="primary" onClick={saveAutomation}>Enregistrer</Btn>
        </div>

        <div className={`mt-4 ${wl ? "" : "opacity-50"}`} aria-disabled={!wl}>
          <div className="mb-1 text-sm font-medium">Seuils de la recommandation et du niveau public</div>
          {!wl && <div className="mb-2 text-xs text-slate-500">Désactivés tant que la gestion des listes d'attente est coupée. Les valeurs enregistrées sont conservées.</div>}
          <DualSlider yesTo={cfg.yesTo} maybeTo={cfg.maybeTo} disabled={!wl} onChange={(p) => set(p)} />
          <div className="mt-3 grid gap-3 md:grid-cols-2">
            <label className="text-sm">
              <span className="mb-1 block text-slate-600">Oui jusqu'à … % de places occupées</span>
              <input type="number" disabled={!wl} min={1} max={cfg.maybeTo - 1} value={cfg.yesTo} onChange={(e) => set({ yesTo: Math.max(1, Math.min(Number(e.target.value), cfg.maybeTo - 1)) })} className="min-h-[44px] w-full rounded border px-3" />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-slate-600">Peut-être jusqu'à … % de places occupées</span>
              <input type="number" disabled={!wl} min={cfg.yesTo + 1} max={SLIDER_MAX} value={cfg.maybeTo} onChange={(e) => set({ maybeTo: Math.min(SLIDER_MAX, Math.max(Number(e.target.value), cfg.yesTo + 1)) })} className="min-h-[44px] w-full rounded border px-3" />
            </label>
          </div>
          {cfg.maybeTo > 100 && (
            <div className="mt-2 text-xs text-slate-600">
              Au-delà de 100 %, vous acceptez plus de monde que de places en comptant sur les départs non annoncés et sur les inscrits qui
              ne viendront finalement pas.
            </div>
          )}
          <div className="mt-3 rounded border bg-white p-3 text-sm">
            <label className="mb-2 flex items-center gap-2">
              Exemple avec une capacité de
              <input type="number" disabled={!wl} min={1} max={99} value={exampleCap} onChange={(e) => setExampleCap(Math.max(1, Number(e.target.value)))} className="min-h-[44px] w-20 rounded border px-2" />
              places
            </label>
            <ul className="space-y-1">
              <li>
                <Pill cls={LEVEL.yes.cls}>Oui</Pill> tant qu'il y a <b>{exYes <= 0 ? "0 place déjà occupée" : `au plus ${exYes} places déjà occupées`}</b>
              </li>
              <li>
                <Pill cls={LEVEL.maybe.cls}>Peut-être</Pill> quand il y a <b>{exMaybe === exYes + 1 ? `${exMaybe} places déjà occupées` : `de ${exYes + 1} à ${exMaybe} places déjà occupées`}</b>
              </li>
              <li>
                <Pill cls={LEVEL.no.cls}>Non</Pill> à partir de <b>{exMaybe + 1} places déjà occupées</b>
                {exampleCap - exMaybe - 1 > 0
                  ? ` (${exampleCap - exMaybe - 1} place${exampleCap - exMaybe - 1 > 1 ? "s" : ""} libre${exampleCap - exMaybe - 1 > 1 ? "s" : ""} ou moins)`
                  : exampleCap - exMaybe - 1 === 0
                  ? " (l'année est complète)"
                  : ` (${exMaybe + 1 - exampleCap} de plus que la capacité)`}
              </li>
            </ul>
            <div className="mt-2 text-xs text-slate-500">
              Les familles voient la même chose sous les noms « Disponible », « Limitée » et « Complet ».
            </div>
          </div>
          <div className="mt-3"><Btn kind="primary" disabled={!wl} onClick={() => flash("Seuils enregistrés.")}>Enregistrer</Btn></div>
        </div>

        <div className="mt-5">
          <div className="mb-1 text-sm font-medium">Capacités</div>
          <p className="mb-2 text-xs text-slate-500">
            Une case vide veut dire <b>pas de limite</b> ; pour fermer une branche aux inscriptions, indiquez <b>0</b>.
          </p>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[420px] text-sm">
              <thead className="text-left text-xs text-slate-500">
                <tr>
                  <th className="py-1">Branche · année</th>
                  <th>Capacité</th>
                </tr>
              </thead>
              <tbody>
                {SLOTS.map((s) => (
                  <tr key={s.id} className="border-t">
                    <td className="py-1">{s.label}</td>
                    <td>
                      <input defaultValue={s.cap ?? ""} placeholder="sans limite" className="min-h-[44px] w-28 rounded border px-3" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="mt-3"><Btn kind="primary" onClick={() => flash("Capacités enregistrées.")}>Enregistrer</Btn></div>
        </div>
      </Card>

      <Card title="Priorités">
        <p className="mb-1 text-sm text-slate-600">
          Quand plusieurs demandes se disputent les places d'une année de parcours, elles sont acceptées dans cet ordre : d'abord celles de
          « Toujours Oui », puis les autres selon le classement, jusqu'au nombre de places{wl ? "" : " (sans effet tant que les listes d'attente ne sont pas gérées)"}.
        </p>
        <PriorityLists order={order} setOrder={setOrder} mobile={mobile} />
        <div className="mt-3"><Btn kind="primary" onClick={() => flash("Priorités enregistrées.")}>Enregistrer</Btn></div>
      </Card>

      <Card title="Copie des e-mails d'inscription">
        <Switch
          id="cc"
          checked={cfg.ccOn}
          onChange={(v) => set({ ccOn: v })}
          label="Mettre une adresse en copie"
          help="S'applique à l'accusé de réception, à l'acceptation et au refus, qu'ils partent à la main ou automatiquement, renvois compris."
        />
        <label className={`block text-sm ${cfg.ccOn ? "" : "opacity-50"}`}>
          <span className="mb-1 block text-slate-600">Adresse e-mail en copie</span>
          <input
            disabled={!cfg.ccOn}
            value={cfg.ccAddr}
            placeholder="info@exemple.be (adresse de l'unité)"
            onChange={(e) => set({ ccAddr: e.target.value })}
            className={`min-h-[44px] w-full rounded border px-3 ${cfg.ccOn && cfg.ccAddr && !/^\S+@\S+\.\S+$/.test(cfg.ccAddr) ? "border-red-500" : ""}`}
          />
          {cfg.ccOn && cfg.ccAddr && !/^\S+@\S+\.\S+$/.test(cfg.ccAddr) && (
            <span className="mt-1 block text-xs text-red-700">Cette adresse n'est pas valide.</span>
          )}
          <span className="mt-1 block text-xs text-slate-500">Laissée vide, c'est l'adresse par défaut de l'unité qui est utilisée.</span>
        </label>
        <div className="mt-3"><Btn kind="primary" onClick={() => flash("Réglages enregistrés.")}>Enregistrer</Btn></div>
      </Card>
    </>
  );

  /* ------------------------------------------------------- request detail */

  const [mailScenario, setMailScenario] = useState("planned");
  const RequestDetail = () => {
    const r = REQUESTS.find((x) => x.id === detailId);
    const slot = SLOTS.find((s) => s.id === r.slot);
    const decision = r.status === "refused" ? "refus" : "acceptation";
    const scenarios = {
      none: { head: "Non planifié", tone: "bg-slate-100 text-slate-700", note: "L'envoi automatique est désactivé : l'e-mail s'envoie à la main.", actions: ["now"], history: [] },
      planned: { head: `Prévu le ${plusHours(cfg.delay)}`, tone: "bg-sky-100 text-sky-800", note: "La famille voit encore « en attente ». Changer la décision d'ici là annule cet envoi.", actions: ["now"], history: [[TODAY + " 15:00", "Planifié", "Décision prise par Marie Dupont"]] },
      sent: { head: "Envoyé le 09/10 à 14:32", tone: "bg-emerald-100 text-emerald-800", note: "La famille voit la décision.", actions: ["again"], history: [["09/10 14:32", "Envoyé", "Planifié le 08/10 à 14:30"], ["08/10 14:30", "Planifié", ""]] },
      failed: { head: "Échec le 08/10 à 14:32", tone: "bg-red-100 text-red-800", note: "L'e-mail n'a pas pu être envoyé. La famille voit toujours « en attente ».", actions: ["retry"], history: [["08/10 14:32", "Échec", "Réessai possible"], ["07/10 14:30", "Planifié", ""]] },
      cancelled: { head: "Annulé suite à un changement de décision", tone: "bg-amber-100 text-amber-800", note: "Un nouvel e-mail sera planifié pour la nouvelle décision.", actions: [], history: [["10/10 09:12", "Annulé", "Décision changée avant l'envoi"], ["09/10 14:30", "Planifié", ""]] },
      missing: { head: "Gabarit à rédiger", tone: "bg-red-100 text-red-800", note: "Le corps de l'e-mail n'a pas encore été rédigé : rien ne peut partir.", actions: [], history: [] },
    };
    const sc = scenarios[mailScenario];
    return (
      <>
        <Card
          title={`${r.child} — demande #${r.id}`}
          right={<Pill cls={STATUS_STYLE[r.status]}>{STATUS_LABEL[r.status]}</Pill>}
        >
          <div className="grid gap-2 text-sm md:grid-cols-2">
            <div><span className="text-slate-500">Créneau</span><br />{slot ? slot.label : "hors tranches"}</div>
            <div><span className="text-slate-500">Section prévue</span><br />{r.section}</div>
            <div><span className="text-slate-500">Reçue le</span><br />{r.date}</div>
            <div><span className="text-slate-500">Fratrie dans l'unité</span><br />{r.sib ? "Oui" : "Non"}</div>
          </div>
          <div className="mt-3 flex flex-wrap gap-2">
            {r.status === "pending" && (<><Btn kind="success">Accepter</Btn><Btn kind="danger">Refuser</Btn><Btn>Retirer</Btn></>)}
            {r.status === "accepted" && (<><Btn kind="danger">Refuser</Btn><Btn>Revenir en attente</Btn></>)}
            {r.status === "refused" && <Btn>Revenir en attente</Btn>}
          </div>
        </Card>

        <Card title="Note interne">
          <textarea defaultValue="Appeler les parents avant d'accepter." className="min-h-[88px] w-full rounded border p-3 text-sm" />
          <div className="mt-2 flex items-center gap-3">
            <Btn kind="primary" onClick={() => flash("Note enregistrée.")}>Enregistrer la note</Btn>
            <span className="text-xs text-slate-500">Enregistrer la note n'envoie jamais d'e-mail.</span>
          </div>
        </Card>

        <Card
          title="E-mail de décision"
          right={
            <select value={mailScenario} onChange={(e) => setMailScenario(e.target.value)} className="min-h-[44px] rounded border px-2 text-xs" aria-label="Situation à visualiser">
              <option value="none">Situation : non planifié</option>
              <option value="planned">Situation : prévu</option>
              <option value="sent">Situation : envoyé</option>
              <option value="failed">Situation : échec</option>
              <option value="cancelled">Situation : annulé</option>
              <option value="missing">Situation : gabarit à rédiger</option>
            </select>
          }
        >
          <div className="mb-1 text-xs text-slate-500">E-mail d'{decision}</div>
          <div className="mb-2"><Pill cls={sc.tone}>{sc.head}</Pill></div>
          <p className="mb-3 text-sm text-slate-600">{sc.note}</p>
          <div className="mb-3 flex flex-wrap gap-2">
            {sc.actions.includes("now") && <Btn kind="primary">Envoyer maintenant</Btn>}
            {sc.actions.includes("retry") && <Btn kind="primary">Réessayer</Btn>}
            {sc.actions.includes("again") && <Btn>Renvoyer l'e-mail</Btn>}
          </div>
          {sc.history.length > 0 && (
            <>
              <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Historique</div>
              <ul className="divide-y rounded border text-sm">
                {sc.history.map(([d, s, n]) => (
                  <li key={d + s} className="flex flex-wrap gap-2 px-3 py-2">
                    <span className="w-32 text-slate-500">{d}</span>
                    <span className="font-medium">{s}</span>
                    <span className="text-slate-500">{n}</span>
                  </li>
                ))}
              </ul>
            </>
          )}
        </Card>
      </>
    );
  };

  /* ------------------------------------------------------------ dialogue */

  /* --------------------------------------------------------------- rendu */

  const ConfirmModal = () => (
    <Modal
      title={confirm.title}
      onClose={() => setConfirm(null)}
      footer={
        <>
          <Btn onClick={() => setConfirm(null)}>Annuler</Btn>
          <Btn kind="primary" onClick={() => { const ok = confirm.onOk; setConfirm(null); ok ? ok() : flash("Fait."); }}>
            Confirmer
          </Btn>
        </>
      }
    >
      <ul className="space-y-2 text-sm">
        {confirm.lines.map((l) => (
          <li key={l}>{l}</li>
        ))}
      </ul>
      {confirm.bulk && (
        <label className="mt-3 block text-sm">
          <span className="mb-1 block text-slate-600">Motif du refus</span>
          <select className="min-h-[44px] w-full rounded border px-2" defaultValue="">
            <option value="">Choisir…</option>
            <option>Pas de place</option>
            <option>Autre</option>
          </select>
        </label>
      )}
    </Modal>
  );

  return (
    <div className="min-h-screen bg-slate-100 p-3 text-slate-800">
      <div className={`mx-auto ${mobile ? "max-w-[390px]" : "max-w-[980px]"}`}>
        <div className="mb-3 flex flex-wrap items-center gap-2 rounded border border-dashed border-slate-400 bg-white p-2 text-xs">
          <span className="font-semibold">Maquette</span>
          <label className="flex items-center gap-1">
            <input type="checkbox" checked={mobile} onChange={(e) => setMobile(e.target.checked)} /> Aperçu mobile
          </label>
          <button
            className="rounded border px-2 py-1"
            onClick={() => setTpl(decisionTplReady ? { ...tpl, accepted: "missing", refused: "missing" } : { ...tpl, accepted: "custom", refused: "custom" })}
          >
            {decisionTplReady ? "Simuler : gabarits à rédiger" : "Simuler : gabarits rédigés"}
          </button>
        </div>

        <nav aria-label="Fil d'Ariane" className="mb-1 text-xs text-slate-500">
          {crumbs.map((c, i) => (
            <span key={c}>
              {i > 0 && " / "}
              {c}
            </span>
          ))}
        </nav>
        <h1 className="mb-2 text-xl font-semibold">Formulaire d'inscription</h1>

        <div className="mb-4 flex overflow-x-auto border-b">
          {pages.map(([k, l]) => (
            <button
              key={k}
              onClick={() => { setPage(k); setDetailId(null); }}
              className={`min-h-[44px] shrink-0 border-b-2 px-4 text-sm ${pickerCurrent === k ? "border-blue-600 font-semibold text-blue-700" : "border-transparent text-slate-600"}`}
            >
              {l}
            </button>
          ))}
        </div>

        {page === "dashboard" && Dashboard()}
        {page === "requests" && Requests()}
        {page === "templates" && Templates()}
        {page === "settings" && Settings()}
        {page === "detail" && RequestDetail()}

        {deciding && <DecideDialog r={deciding} cfg={cfg} order={order} onClose={() => setDeciding(null)} flash={flash} />}
        {confirm && ConfirmModal()}
        {toast && (
          <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded bg-slate-800 px-4 py-2 text-sm text-white">{toast}</div>
        )}
      </div>
    </div>
  );
}
