import React, { useState } from "react";

/**
 * ScoutMagic — maquette : module Documents (partage de documents d'unité).
 *
 * Deux écrans :
 *   — « Notre unité › Documents », publique, dont le contenu se filtre par
 *     le rôle du lecteur ;
 *   — « Espace chefs d'U › Documents », gestion (role_min: admin).
 *
 * La visibilité s'appuie sur `files.role_min`, imposée par FileAccessGuard
 * sur /files/{id} — rien de neuf. L'adresse partagée, elle, appartient au
 * module : /documents/{slug} redirige vers la version courante, pour qu'une
 * mise à jour ne tue pas les liens déjà distribués.
 *
 * Le produit est en Bootstrap 5 ; Tailwind n'est ici que pour dessiner vite.
 */

const VIEWERS = [
  { key: "public", label: "Visiteur", sub: "non identifié", role: 0 },
  { key: "member", label: "Parent / animé", sub: "identifié", role: 1 },
  { key: "chief", label: "Animateur", sub: "chief", role: 3 },
  { key: "unit", label: "Chef d'unité", sub: "admin", role: 4 },
];

// Les mêmes libellés que l'éditeur d'actualités, qui propose déjà cette
// visibilité en chips btn-check : un chef d'unité doit voir la même chose
// des deux côtés.
const LEVELS = {
  0: { label: "Public", cls: "bg-green-100 text-green-800", who: "tout le monde" },
  1: { label: "Membres connectés", cls: "bg-blue-100 text-blue-800", who: "les membres identifiés" },
  3: { label: "Animateurs", cls: "bg-violet-100 text-violet-800", who: "les animateurs et plus" },
  4: { label: "Chefs d'Unité", cls: "bg-amber-100 text-amber-800", who: "le Staff d'U seulement" },
  // « Lien direct » n'est PAS un échelon de l'échelle des rôles — c'est le
  // commentaire du schéma des actualités : ça veut dire « non listé », et ça
  // accorde l'accès à quiconque détient l'URL.
  9: { label: "Lien direct", cls: "bg-slate-200 text-slate-700", who: "quiconque a l'adresse, invisible de la liste" },
};

const DOCS = [
  { id: 1, slug: "reglement-interieur", title: "Règlement d'ordre intérieur",
    desc: "Les règles de vie de l'unité, validées en assemblée.",
    level: 0, kind: "PDF", size: "180 Ko", updated: "12 septembre 2026", versions: 3 },
  { id: 2, slug: "charte-des-parents", title: "Charte des parents",
    desc: "Ce que l'unité attend des familles, et ce qu'elles peuvent attendre d'elle.",
    level: 0, kind: "PDF", size: "96 Ko", updated: "3 mars 2026", versions: 1 },
  { id: 3, slug: "liste-materiel-camp", title: "Liste de matériel pour le camp",
    desc: "Ce qu'il faut mettre dans le sac, par branche.",
    level: 1, kind: "PDF", size: "240 Ko", updated: "2 juin 2026", versions: 5 },
  { id: 4, slug: "procedure-accident", title: "Procédure en cas d'accident",
    desc: "Qui appeler, dans quel ordre, et ce qu'il faut noter.",
    level: 3, kind: "PDF", size: "120 Ko", updated: "1er septembre 2026", versions: 2 },
  { id: 6, slug: "pv-ag-2026-a7f3c9", title: "PV de l'assemblée générale 2026",
    desc: "Envoyé aux parents par courriel ; ne figure pas dans la liste publique.",
    level: 9, kind: "PDF", size: "310 Ko", updated: "18 septembre 2026", versions: 1 },
  { id: 5, slug: "budget-unite", title: "Budget de l'unité",
    desc: "Le tableau présenté au conseil d'unité.",
    level: 4, kind: "Tableur", size: "64 Ko", updated: "20 septembre 2026", versions: 4 },
];

const HISTORY = [
  { v: 5, when: "2 juin 2026", by: "Sophie Martin", size: "240 Ko", current: true },
  { v: 4, when: "14 mai 2026", by: "Sophie Martin", size: "238 Ko" },
  { v: 3, when: "3 juin 2025", by: "Marc Dubois", size: "231 Ko" },
  { v: 2, when: "28 mai 2025", by: "Marc Dubois", size: "230 Ko" },
  { v: 1, when: "5 juin 2024", by: "Marc Dubois", size: "225 Ko" },
];

function Card({ children, tone }) {
  return <div className={`mb-3 rounded-lg border bg-white ${tone === "warn" ? "border-amber-300" : ""}`}>{children}</div>;
}

export default function MaquetteDocuments() {
  const [viewer, setViewer] = useState("public");
  const [view, setView] = useState("public");
  const [docs, setDocs] = useState(DOCS);
  const [openHistory, setOpenHistory] = useState(null);
  const [editing, setEditing] = useState(null);
  const [newFile, setNewFile] = useState(false);
  const [dragged, setDragged] = useState(null);
  const [overIndex, setOverIndex] = useState(null);

  function reorder(from, to) {
    setDocs((ds) => {
      if (from === to || from == null || to == null) return ds;
      const a = [...ds];
      const [moved] = a.splice(from, 1);
      a.splice(to, 0, moved);
      return a;
    });
  }

  const v = VIEWERS.find((x) => x.key === viewer);
  // Un document non listé n'apparaît jamais dans la liste, quel que soit le rôle.
  const visible = docs.filter((d) => d.level !== 9 && d.level <= v.role);

  return (
    <div className="min-h-screen bg-slate-100 p-4 text-slate-800">
      <div className="mx-auto" style={{ maxWidth: 660 }}>
        <div className="mb-3 flex flex-wrap gap-2">
          {[["public", "Page publique"], ["manage", "Gestion"]].map(([k, l]) => (
            <button key={k} onClick={() => setView(k)}
              className={`rounded border px-3 py-2 text-xs ${view === k ? "border-slate-800 bg-slate-800 text-white" : "border-slate-300 bg-white text-slate-600"}`}>{l}</button>
          ))}
        </div>

        {view === "public" && (
          <>
            <div className="mb-3 flex flex-wrap gap-2">
              {VIEWERS.map((x) => (
                <button key={x.key} onClick={() => setViewer(x.key)}
                  className={`rounded border px-3 py-2 text-xs ${viewer === x.key ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>
                  {x.label}<span className="block text-slate-400">{x.sub}</span>
                </button>
              ))}
            </div>

            <div className="mb-1 text-xs text-slate-500">Notre unité</div>
            <h1 className="mb-1 text-xl font-semibold">Documents</h1>
            <p className="mb-4 text-xs leading-relaxed text-slate-600">
              Les documents de l'unité, à consulter ou à télécharger.
            </p>

            {visible.map((d) => (
              <Card key={d.id}>
                <div className="flex items-start gap-3 px-4 py-3">
                  <div className="flex-1">
                    <div className="flex flex-wrap items-baseline gap-2">
                      <span className="text-sm font-medium">{d.title}</span>
                      {d.level > 0 && (
                        <span className={`rounded px-2 py-0.5 text-xs ${LEVELS[d.level].cls}`}>{LEVELS[d.level].label}</span>
                      )}
                    </div>
                    <div className="mt-1 text-xs text-slate-600">{d.desc}</div>
                    <div className="mt-1 text-xs text-slate-400">
                      {d.kind} · {d.size} · mis à jour le {d.updated}
                    </div>
                  </div>
                  <a href="#" onClick={(e) => e.preventDefault()}
                     aria-label={`Télécharger ${d.title}`} title={`Télécharger ${d.title}`}
                     className="flex shrink-0 items-center justify-center rounded border border-blue-600 text-blue-600"
                     style={{ width: 44, height: 44 }}>
                    ⭳
                  </a>
                </div>
              </Card>
            ))}

            {visible.length === 0 && (
              <Card>
                <div className="px-4 py-6 text-center">
                  <div className="text-sm">Aucun document public pour le moment.</div>
                  <div className="mt-1 text-xs leading-relaxed text-slate-600">
                    Certains documents sont réservés aux membres de l'unité. Connectez-vous pour voir
                    ceux qui vous concernent.
                  </div>
                  <button className="mt-3 rounded bg-blue-600 px-4 py-2 text-sm text-white">Se connecter</button>
                </div>
              </Card>
            )}

            {viewer !== "public" && visible.length < docs.length && (
              <p className="px-1 text-xs text-slate-500">
                {docs.length - visible.length} document{docs.length - visible.length > 1 ? "s" : ""} ne
                vous {docs.length - visible.length > 1 ? "sont" : "est"} pas destiné{docs.length - visible.length > 1 ? "s" : ""} et
                n'apparaî{docs.length - visible.length > 1 ? "ssent" : "t"} pas ici.
              </p>
            )}
          </>
        )}

        {view === "manage" && (
          <>
            <div className="mb-1 text-xs text-slate-500">Espace chefs d'U › Communication</div>
            <h1 className="mb-1 text-xl font-semibold">Documents</h1>
            <p className="mb-4 text-xs leading-relaxed text-slate-600">
              Ce que l'unité partage sur sa page publique. L'ordre ci-dessous est celui de la page.
            </p>

            <button className="mb-4 w-full rounded bg-blue-600 px-4 py-2 text-sm text-white">Ajouter un document</button>

            {docs.map((d, i) => (
              <div key={d.id}
                draggable
                onDragStart={() => setDragged(i)}
                onDragOver={(e) => { e.preventDefault(); setOverIndex(i); }}
                onDragEnd={() => { setDragged(null); setOverIndex(null); }}
                onDrop={(e) => { e.preventDefault(); reorder(dragged, i); setDragged(null); setOverIndex(null); }}
                className={`mb-3 rounded-lg border bg-white ${dragged === i ? "opacity-40" : ""} ${overIndex === i && dragged !== i ? "border-blue-500" : ""}`}>
                <div className="px-4 py-3">
                  <div className="flex items-start gap-2">
                    <span className="hidden cursor-grab pt-1 text-slate-300 lg:inline" title="Glisser pour réordonner">⠿</span>
                    <span className="flex flex-col lg:hidden">
                      <button onClick={() => reorder(i, i - 1)} disabled={i === 0} aria-label="Monter"
                        className="px-1 text-slate-400 disabled:text-slate-200">⌃</button>
                      <button onClick={() => reorder(i, i + 1)} disabled={i === docs.length - 1} aria-label="Descendre"
                        className="px-1 text-slate-400 disabled:text-slate-200">⌄</button>
                    </span>
                    <div className="flex-1">
                      <div className="flex flex-wrap items-baseline gap-2">
                        <span className="flex-1 text-sm font-medium">{d.title}</span>
                        <span className={`rounded px-2 py-0.5 text-xs ${LEVELS[d.level].cls}`}>{LEVELS[d.level].label}</span>
                      </div>
                      <div className="mt-1 text-xs text-slate-500">{d.kind} · {d.size} · mis à jour le {d.updated}</div>
                      <div className="mt-1 break-all text-xs text-slate-400" style={{ fontFamily: "ui-monospace, monospace" }}>
                        /documents/{d.slug}
                      </div>
                    </div>
                    <button onClick={() => { setEditing(editing === d.id ? null : d.id); setNewFile(false); }}
                      aria-label={`Modifier ${d.title}`} title="Modifier"
                      className="flex shrink-0 items-center justify-center rounded border border-slate-400 text-slate-600"
                      style={{ width: 44, height: 44 }}>✎</button>
                    <button aria-label={`Supprimer ${d.title}`} title="Supprimer"
                      className="flex shrink-0 items-center justify-center rounded border border-red-400 text-red-600"
                      style={{ width: 44, height: 44 }}>🗑</button>
                  </div>

                  <button onClick={() => setOpenHistory(openHistory === d.id ? null : d.id)}
                    className="mt-2 text-xs text-blue-600 underline">
                    {d.versions} version{d.versions > 1 ? "s" : ""} conservée{d.versions > 1 ? "s" : ""}
                  </button>

                  {editing === d.id && (
                    <div className="mt-3 rounded border px-3 py-3">
                      <div className="mb-3 text-xs font-semibold">Modifier « {d.title} »</div>

                      <label className="mb-1 block text-xs text-slate-500">Titre</label>
                      <input defaultValue={d.title} className="mb-3 w-full rounded border px-2 py-2 text-sm" />

                      <label className="mb-1 block text-xs text-slate-500">Description</label>
                      <textarea rows={2} defaultValue={d.desc} className="mb-3 w-full rounded border px-2 py-2 text-sm" />

                      <label className="mb-1 block text-xs text-slate-500">Visible par</label>
                      <div className="mb-3 flex flex-wrap gap-2">
                        {[0, 1, 3, 4, 9].map((l) => (
                          <button key={l} type="button"
                            className={`rounded border px-3 py-2 text-sm ${l === d.level ? "border-blue-600 bg-blue-600 text-white" : "border-blue-600 bg-white text-blue-600"}`}>
                            {LEVELS[l].label}
                          </button>
                        ))}
                      </div>

                      {d.level === 9 && (
                        <div className="mb-3 rounded border border-slate-300 bg-slate-50 px-3 py-2 text-xs leading-relaxed">
                          <b>Non listé, pas protégé.</b> Quiconque a l'adresse peut le télécharger,
                          même sans compte. Elle contient un segment aléatoire pour qu'on ne puisse
                          pas la deviner, et le document est tenu hors des moteurs de recherche.
                        </div>
                      )}

                      <label className="mb-1 block text-xs text-slate-500">Remplacer le fichier (facultatif)</label>
                      <input type="file" onChange={(e) => setNewFile(e.target.value !== "")}
                        className="mb-1 w-full text-sm" />
                      <p className="mb-3 text-xs text-slate-500">
                        Laissez vide pour conserver le fichier actuel : {d.kind}, {d.size}, du {d.updated}.
                      </p>

                      {newFile && (
                        <div className="mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-relaxed">
                          L'adresse <b>/documents/{d.slug}</b> ne change pas : les liens déjà
                          distribués mèneront à cette nouvelle version. L'ancienne cesse d'être
                          accessible par son lien.
                          {d.versions >= 5 && (
                            <div className="mt-1">
                              Cinq versions sont déjà conservées : la plus ancienne sera
                              définitivement supprimée.
                            </div>
                          )}
                        </div>
                      )}

                      <div className="flex gap-2">
                        <button onClick={() => { setEditing(null); setNewFile(false); }}
                          className="rounded bg-blue-600 px-3 py-2 text-sm text-white">Enregistrer</button>
                        <button onClick={() => { setEditing(null); setNewFile(false); }}
                          className="rounded border px-3 py-2 text-sm text-slate-600">Annuler</button>
                      </div>
                    </div>
                  )}

                  {openHistory === d.id && (
                    <div className="mt-3 rounded border">
                      <div className="border-b bg-slate-50 px-3 py-2 text-xs font-semibold">
                        Versions conservées — visibles du Staff d'U seulement
                      </div>
                      {HISTORY.slice(0, d.versions).map((h) => (
                        <div key={h.v} className="flex flex-wrap items-baseline gap-2 border-b px-3 py-2 text-xs last:border-0">
                          <span className="w-16">Version {h.v}</span>
                          <span className="flex-1 text-slate-600">{h.when} · {h.by} · {h.size}</span>
                          {h.current
                            ? <span className="rounded bg-green-100 px-2 py-0.5 text-green-800">en ligne</span>
                            : <button className="text-blue-600 underline">Télécharger</button>}
                        </div>
                      ))}
                      <div className="bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-600">
                        Une version remplacée n'est plus accessible par son ancien lien, même si le
                        document est public : c'est ce qui fait qu'une correction corrige vraiment.
                        Au-delà de cinq, la plus ancienne est supprimée.
                      </div>
                    </div>
                  )}
                </div>
              </div>
            ))}

            <p className="mb-3 px-1 text-xs text-slate-500">
              Glissez une ligne pour changer l'ordre de la page publique. Sur écran étroit, les
              chevrons remplacent la poignée. Le nouvel ordre est enregistré tout seul.
            </p>

            <div className="rounded-lg border bg-white px-4 py-3 text-xs leading-relaxed text-slate-600">
              <b className="text-slate-800">Les quatre niveaux de visibilité</b>
              <div className="mt-2 space-y-1">
                {[0, 1, 3, 4, 9].map((l) => (
                  <div key={l} className="flex items-baseline gap-2">
                    <span className={`rounded px-2 py-0.5 text-xs ${LEVELS[l].cls}`}>{LEVELS[l].label}</span>
                    <span>{l === 9 ? LEVELS[l].who : `visible par ${LEVELS[l].who}`}</span>
                  </div>
                ))}
              </div>
              <div className="mt-2">
                Les mêmes libellés que l'éditeur d'actualités, qui propose déjà cette visibilité.
                Un intendant voit ce qui est destiné aux membres, jamais ce qui est réservé aux
                animateurs.
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
