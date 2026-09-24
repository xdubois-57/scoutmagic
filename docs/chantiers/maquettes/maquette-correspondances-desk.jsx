import React, { useState } from "react";

/**
 * ScoutMagic — maquette : correspondances Desk non résolues (issue #356).
 *
 * Deux écrans, deux publics :
 *   — « Instance centrale » : la vue globale du mainteneur, dérivée des
 *     charges de statistiques déjà reçues. Rien n'y est catalogué sauf ce
 *     qui ne se recalcule pas : la première apparition, la notification
 *     déjà envoyée, et le fait d'avoir écarté une valeur.
 *   — « Correspondances Desk » : ce que voit un chef d'unité sur son propre
 *     site, où il peut agir tout de suite sans attendre une version.
 *
 * Le produit est en Bootstrap 5 ; Tailwind n'est ici que pour dessiner vite.
 */

const KINDS = {
  function: { label: "Fonction", where: "MappingResolver::resolveFunction()" },
  branch: { label: "Branche", where: "AgeBranchRepository::canonicalSortOrder()" },
  fee: { label: "Tarif", where: "FederalScaleLookupService::FIELD_BY_CATEGORY" },
  header: { label: "En-tête CSV", where: "DeskCsvParser::EXPECTED_HEADERS" },
};

const GAPS = [
  { id: 1, kind: "function", raw: "Animateur Nutons", units: 7,
    unitNames: ["25e SV", "12e Uccle", "3e Wavre", "…"],
    since: "3 sept.", last: "aujourd'hui", version: "2.4.0", ignored: false,
    effect: "Créée avec le rôle le plus bas : ces animateurs n'ont aucun accès au staff." },
  { id: 2, kind: "branch", raw: "Nutons", units: 7, unitNames: ["25e SV", "12e Uccle", "…"],
    since: "3 sept.", last: "aujourd'hui", version: "2.4.0", ignored: false,
    effect: "Classée 99 : aucun logo, et un rang arbitraire dans tous les sélecteurs." },
  { id: 3, kind: "fee", raw: "reduit_fratrie", units: 3, unitNames: ["8e Namur", "…"],
    since: "18 août", last: "hier", version: "2.3.1", ignored: false,
    effect: "Aucun montant du barème fédéral ne lui correspond." },
  { id: 4, kind: "header", raw: "Courriel", units: 1, unitNames: ["41e Liège"],
    since: "22 sept.", last: "aujourd'hui", version: "2.4.0", ignored: false,
    effect: "Colonne inattendue : l'import s'arrête. Bloquant pour cette unité." },
  { id: 5, kind: "function", raw: "Animateur Baladinss", units: 1, unitNames: ["7e Jette"],
    since: "1er sept.", last: "il y a 12 jours", version: "2.3.1", ignored: true,
    effect: "Créée avec le rôle le plus bas." },
];

const UNIT_GAPS = [
  { kind: "function", raw: "Animateur Nutons", n: 3,
    says: "3 personnes ont cette fonction. Elles n'ont aucun accès au-delà de l'espace membres tant que vous ne leur donnez pas un rôle.",
    action: "Donner un rôle" },
  { kind: "branch", raw: "Nutons", n: 1,
    says: "Le site ne reconnaît pas cette branche : elle n'a pas de logo et se range au hasard dans les listes.",
    action: "Choisir un logo" },
];

export default function MaquetteCorrespondances() {
  const [view, setView] = useState("central");
  const [gaps, setGaps] = useState(GAPS);
  const [showIgnored, setShowIgnored] = useState(false);

  const toggleIgnore = (id) => setGaps((g) => g.map((x) => x.id === id ? { ...x, ignored: !x.ignored } : x));
  const visible = gaps.filter((g) => showIgnored || !g.ignored).sort((a, b) => b.units - a.units);
  const newCount = gaps.filter((g) => !g.ignored).length;

  return (
    <div className="min-h-screen bg-slate-100 p-4 text-slate-800">
      <div className="mx-auto" style={{ maxWidth: 720 }}>
        <div className="mb-4 flex flex-wrap gap-2">
          {[["central", "Instance centrale", "le mainteneur"], ["unit", "Correspondances Desk", "un chef d'unité"]].map(([k, l, s]) => (
            <button key={k} onClick={() => setView(k)}
              className={`rounded border px-3 py-2 text-xs ${view === k ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>
              {l}<span className="block text-slate-400">{s}</span>
            </button>
          ))}
        </div>

        {/* ═════════ INSTANCE CENTRALE ═════════ */}
        {view === "central" && (
          <>
            <div className="mb-1 text-xs text-slate-500">Configuration › Supervision</div>
            <h1 className="mb-3 text-xl font-semibold">Supervision</h1>

            <div className="mb-4 flex gap-2 overflow-x-auto pb-1">
              {["Tableau de bord", "Tickets", "Correspondances"].map((t) => (
                <span key={t}
                  className={`whitespace-nowrap rounded border px-3 py-2 text-xs ${t === "Correspondances" ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>
                  {t}
                </span>
              ))}
            </div>

            <h2 className="mb-1 text-base font-semibold">Correspondances Desk à corriger</h2>
            <p className="mb-4 text-xs leading-relaxed text-slate-600">
              Les valeurs que les installations remontent et que ce code ne reconnaît pas. Chaque
              ligne est une table du code à compléter dans une prochaine version. La liste se
              recalcule à chaque affichage : une valeur corrigée disparaît d'elle-même.
            </p>

            <div className="mb-3 flex items-center gap-3">
              <span className="text-xs text-slate-500">{newCount} à traiter</span>
              <label className="flex items-center gap-2 text-xs text-slate-600">
                <input type="checkbox" checked={showIgnored} onChange={(e) => setShowIgnored(e.target.checked)} />
                Montrer les valeurs écartées
              </label>
            </div>

            {visible.map((g) => (
              <div key={g.id} className={`mb-3 rounded-lg border bg-white ${g.ignored ? "opacity-60" : ""}`}>
                <div className="px-4 py-3">
                  <div className="flex flex-wrap items-baseline gap-2">
                    <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{KINDS[g.kind].label}</span>
                    <span className="flex-1 text-sm font-medium" style={{ fontFamily: "ui-monospace, monospace" }}>{g.raw}</span>
                    <span className={`text-xs ${g.units > 3 ? "font-semibold text-red-700" : "text-slate-500"}`}>
                      {g.units} unité{g.units > 1 ? "s" : ""}
                    </span>
                  </div>

                  <div className="mt-1 text-xs text-slate-600">{g.effect}</div>

                  <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                    <span>Depuis le {g.since}</span>
                    <span>Vue {g.last}</span>
                    <span>Plus ancienne version : {g.version}</span>
                  </div>
                  <div className="mt-1 text-xs text-slate-400">{g.unitNames.join(", ")}</div>
                  <div className="mt-1 text-xs text-slate-400" style={{ fontFamily: "ui-monospace, monospace" }}>
                    {KINDS[g.kind].where}
                  </div>

                  <button onClick={() => toggleIgnore(g.id)} className="mt-2 text-xs text-blue-600 underline">
                    {g.ignored ? "Réactiver" : "Écarter"}
                  </button>
                  {g.ignored && <span className="ml-2 text-xs text-slate-500">Écartée : une faute de frappe d'une seule unité.</span>}
                </div>
              </div>
            ))}

            <div className="rounded-lg border bg-white px-4 py-3 text-xs leading-relaxed text-slate-600">
              <b className="text-slate-800">« Plus ancienne version » évite de retraiter deux fois.</b>
              <div className="mt-1">
                Une valeur corrigée dans le code continue d'être remontée par les installations qui
                n'ont pas encore installé la version. Sans cette colonne, on la reprend en croyant
                l'avoir oubliée.
              </div>
              <div className="mt-2">
                Les noms de fonctions, de branches et de tarifs sont du vocabulaire fédéral, pas des
                données personnelles. Les noms de sections ne sont <b>pas</b> remontés : ils sont
                choisis par l'unité, n'ont aucune correspondance centrale à corriger, et
                l'identifient.
              </div>
            </div>
          </>
        )}

        {/* ═════════ CÔTÉ UNITÉ ═════════ */}
        {view === "unit" && (
          <>
            <div className="mb-1 text-xs text-slate-500">Configuration</div>
            <h1 className="mb-1 text-xl font-semibold">Correspondances Desk</h1>
            <p className="mb-4 text-xs text-slate-500">Fonctions · Sections · Branches</p>

            <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50">
              <div className="border-b border-amber-300 px-4 py-3 text-sm font-semibold">
                2 valeurs que le site ne connaît pas
              </div>
              <div className="px-4 py-3">
                <p className="mb-3 text-xs leading-relaxed text-slate-700">
                  Le dernier import a rencontré des valeurs pour lesquelles le site n'a pas de
                  correspondance. Il les a créées telles quelles, avec les réglages les plus
                  prudents. Vous pouvez les corriger ici sans attendre.
                </p>
                {UNIT_GAPS.map((u) => (
                  <div key={u.raw} className="border-b border-amber-200 py-2 last:border-0">
                    <div className="flex flex-wrap items-baseline gap-2">
                      <span className="rounded bg-white px-2 py-0.5 text-xs text-slate-600">{KINDS[u.kind].label}</span>
                      <span className="flex-1 text-sm font-medium">{u.raw}</span>
                      <span className="text-xs text-slate-500">{u.n} concerné{u.n > 1 ? "s" : ""}</span>
                    </div>
                    <div className="mt-1 text-xs leading-relaxed text-slate-700">{u.says}</div>
                    <button className="mt-1 text-xs text-blue-600 underline">{u.action}</button>
                  </div>
                ))}
                <p className="mt-3 text-xs text-slate-600">
                  Ces valeurs sont aussi signalées au mainteneur du site, pour qu'il les ajoute aux
                  versions suivantes. Seul le libellé est transmis.
                </p>
              </div>
            </div>

            <div className="rounded-lg border bg-white px-4 py-3">
              <div className="text-sm font-semibold">Fonctions</div>
              <div className="mt-1 text-xs text-slate-500">
                La suite de la page, inchangée : associer chaque fonction importée à un rôle du site.
              </div>
            </div>

            <div className="mt-4 rounded-lg border bg-white px-4 py-3 text-xs leading-relaxed text-slate-600">
              <b className="text-slate-800">Au journal, et dans le paquet de support</b>
              <div className="mt-1">
                Chaque création automatique laisse une entrée en niveau <i>info</i> — fonction créée,
                branche non reconnue, tarif sans barème — avec le seul libellé, jamais un nom de
                membre. C'est ce qui permet de comprendre après coup, quand un bug est rapporté et
                que les statistiques sont coupées.
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
