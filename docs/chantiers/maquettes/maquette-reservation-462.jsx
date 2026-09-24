import React, { useState } from "react";

/**
 * ScoutMagic — maquette : page d'une réservation (module Locations, issue #462).
 *
 * Fait foi pour : le rail contextuel de la réservation (il remplace celui du
 * bien — le fil d'Ariane, déjà dynamique, garde le chemin de retour), la
 * répartition des boîtes en quatre pages, et surtout le composant
 * « parcours » qui fusionne « L'action suivante » et « Où en est cette
 * location » — deux boîtes qui répondaient à la même question.
 *
 * Trois natures d'étape sont distinguées, ce que BookingMilestone ne porte
 * pas aujourd'hui : elle n'a que key/label/isDone/isApplicable/detail, donc
 * rien ne dit si une case se coche toute seule, ici, ou hors du site.
 *
 * Le produit est en Bootstrap 5 ; Tailwind n'est ici que pour dessiner vite.
 */

const PAGES = [
  ["dash", "Tableau de bord"],
  ["fin", "Finances"],
  ["doc", "Documents"],
  ["mail", "Courrier"],
];

const PHASES = [
  { key: "request", label: "La demande" },
  { key: "agreement", label: "L'accord" },
  { key: "before", label: "Avant le séjour" },
  { key: "stay", label: "Le séjour" },
  { key: "after", label: "Après le séjour" },
];

// Deux dossiers, pour montrer les deux cas difficiles du ticket.
const CASES = {
  nouvelle: {
    ref: "LOC-2026-014", asset: "Chalet de Saint-Crépin",
    dates: "du 14 au 21 février 2027", renter: "Unité 12e Uccle",
    current: 0,
    headline: "Cette demande attend votre décision.",
    since: "reçue il y a 3 jours",
    primary: "Proposer un contrat",
    others: ["Demander des informations", "Mettre en examen", "Refuser la demande"],
    milestones: {
      request: [
        { l: "Demande reçue", done: true, kind: "derived", note: "Reçue le 18 septembre, par le formulaire public." },
        { l: "Dates bloquées", done: true, kind: "derived", note: "Du 14 au 21 février 2027, invisibles des autres demandeurs." },
        { l: "Décision prise sur la demande", done: false, kind: "here",
          desc: "Répondez au locataire : proposez-lui un contrat, demandez-lui des précisions, ou refusez.",
          action: "Proposer un contrat", others: ["Demander des informations", "Refuser la demande"] },
      ],
      agreement: [
        { l: "Contrat envoyé", done: false, kind: "here", desc: "Le contrat reprend les conditions du bien et le prix convenu.", action: "Préparer le contrat" },
        { l: "Conditions et contrat acceptés", done: false, kind: "renter", desc: "Le locataire accepte depuis sa page de suivi." },
        { l: "Acompte reçu", done: false, kind: "derived", note: "Se coche dès qu'un paiement est enregistré dans Finances." },
      ],
      before: [
        { l: "Solde reçu", done: false, kind: "derived" },
        { l: "Caution reçue", done: false, kind: "derived" },
      ],
      stay: [
        { l: "État des lieux d'arrivée", done: false, kind: "offsite", desc: "Personne ne peut le deviner : cochez quand c'est fait." },
        { l: "Relevés de compteurs", done: false, kind: "offsite" },
        { l: "État des lieux de départ", done: false, kind: "offsite" },
      ],
      after: [
        { l: "Décompte final", done: false, kind: "here", action: "Établir le décompte" },
        { l: "Caution restituée", done: false, kind: "here", action: "Enregistrer la restitution" },
      ],
    },
  },
  avant: {
    ref: "LOC-2026-009", asset: "Chalet de Saint-Crépin",
    dates: "du 2 au 9 août 2026", renter: "Famille Leroy",
    current: 2,
    headline: "En attente du solde — échéance dépassée de 4 jours.",
    since: "relancé le 8 septembre",
    primary: "Relancer le locataire",
    others: ["Enregistrer un paiement reçu", "Modifier le prix", "Annuler la réservation"],
    milestones: {
      request: [
        { l: "Demande reçue", done: true, kind: "derived", note: "Reçue le 3 juin." },
        { l: "Dates bloquées", done: true, kind: "derived" },
        { l: "Décision prise sur la demande", done: true, kind: "here", note: "Contrat proposé le 5 juin." },
      ],
      agreement: [
        { l: "Contrat envoyé", done: true, kind: "here", note: "Envoyé le 5 juin." },
        { l: "Conditions et contrat acceptés", done: true, kind: "renter", note: "Acceptés le 7 juin depuis la page de suivi." },
        { l: "Acompte reçu", done: true, kind: "derived", note: "150 € reçus le 9 juin." },
      ],
      before: [
        { l: "Solde reçu", done: false, kind: "derived",
          note: "350 € attendus pour le 15 septembre — rien reçu.",
          desc: "Se cochera dès qu'un paiement sera enregistré dans Finances.",
          action: "Relancer le locataire", others: ["Enregistrer un paiement reçu"] },
        { l: "Caution reçue", done: false, kind: "derived", note: "500 € attendus." },
      ],
      stay: [
        { l: "État des lieux d'arrivée", done: false, kind: "offsite", desc: "Personne ne peut le deviner : cochez quand c'est fait." },
        { l: "Relevés de compteurs", done: false, kind: "offsite" },
        { l: "État des lieux de départ", done: false, kind: "offsite" },
      ],
      after: [
        { l: "Décompte final", done: false, kind: "here", action: "Établir le décompte" },
        { l: "Caution restituée", done: false, kind: "here", action: "Enregistrer la restitution" },
      ],
    },
  },
};

const KIND = {
  derived:  { label: "se dérive du site",        cls: "bg-slate-100 text-slate-600" },
  here:     { label: "à faire ici",              cls: "bg-blue-100 text-blue-800" },
  renter:   { label: "en attente du locataire",  cls: "bg-amber-100 text-amber-800" },
  offsite:  { label: "hors du site",             cls: "bg-violet-100 text-violet-800" },
};

const MAILS = [
  { from: "contact@12e-uccle.be", subj: "Re : Votre demande de location — LOC-2026-014", when: "hier, 18:04", link: "LOC-2026-014" },
  { from: "j.leroy@example.be", subj: "Question sur les draps", when: "hier, 09:12", link: null, guess: "LOC-2026-009" },
  { from: "noreply@banque.be", subj: "Extrait de compte n°128", when: "2 sept.", link: null },
  { from: "commune@wavre.be", subj: "Taxe de séjour — rappel", when: "28 août", link: null },
];

function Card({ title, children, tone }) {
  return (
    <div className={`mb-4 rounded-lg border bg-white ${tone === "warn" ? "border-amber-300" : ""}`}>
      {title && <div className="border-b px-4 py-3 text-sm font-semibold">{title}</div>}
      {children}
    </div>
  );
}

export default function MaquetteReservation() {
  const [page, setPage] = useState("dash");
  const [which, setWhich] = useState("nouvelle");
  const [wide, setWide] = useState(true);
  const [open, setOpen] = useState(null);
  const [showOthers, setShowOthers] = useState(false);

  const b = CASES[which];
  const openPhase = open ?? PHASES[b.current].key;

  return (
    <div className="min-h-screen bg-slate-100 p-4 text-slate-800">
      <div className="mx-auto" style={{ maxWidth: wide ? 760 : 380 }}>
        <div className="mb-3 flex flex-wrap gap-2">
          {Object.entries({ nouvelle: "Nouvelle demande", avant: "Avant le séjour" }).map(([k, l]) => (
            <button key={k} onClick={() => { setWhich(k); setOpen(null); setShowOthers(false); }}
              className={`rounded border px-3 py-1 text-xs ${which === k ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>{l}</button>
          ))}
          <button onClick={() => setWide(!wide)} className="rounded border border-slate-300 bg-white px-3 py-1 text-xs text-slate-600">
            {wide ? "Voir en mobile" : "Voir en large"}
          </button>
        </div>

        {/* fil d'Ariane — déjà en place aujourd'hui, avec ses étapes dynamiques
            fournies par le contrôleur (breadcrumb_trail). Le rail peut donc
            remplacer celui du bien sans supprimer aucun chemin de retour. */}
        <div className="mb-2 text-xs leading-relaxed text-slate-500">
          <span className="text-blue-600">⌂</span>
          {" / "}<span className="text-blue-600">Espace membres</span>
          {" / "}<span className="text-blue-600">Mes locations</span>
          {" / "}<span className="text-blue-600">{b.asset}</span>
          {" / "}<span className="text-blue-600">Réservations</span>
          {" / "}<span>{b.ref}</span>
        </div>
        <h1 className="text-xl font-semibold">{b.ref}</h1>
        <p className="mb-3 text-xs text-slate-500">{b.renter} · {b.dates}</p>

        {/* rail contextuel : il remplace celui du bien */}
        <div className="mb-4 flex gap-2 overflow-x-auto pb-1">
          {PAGES.map(([k, l]) => (
            <button key={k} onClick={() => setPage(k)}
              className={`whitespace-nowrap rounded border px-3 py-2 text-xs ${page === k ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>{l}</button>
          ))}
        </div>

        {/* ───────────── TABLEAU DE BORD ───────────── */}
        {page === "dash" && (
          <>
            <Card tone="warn">
              <div className="px-4 py-4">
                <div className="text-sm font-semibold">{b.headline}</div>
                <div className="mt-1 text-xs text-slate-500">{b.since}</div>

                <div className="mt-3 flex flex-wrap gap-2">
                  <button className="rounded bg-blue-600 px-4 py-2 text-sm text-white">{b.primary}</button>
                  <button onClick={() => setShowOthers(!showOthers)} className="rounded border border-slate-300 px-3 py-2 text-sm text-slate-600">
                    Autres décisions ({b.others.length})
                  </button>
                </div>
                {showOthers && (
                  <div className="mt-2 flex flex-wrap gap-2">
                    {b.others.map((o) => (
                      <button key={o} className="rounded border border-slate-300 px-3 py-1 text-xs text-slate-600">{o}</button>
                    ))}
                  </div>
                )}
                <p className="mt-2 text-xs text-slate-500">
                  Une seule action mise en avant : celle qui fait avancer le dossier. Un refus ou une
                  annulation n'est jamais l'action proposée.
                </p>
              </div>

              {/* parcours — frise verticale, sur le motif de « Changer d'année » */}
              <div className="border-t px-4 py-4">
                <div className="mb-3 text-xs leading-relaxed text-slate-600">
                  Les étapes se suivent dans cet ordre : chacune attend la précédente. Les dates sont
                  celles du séjour, pas des échéances du site.
                </div>

                {PHASES.map((ph, pi) => {
                  const state = pi < b.current ? "done" : pi === b.current ? "now" : "todo";
                  const isOpen = openPhase === ph.key;
                  return (
                    <div key={ph.key} className="mb-3">
                      <button onClick={() => setOpen(isOpen ? "" : ph.key)}
                        className="flex w-full items-center gap-2 text-left">
                        <span className="text-sm font-semibold">{ph.label}</span>
                        {state === "now" && <span className="rounded bg-blue-600 px-2 py-0.5 text-xs text-white">en cours</span>}
                        {state === "done" && <span className="rounded bg-green-100 px-2 py-0.5 text-xs text-green-800">terminée</span>}
                        <span className="flex-1" />
                        <span className="text-slate-400">{isOpen ? "▾" : "▸"}</span>
                      </button>

                      {isOpen && (
                        <div className="mt-2">
                          {b.milestones[ph.key].map((m, mi) => {
                            const future = state === "todo";
                            const n = mi + 1;
                            return (
                              <div key={m.l} className="flex gap-3">
                                <div className="flex flex-col items-center">
                                  <span className={`flex items-center justify-center rounded-full text-xs ${
                                    m.done ? "bg-green-600 text-white" : future ? "bg-slate-200 text-slate-400" : "bg-blue-600 text-white"}`}
                                    style={{ width: 28, height: 28 }}>
                                    {m.done ? "✓" : n}
                                  </span>
                                  {mi < b.milestones[ph.key].length - 1 && <span className="w-px flex-1 bg-slate-200" style={{ minHeight: 18 }} />}
                                </div>

                                <div className="flex-1 pb-4">
                                  <div className="flex flex-wrap items-baseline gap-2">
                                    <span className={`text-sm font-medium ${m.done ? "text-green-700" : future ? "text-slate-400" : ""}`}>{m.l}</span>
                                    {!m.done && <span className={`rounded px-2 py-0.5 text-xs ${KIND[m.kind].cls}`}>{KIND[m.kind].label}</span>}
                                  </div>

                                  {m.desc && !m.done && <div className="mt-1 text-xs leading-relaxed text-slate-600">{m.desc}</div>}
                                  {m.note && <div className="mt-1 text-xs text-slate-500">📊 {m.note}</div>}

                                  {!m.done && !future && m.action && (
                                    <div className="mt-2 flex flex-wrap gap-2">
                                      <button className="rounded bg-blue-600 px-3 py-2 text-sm text-white">{m.action}</button>
                                      {m.others?.map((o) => (
                                        <button key={o} className="rounded border border-slate-300 px-3 py-2 text-sm text-slate-600">{o}</button>
                                      ))}
                                    </div>
                                  )}

                                  {!m.done && m.kind === "offsite" && (
                                    <label className="mt-2 flex items-center gap-2 text-sm text-slate-600">
                                      <input type="checkbox" disabled={future} /> Marquer comme fait
                                    </label>
                                  )}
                                </div>
                              </div>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  );
                })}

                <p className="text-xs leading-relaxed text-slate-500">
                  Une case à cocher n'apparaît que sur les étapes <b>hors du site</b> : les autres se
                  dérivent de ce qui s'est passé ici, et une coche manuelle à côté créerait une
                  seconde vérité.
                </p>
              </div>
            </Card>

            <Card title="Les détails de la réservation">
              <div className="px-4 py-3 text-xs text-slate-600">
                Qui, quoi, quand, combien — sans badge d'état : le parcours ci-dessus répond déjà à
                cette question, et deux réponses à deux écrans d'écart sont le défaut qu'on corrige.
              </div>
            </Card>
            <Card title="Demandes de modification"><div className="px-4 py-3 text-xs text-slate-500">Aucune demande en cours.</div></Card>
            <Card title="Commentaires internes"><div className="px-4 py-3 text-xs text-slate-500">Visibles des seuls gestionnaires.</div></Card>
            <Card title="Historique"><div className="px-4 py-3 text-xs text-slate-500">Toutes les actions, du plus récent au plus ancien.</div></Card>
          </>
        )}

        {page === "fin" && (
          <>
            <Card title="Prix"><div className="px-4 py-3 text-xs text-slate-500">Lignes de prix, remises, total.</div></Card>
            <Card title="Paiements"><div className="px-4 py-3 text-xs text-slate-500">Acompte, solde, caution — attendus et reçus.</div></Card>
          </>
        )}

        {page === "doc" && (
          <Card title="Documents"><div className="px-4 py-3 text-xs text-slate-500">Contrat, facture, états des lieux.</div></Card>
        )}

        {/* ───────────── COURRIER ───────────── */}
        {page === "mail" && (
          <>
            <Card>
              <div className="px-4 py-3 text-xs leading-relaxed text-slate-600">
                Cette page n'existe que parce que le module dispose d'une <b>boîte dédiée</b>. Elle
                montre donc tout son courrier, pas seulement les messages déjà rattachés à une
                réservation. Les e-mails envoyés par le module portent cette adresse en réponse.
              </div>
            </Card>

            <Card title="Courrier de la boîte">
              <div className="px-4 py-3">
                <input placeholder="Rechercher un expéditeur, un objet…" className="mb-3 w-full rounded border px-2 py-2 text-sm" />
                {MAILS.map((m) => (
                  <div key={m.subj} className="border-b py-2 last:border-0">
                    <div className="flex flex-wrap items-baseline gap-2">
                      <span className="flex-1 text-sm">{m.subj}</span>
                      <span className="text-xs text-slate-400">{m.when}</span>
                    </div>
                    <div className="text-xs text-slate-500">{m.from}</div>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs">
                      {m.link && <span className="rounded bg-green-100 px-2 py-0.5 text-green-800">rattaché à {m.link}</span>}
                      {!m.link && m.guess && (
                        <>
                          <span className="rounded bg-amber-100 px-2 py-0.5 text-amber-800">{m.guess} ?</span>
                          <button className="text-blue-600 underline">Confirmer</button>
                          <button className="text-slate-500 underline">Non</button>
                        </>
                      )}
                      {!m.link && !m.guess && (
                        <>
                          <button className="text-blue-600 underline">Rattacher</button>
                          <button className="text-slate-500 underline">Écarter</button>
                          <button className="text-slate-500 underline">Supprimer</button>
                        </>
                      )}
                    </div>
                  </div>
                ))}
                <p className="mt-3 text-xs text-slate-500">
                  Le même écran sert au courrier des camps : un seul composant, deux modules.
                </p>
              </div>
            </Card>
          </>
        )}
      </div>
    </div>
  );
}
