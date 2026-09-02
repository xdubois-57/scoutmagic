import React, { useState } from "react";
import { Download, Info, Mail, Phone, Printer } from "lucide-react";

/* ==========================================================================
 * ScoutMagic — Trombinoscope imprimable (PDF A4)
 *
 * Page 1  : l'annuaire — un responsable par section, avec ses contacts.
 *           C'est la page qu'un parent affiche sur son frigo.
 * Page 2+ : une par section — les portraits de tout le staff.
 *
 * Rendu ici à l'échelle : 210 × 297 mm, marges 15 mm, comme
 * Core\Pdf\PosterPdfService les pose déjà.
 *
 * dompdf ne connaît ni flexbox ni grid : la composition réelle se fera en
 * tableaux. La maquette montre le résultat, pas la technique.
 *
 * Noms de personnes inventés.
 * ======================================================================= */

const BLUE = "#0d6efd";

/* Couleurs de branche : SectionService::colorForSection() est la source
   unique du site — Staffs, trombinoscope, calendrier, statistiques. */
const SECTIONS = [
  { name: "Baladins 1", color: "#0d6efd", email: "baladins1@sv025.be", lead: { first: "Lucie", last: "Crijns", totem: "Alouette", tel: "0475 12 34 56", mail: "baladins1@sv025.be" } },
  { name: "Baladins 2", color: "#0d6efd", email: "baladins2@sv025.be", lead: { first: "Célian", last: "Dockx", totem: "Fennec", tel: "0478 22 11 09", mail: "baladins2@sv025.be" } },
  { name: "Louveteaux 1", color: "#198754", email: "louveteaux1@sv025.be", lead: { first: "Antonin", last: "Grandjean", totem: "Chacal", tel: "0496 88 41 20", mail: "louveteaux1@sv025.be" } },
  { name: "Louveteaux 2", color: "#198754", email: "louveteaux2@sv025.be", lead: null },
  { name: "Éclaireurs 1", color: "#fd7e14", email: "eclaireurs1@sv025.be", lead: { first: "Charlie", last: "Laloux", totem: "Onagre", tel: "0471 55 02 87", mail: "eclaireurs1@sv025.be" } },
  { name: "Pionniers 1", color: "#dc3545", email: "pionniers1@sv025.be", lead: { first: "Clara", last: "Vandersmissen", totem: "Ibis", tel: "0499 71 63 44", mail: "pionniers1@sv025.be" } },
  { name: "Staff d'U", color: "#6f42c1", email: "unite@sv025.be", lead: { first: "Xavier", last: "Dubois", totem: "Bouquetin", tel: "0475 90 12 33", mail: "unite@sv025.be" } },
];

const STAFF = [
  { first: "Antonin", last: "Grandjean", totem: "Chacal", role: "Responsable", tel: "0496 88 41 20", mail: "antonin@sv025.be" },
  { first: "Mattis", last: "Wergifosse", totem: "Ocelot", role: "Animateur", tel: "0470 11 22 33", mail: "mattis@sv025.be" },
  { first: "Lola", last: "Pierreux", totem: "Sittelle", role: "Animatrice", tel: "0472 44 55 66", mail: "lola@sv025.be" },
  { first: "Théophile", last: "Ketelslegers", totem: "Markhor", role: "Animateur", tel: "0473 77 88 99", mail: "theo@sv025.be" },
  { first: "Juliette", last: "Leclercq", totem: "Harfang", role: "Animatrice", tel: "0474 12 90 45", mail: "juliette@sv025.be" },
  { first: "Antoine", last: "Leclercq", totem: "Guanaco", role: "Animateur", tel: "0476 33 21 08", mail: "antoine@sv025.be" },
];

/* Le nombre de sections varie d'une unité à l'autre. La page annuaire doit
   tenir sur une feuille quel que soit ce nombre : la densité se choisit par
   paliers plutôt qu'en mise à l'échelle continue — plus robuste sous dompdf,
   et testable. */
function density(n) {
  if (n <= 6) return { cols: 2, avatar: 56, band: 10, name: 13, totem: 15, sub: 12, contact: 11, pad: 8, gap: 12 };
  if (n <= 10) return { cols: 2, avatar: 44, band: 8, name: 12, totem: 14, sub: 11, contact: 10, pad: 6, gap: 8 };
  return { cols: 3, avatar: 36, band: 6, name: 11, totem: 12, sub: 10, contact: 9, pad: 5, gap: 6 };
}

function buildSections(n) {
  const out = [];
  for (let i = 0; i < n; i++) {
    const base = SECTIONS[i % SECTIONS.length];
    out.push(i < SECTIONS.length ? base : { ...base, name: base.name + " " + (Math.floor(i / SECTIONS.length) + 1), email: "section" + (i + 1) + "@sv025.be" });
  }
  return out;
}

export default function Mockup() {
  const [contacts, setContacts] = useState(true);
  const [page, setPage] = useState("annuaire");
  const [count, setCount] = useState(7);
  const sections = buildSections(count);

  return (
    <div className="min-h-screen bg-slate-300 font-sans text-slate-900 antialiased">
      <style>{`.fw-num{font-variant-numeric:tabular-nums}`}</style>

      <div className="bg-slate-800 px-4 py-2.5">
        <div className="mx-auto flex max-w-4xl flex-wrap items-center gap-2">
          <span className="mr-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">Maquette</span>
          {[
            ["annuaire", "Page 1 — annuaire"],
            ["section", "Page 4 — Louveteaux 1"],
          ].map(([id, l]) => (
            <button
              key={id}
              onClick={() => setPage(id)}
              className={`rounded px-2.5 py-1 text-xs font-medium ${page === id ? "bg-white text-slate-900" : "bg-white/10 text-slate-300"}`}
            >
              {l}
            </button>
          ))}
          <span className="ml-auto text-[11px] font-semibold uppercase tracking-wider text-slate-400">Sections</span>
          {[4, 7, 10, 14].map((n) => (
            <button
              key={n}
              onClick={() => setCount(n)}
              className={`rounded px-2 py-1 text-xs font-medium ${count === n ? "bg-white text-slate-900" : "bg-white/10 text-slate-300"}`}
            >
              {n}
            </button>
          ))}
          <label className="flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-300">
            <input type="checkbox" checked={contacts} onChange={(e) => setContacts(e.target.checked)} className="h-4 w-4" />
            Afficher les contacts
          </label>
        </div>
      </div>

      {/* L'écran d'où l'on génère */}
      <div className="mx-auto max-w-4xl px-4 pt-5">
        <div className="rounded-lg border border-slate-200 bg-white px-4 py-3">
          <div className="flex flex-wrap items-center gap-3">
            <div className="min-w-0 flex-1">
              <p className="text-sm font-semibold">Trombinoscope 2025-2026</p>
              <p className="text-xs text-slate-500">
{count + 1} pages — l'annuaire, puis une page par section
                {contacts ? "" : " — contacts masqués par le réglage"}
              </p>
            </div>
            <button
              className="inline-flex items-center gap-1.5 rounded px-3 py-2.5 text-sm font-medium text-white"
              style={{ backgroundColor: BLUE }}
            >
              <Download className="h-4 w-4" />
              Télécharger le PDF
            </button>
          </div>
          <div className="mt-2.5 flex items-start gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs leading-relaxed text-slate-600">
            <Printer className="mt-px h-4 w-4 shrink-0 opacity-60" />
            <div>
              Personne n'a besoin de tout imprimer : la page 1 et celle de votre section suffisent. Chaque
              page porte son nom en tête pour qu'on la retrouve dans la boîte d'impression.
            </div>
          </div>
        </div>
      </div>

      {/* La feuille A4, à l'échelle */}
      <div className="flex justify-center px-4 py-6">
        <div
          className="bg-white shadow-2xl"
          style={{ width: 620, height: 877, padding: 44 }}
        >
          {page === "annuaire" ? <Directory contacts={contacts} sections={sections} /> : <SectionPage contacts={contacts} />}
        </div>
      </div>

      <div className="mx-auto max-w-4xl px-4 pb-10">
        <div className="flex items-start gap-2 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-xs leading-relaxed text-slate-600">
          <Info className="mt-px h-4 w-4 shrink-0 opacity-60" />
          <div>
            A4 à l'échelle, marges de 15 mm — celles que <strong>PosterPdfService</strong> pose déjà.
            Un PDF plutôt qu'une feuille de style d'impression : c'est un fichier qu'on joint à un
            e-mail, et les fonds colorés y survivent, alors qu'un navigateur les supprime par défaut.
          </div>
        </div>
      </div>
    </div>
  );
}

/* ===================== PAGE 1 — L'ANNUAIRE ============================== */

function Directory({ contacts, sections }) {
  const d = density(sections.length);

  return (
    <div className="flex h-full flex-col">
      <header className="border-b-2 border-slate-900 pb-2">
        <h1 className="text-2xl font-bold tracking-tight">Qui contacter</h1>
        <p className="text-sm text-slate-600">
          Unité SV025 Ottignies — Petit Ry · Année scoute 2025-2026
        </p>
      </header>

      {/* Densité par paliers : la page ne déborde jamais, quel que soit le
          nombre de sections. Deux colonnes jusqu'à dix, trois au-delà. */}
      <div
        className="mt-3 grid flex-1 content-start"
        style={{ gridTemplateColumns: `repeat(${d.cols}, minmax(0, 1fr))`, gap: d.gap }}
      >
        {sections.map((s) => (
          <div key={s.name} className="flex items-stretch overflow-hidden rounded border border-slate-300">
            <div className="shrink-0" style={{ width: d.band, backgroundColor: s.color }} />
            <div className="flex min-w-0 flex-1 items-center" style={{ gap: d.pad + 2, padding: d.pad }}>
              {s.lead ? (
                <>
                  <span
                    className="grid shrink-0 place-items-center rounded-full font-bold text-white"
                    style={{ width: d.avatar, height: d.avatar, backgroundColor: s.color, fontSize: d.avatar / 3.6 }}
                  >
                    {s.lead.first[0]}
                    {s.lead.last[0]}
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="font-bold uppercase leading-tight tracking-wide" style={{ color: s.color, fontSize: d.name }}>
                      {s.name}
                    </p>
                    <p className="font-bold leading-tight" style={{ fontSize: d.totem }}>
                      {s.lead.totem}
                    </p>
                    <p className="leading-tight text-slate-600" style={{ fontSize: d.sub }}>
                      {s.lead.first} {s.lead.last}
                    </p>
                    {contacts && (
                      <div className="fw-num mt-0.5 leading-snug" style={{ fontSize: d.contact }}>
                        <p className="font-semibold">{s.lead.tel}</p>
                        {/* Jamais tronquée : une adresse coupée ne sert à rien. */}
                        <p className="break-all text-slate-600">{s.lead.mail}</p>
                      </div>
                    )}
                  </div>
                </>
              ) : (
                <>
                  <span
                    className="grid shrink-0 place-items-center rounded-full border-2 border-dashed border-slate-300 text-slate-400"
                    style={{ width: d.avatar, height: d.avatar, fontSize: d.avatar / 3 }}
                  >
                    ?
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="font-bold uppercase leading-tight tracking-wide" style={{ color: s.color, fontSize: d.name }}>
                      {s.name}
                    </p>
                    <p className="mt-1 italic leading-tight text-slate-500" style={{ fontSize: d.sub }}>
                      Responsable non désigné
                    </p>
                  </div>
                </>
              )}
            </div>
          </div>
        ))}
      </div>

      {/* Les adresses de section sont organisationnelles et stockées en clair
          (design.md) : elles ne suivent pas le réglage des coordonnées
          personnelles, et ne sont jamais tronquées. */}
      <footer className="mt-3 shrink-0 border-t-2 border-slate-900 pt-2">
        <p className="text-[11px] font-bold uppercase tracking-wide text-slate-700">Écrire à une section</p>
        <div
          className="mt-1 grid gap-x-3 gap-y-0.5"
          style={{ gridTemplateColumns: `repeat(${sections.length > 8 ? 3 : 2}, minmax(0, 1fr))` }}
        >
          {sections.map((x) => (
            <p key={x.name} className="fw-num leading-snug" style={{ fontSize: sections.length > 10 ? 9 : 10.5 }}>
              <span className="font-semibold" style={{ color: x.color }}>
                {x.name}
              </span>{" "}
              <span className="break-all text-slate-600">{x.email}</span>
            </p>
          ))}
        </div>
        <p className="mt-2 text-[10px] text-slate-500">
          www.sv025.be · document généré le 2 septembre 2026
          {!contacts && " · coordonnées personnelles masquées par le réglage"}
        </p>
      </footer>
    </div>
  );
}

/* ===================== PAGE DE SECTION ================================== */

function SectionPage({ contacts }) {
  const s = SECTIONS[2];
  return (
    <>
      {/* Le nom de la section en tête : c'est ce qui permet de retrouver
          la bonne page dans la boîte d'impression. */}
      <header className="flex items-center gap-3 border-b-2 pb-2" style={{ borderColor: s.color }}>
        <div className="h-9 w-2 rounded" style={{ backgroundColor: s.color }} />
        <div className="min-w-0 flex-1">
          <h1 className="text-2xl font-bold tracking-tight" style={{ color: s.color }}>
            {s.name}
          </h1>
          <p className="text-sm text-slate-600">Louveteaux · 6 animateurs · 2025-2026</p>
        </div>
        <p className="shrink-0 text-xs text-slate-400">page 4 / 8</p>
      </header>

      <div className="mt-4 grid grid-cols-3 gap-3">
        {STAFF.map((p) => (
          <div key={p.first + p.last} className="overflow-hidden rounded border border-slate-300">
            <div className="grid h-24 place-items-center bg-slate-100">
              <span className="grid h-16 w-16 place-items-center rounded-full text-lg font-bold text-white" style={{ backgroundColor: s.color }}>
                {p.first[0]}
                {p.last[0]}
              </span>
            </div>
            <div className="px-2 py-1.5">
              <p className="truncate text-sm font-bold">{p.totem}</p>
              <p className="truncate text-xs text-slate-600">
                {p.first} {p.last}
              </p>
              {p.role === "Responsable" && (
                <p className="mt-0.5 inline-block rounded px-1 py-0.5 text-[10px] font-bold uppercase text-white" style={{ backgroundColor: s.color }}>
                  Responsable
                </p>
              )}
              {contacts && (
                <div className="fw-num mt-1 border-t border-slate-200 pt-1 text-[10px] leading-snug text-slate-600">
                  <p className="flex items-center gap-1">
                    <Phone className="h-2.5 w-2.5 shrink-0" />
                    {p.tel}
                  </p>
                  <p className="flex items-start gap-1">
                    <Mail className="mt-px h-2.5 w-2.5 shrink-0" />
                    <span className="break-all">{p.mail}</span>
                  </p>
                </div>
              )}
            </div>
          </div>
        ))}
      </div>

      <footer className="mt-5 border-t-2 pt-2" style={{ borderColor: s.color }}>
        <p className="text-sm">
          <span className="font-bold uppercase tracking-wide" style={{ color: s.color }}>
            Écrire aux {s.name}
          </span>{" "}
          <span className="fw-num break-all font-semibold">{s.email}</span>
        </p>
        <p className="mt-1 text-[10px] text-slate-500">
          Unité SV025 Ottignies — Petit Ry · www.sv025.be
        </p>
      </footer>
    </>
  );
}
