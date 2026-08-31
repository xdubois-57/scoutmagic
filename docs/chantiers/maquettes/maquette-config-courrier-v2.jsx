import React, { useState } from "react";

/**
 * ScoutMagic — maquette v2 : configuration des boîtes de courrier entrant.
 *
 * Deux changements par rapport à la v1 :
 *  - la boîte déclare d'abord son usage (dédiée / partagée), et cet usage
 *    pré-remplit tout le reste ;
 *  - « analyser » et « laisser consulter » deviennent deux questions
 *    distinctes, au lieu d'une case + trois boutons radio qui se recouvrent.
 */

const MODULES = [
  {
    id: "rental",
    name: "Locations",
    evidence: "Référence de réservation dans l'objet, fil de discussion, adresse du locataire pendant son séjour",
    audience: "Staff d'U et gestionnaires de biens",
    count: 14,
  },
  {
    id: "camps",
    name: "Camps",
    evidence: "Fil de discussion, adresse d'un contact de camp dans la période du séjour",
    audience: "Staff d'U et chefs de camp",
    count: 9,
  },
  {
    id: "finance",
    name: "Finances",
    evidence: "Pièce jointe dont le texte contient un montant et une date — signal faible",
    audience: "Trésorerie et Staff d'U",
    count: 5,
  },
];

const INITIAL = {
  "info@unite.be": {
    purpose: "shared",
    dedicatedTo: null,
    scopes: {
      rental: { analyze: true, read: "relevant" },
      camps: { analyze: true, read: "none" },
      finance: { analyze: false, read: "none" },
    },
  },
  "tresorerie@unite.be": {
    purpose: "dedicated",
    dedicatedTo: "finance",
    scopes: {
      rental: { analyze: false, read: "none" },
      camps: { analyze: false, read: "none" },
      finance: { analyze: true, read: "all" },
    },
  },
  "camps@unite.be": {
    purpose: "dedicated",
    dedicatedTo: "camps",
    scopes: {
      rental: { analyze: false, read: "none" },
      camps: { analyze: true, read: "all" },
      finance: { analyze: false, read: "none" },
    },
  },
};

const readLabel = { none: "Aucune consultation", relevant: "Messages concernés", all: "Tout le courrier" };

const Pill = ({ tone = "muted", children }) => {
  const tones = {
    muted: "bg-slate-100 text-slate-600 border-slate-200",
    on: "bg-emerald-50 text-emerald-800 border-emerald-200",
    open: "bg-amber-50 text-amber-900 border-amber-300",
    off: "bg-slate-50 text-slate-400 border-slate-200",
  };
  return <span className={`inline-flex rounded border px-1.5 py-0.5 text-[11px] leading-tight ${tones[tone]}`}>{children}</span>;
};

const Btn = ({ children, variant = "outline", onClick }) => {
  const v = {
    primary: "bg-blue-600 text-white hover:bg-blue-700",
    outline: "border border-slate-300 text-slate-700 hover:bg-slate-50",
    ghost: "text-blue-600 hover:bg-blue-50",
  };
  return (
    <button onClick={onClick} className={`rounded px-3 py-1.5 text-[13px] font-medium min-h-[38px] ${v[variant]}`}>
      {children}
    </button>
  );
};

/* ------------------------------------------------ index : vue d'ensemble */

function Index({ boxes, mobile, onOpen }) {
  const summary = (name) => {
    const b = boxes[name];
    const active = MODULES.filter((m) => b.scopes[m.id].analyze);
    if (active.length === 0) return [<Pill key="x" tone="off">Aucun module — le courrier est conservé mais rien ne le classe</Pill>];
    return active.map((m) => {
      const r = b.scopes[m.id].read;
      return (
        <Pill key={m.id} tone={r === "all" ? "open" : r === "relevant" ? "on" : "muted"}>
          {m.name} · {r === "none" ? "classement seul" : readLabel[r].toLowerCase()}
        </Pill>
      );
    });
  };

  return (
    <div>
      <h1 className="text-[21px] font-semibold text-slate-900">Courrier entrant</h1>
      <p className="mb-4 text-[13px] text-slate-500">
        Les boîtes lues par ScoutMagic, et ce que chaque module en fait. Le courrier n'est jamais modifié ni supprimé
        dans la boîte d'origine.
      </p>

      <div className="space-y-2">
        {Object.keys(boxes).map((name) => {
          const b = boxes[name];
          return (
            <button
              key={name}
              onClick={() => onOpen(name)}
              className="block w-full rounded border border-slate-200 p-3 text-left hover:border-slate-400"
            >
              <div className={`flex ${mobile ? "flex-col gap-1" : "items-start justify-between gap-4"}`}>
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="text-[15px] font-medium text-slate-900">{name}</span>
                    <Pill>{b.purpose === "dedicated" ? "Dédiée" : "Partagée"}</Pill>
                  </div>
                  <div className="mt-1 flex flex-wrap gap-1">{summary(name)}</div>
                  <div className="mt-1 text-[12px] text-slate-400">Dernière lecture il y a 7 minutes · 1 240 messages conservés</div>
                </div>
                {!mobile && <span className="shrink-0 text-[13px] font-medium text-blue-600">Configurer</span>}
              </div>
            </button>
          );
        })}
      </div>

      <div className="mt-4 flex gap-2">
        <Btn variant="primary">Ajouter une boîte</Btn>
        <Btn>Rafraîchir maintenant</Btn>
      </div>
    </div>
  );
}

/* -------------------------------------------- détail : usage puis portée */

function BoxConfig({ name, box, setBox, mobile, onBack }) {
  const setPurpose = (purpose, dedicatedTo = null) => {
    const scopes = {};
    MODULES.forEach((m) => {
      if (purpose === "dedicated") {
        scopes[m.id] = m.id === dedicatedTo ? { analyze: true, read: "all" } : { analyze: false, read: "none" };
      } else {
        scopes[m.id] = { analyze: box.scopes[m.id].analyze, read: box.scopes[m.id].read === "all" ? "relevant" : box.scopes[m.id].read };
      }
    });
    setBox({ ...box, purpose, dedicatedTo, scopes });
  };

  const setScope = (id, patch) =>
    setBox({ ...box, scopes: { ...box.scopes, [id]: { ...box.scopes[id], ...patch } } });

  return (
    <div>
      <button onClick={onBack} className="mb-3 text-[13px] text-blue-600 hover:underline">
        ← Toutes les boîtes
      </button>
      <h1 className="text-[21px] font-semibold text-slate-900">{name}</h1>
      <p className="mb-5 text-[13px] text-slate-500">Connexion IMAP validée · dernière lecture il y a 7 minutes</p>

      {/* ---- 1. usage ---- */}
      <h2 className="text-[15px] font-semibold text-slate-900">À quoi sert cette boîte ?</h2>
      <p className="mb-2 text-[13px] text-slate-500">C'est cette réponse qui détermine la suite.</p>

      <div className={`mb-6 gap-2 ${mobile ? "space-y-2" : "grid grid-cols-2"}`}>
        <button
          onClick={() => setPurpose("shared")}
          className={`rounded border p-3 text-left ${
            box.purpose === "shared" ? "border-blue-600 bg-blue-50" : "border-slate-300 hover:border-slate-400"
          }`}
        >
          <div className="text-[14px] font-medium text-slate-900">Boîte partagée</div>
          <div className="text-[12px] text-slate-600">
            L'adresse publique de l'unité. On y trouve de tout : parents, fournisseurs, locataires, inscriptions.
          </div>
        </button>
        <button
          onClick={() => setPurpose("dedicated", box.dedicatedTo ?? "camps")}
          className={`rounded border p-3 text-left ${
            box.purpose === "dedicated" ? "border-blue-600 bg-blue-50" : "border-slate-300 hover:border-slate-400"
          }`}
        >
          <div className="text-[14px] font-medium text-slate-900">Boîte dédiée</div>
          <div className="text-[12px] text-slate-600">
            Une adresse créée pour un seul usage, dont tout le courrier concerne le même module.
          </div>
        </button>
      </div>

      {box.purpose === "dedicated" ? (
        <div className="mb-6 rounded border border-slate-200 p-3">
          <label className="mb-1 block text-[14px] font-medium text-slate-900">Dédiée à</label>
          <select
            value={box.dedicatedTo ?? ""}
            onChange={(e) => setPurpose("dedicated", e.target.value)}
            className="min-h-[38px] w-full rounded border border-slate-300 px-2 text-[14px]"
          >
            {MODULES.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
              </option>
            ))}
          </select>
          <p className="mt-2 text-[13px] text-slate-600">
            {MODULES.find((m) => m.id === box.dedicatedTo)?.name} lit et classe tout le courrier de cette boîte. Ses
            utilisateurs — {MODULES.find((m) => m.id === box.dedicatedTo)?.audience.toLowerCase()},{" "}
            {MODULES.find((m) => m.id === box.dedicatedTo)?.count} personnes aujourd'hui — voient l'intégralité des
            messages reçus dans {name}, y compris ceux que le module ne rattache à aucun de ses éléments.
          </p>
          <p className="mt-2 text-[13px] text-slate-500">
            Aucun autre module ne lit cette boîte. Le Chef d'Unité, lui, voit le courrier de toutes les boîtes depuis
            Espace admin &gt; Courrier.
          </p>
        </div>
      ) : (
        /* ---- 2. portée par module, boîte partagée ---- */
        <>
          <h2 className="text-[15px] font-semibold text-slate-900">Ce que chaque module en fait</h2>
          <p className="mb-3 text-[13px] text-slate-500">
            Sur une boîte partagée, chaque module ne doit voir que ce qui le concerne. Le Chef d'Unité, lui, voit tout
            le courrier depuis Espace admin &gt; Courrier.
          </p>

          <div className="space-y-3">
            {MODULES.map((m) => {
              const s = box.scopes[m.id];
              return (
                <div key={m.id} className="rounded border border-slate-200 p-3">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="text-[15px] font-medium text-slate-900">{m.name}</div>
                      <div className="text-[12px] text-slate-500">
                        Analyse ce courrier pour le rattacher à ses propres éléments
                      </div>
                    </div>
                    <button
                      onClick={() => setScope(m.id, { analyze: !s.analyze, read: s.analyze ? "none" : s.read })}
                      className={`relative h-6 w-11 shrink-0 rounded-full transition-colors ${
                        s.analyze ? "bg-blue-600" : "bg-slate-300"
                      }`}
                      aria-label={`Activer ${m.name}`}
                    >
                      <span
                        className={`absolute top-0.5 h-5 w-5 rounded-full bg-white transition-all ${
                          s.analyze ? "left-[22px]" : "left-0.5"
                        }`}
                      />
                    </button>
                  </div>

                  {s.analyze && (
                    <div className="mt-3 border-t border-slate-100 pt-3">
                      <div className="mb-1.5 text-[13px] font-medium text-slate-800">
                        Qui peut lire ce courrier depuis {m.name} ?
                      </div>
                      <div className={`gap-1 ${mobile ? "space-y-1" : "flex"}`}>
                        {[
                          ["none", "Personne"],
                          ["relevant", "Messages concernés"],
                          ["all", "Tout le courrier"],
                        ].map(([v, label]) => (
                          <button
                            key={v}
                            onClick={() => setScope(m.id, { read: v })}
                            className={`rounded border px-3 py-1.5 text-[13px] ${mobile ? "w-full text-left" : ""} ${
                              s.read === v
                                ? v === "all"
                                  ? "border-amber-500 bg-amber-50 text-amber-900"
                                  : "border-blue-600 bg-blue-50 text-blue-800"
                                : "border-slate-300 text-slate-600"
                            }`}
                          >
                            {label}
                          </button>
                        ))}
                      </div>

                      <p className="mt-2 text-[12px] text-slate-500">
                        {s.read === "none" && (
                          <>
                            {m.name} classe le courrier automatiquement, mais n'ouvre aucune liste. Les messages restent
                            visibles depuis la fiche à laquelle ils sont rattachés.
                          </>
                        )}
                        {s.read === "relevant" && (
                          <>
                            {m.audience} ({m.count} personnes) verront les messages que {m.name} rattache à un élément
                            qu'ils gèrent, <strong>ou pense pouvoir y rattacher</strong>. {m.name} propose sur :{" "}
                            {m.evidence.toLowerCase()}.
                          </>
                        )}
                      </p>

                      {s.read === "all" && (
                        <div className="mt-2 rounded border border-amber-400 bg-amber-50 p-2.5 text-[13px] text-amber-900">
                          <strong>Sur une boîte partagée, c'est un choix lourd.</strong> {m.audience} ({m.count}{" "}
                          personnes) pourront lire <strong>tous</strong> les messages reçus dans {name}, y compris ceux
                          qui n'ont rien à voir avec {m.name} : questions de parents, documents médicaux, candidatures.
                          Déclarez plutôt cette boîte comme dédiée si c'est vraiment son usage.
                        </div>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
          </div>

        </>
      )}

      <div className="mt-6 rounded border border-slate-200 bg-slate-50 p-3 text-[13px] text-slate-600">
        <div className="mb-1 font-medium text-slate-800">Conservation</div>
        Tout le courrier reçu dans {name} est conservé. Les messages qu'aucun module ne rattache ni ne propose sont
        supprimés après <strong>90 jours</strong>. Les autres sont conservés tant que l'élément qui les utilise existe.
      </div>

      <div className="mt-4 flex gap-2">
        <Btn variant="primary">Enregistrer</Btn>
        <Btn onClick={onBack}>Annuler</Btn>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ app */

export default function App() {
  const [mobile, setMobile] = useState(false);
  const [boxes, setBoxes] = useState(INITIAL);
  const [open, setOpen] = useState(null);

  return (
    <div className="min-h-screen bg-slate-100 p-4 font-sans text-slate-900">
      <div className="mx-auto max-w-[900px]">
        <div className="mb-4 rounded-lg border border-slate-300 bg-white p-3">
          <div className="mb-2 text-[12px] font-semibold uppercase tracking-wide text-slate-500">
            Maquette v2 — configuration (superadmin)
          </div>
          <div className="flex gap-1">
            {["Bureau", "Mobile"].map((l, i) => (
              <button
                key={l}
                onClick={() => setMobile(i === 1)}
                className={`rounded px-3 py-1.5 text-[13px] ${
                  mobile === (i === 1) ? "bg-slate-900 text-white" : "border border-slate-300 text-slate-600"
                }`}
              >
                {l}
              </button>
            ))}
          </div>
          <div className="mt-2 text-[12px] text-slate-500">
            Ouvrez info@unite.be (partagée) puis tresorerie@unite.be (dédiée) pour comparer les deux parcours.
          </div>
        </div>

        <div className={`mx-auto rounded-lg border border-slate-300 bg-white shadow-sm ${mobile ? "w-[375px] p-4" : "w-full p-6"}`}>
          {open === null ? (
            <Index boxes={boxes} mobile={mobile} onOpen={setOpen} />
          ) : (
            <BoxConfig
              name={open}
              box={boxes[open]}
              setBox={(b) => setBoxes({ ...boxes, [open]: b })}
              mobile={mobile}
              onBack={() => setOpen(null)}
            />
          )}
        </div>
      </div>
    </div>
  );
}
