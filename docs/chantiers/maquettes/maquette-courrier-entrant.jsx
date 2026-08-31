import React, { useState, useMemo } from "react";

/**
 * ScoutMagic — maquette « Courrier entrant »
 * Structure, hiérarchie, libellés français et états d'interaction font foi.
 * Bootstrap 5 dans le vrai produit ; ici Tailwind pour la maquette.
 */

const MESSAGES = [
  {
    id: 141,
    date: "29/08 12:41",
    from: "Jean Dupont",
    fromMail: "j.dupont@example.be",
    subject: "Contrat signé et preuve de paiement",
    mailbox: "info@unite.be",
    attachments: [
      { id: 78, name: "contrat-signe.pdf", size: "814 Ko", kind: "pdf" },
      { id: 79, name: "virement.jpg", size: "1,9 Mo", kind: "img" },
    ],
    omitted: [],
    bulk: false,
    links: [{ module: "Locations", ref: "LOC-2026-0042", label: "La Grange — 12–15 sept.", origin: "Référence" }],
    candidates: [{ module: "Finances", scope: "virement.jpg", ref: "Compte courant", evidence: "Pièce jointe + montant lisible" }],
    body:
      "Bonjour,\n\nVous trouverez ci-joint le contrat signé pour la location de La Grange, ainsi que la preuve du virement de la caution.\n\nBien à vous,\nJean Dupont",
  },
  {
    id: 140,
    date: "29/08 11:18",
    from: "Ferme des Trois Chênes",
    fromMail: "contact@troischenes.be",
    subject: "Re: disponibilité de la prairie en juillet",
    mailbox: "camps@unite.be",
    attachments: [],
    omitted: [],
    bulk: false,
    links: [{ module: "Camps", ref: "camp-51", label: "Séjour Éclaireurs 2027", origin: "Fil de discussion" }],
    candidates: [],
    body: "Bonjour, la prairie est bien libre du 8 au 18 juillet. Je vous confirme le tarif convenu.",
  },
  {
    id: 139,
    date: "29/08 09:52",
    from: "Marie Lambert",
    fromMail: "marie.lambert@example.be",
    subject: "Question sur la fiche santé de Camille",
    mailbox: "info@unite.be",
    attachments: [{ id: 80, name: "fiche-sante.pdf", size: "220 Ko", kind: "pdf" }],
    omitted: [],
    bulk: false,
    links: [],
    candidates: [],
    body: "Bonjour, je ne trouve plus où déposer la fiche santé de ma fille Camille. Pouvez-vous m'indiquer la marche à suivre ?",
  },
  {
    id: 138,
    date: "28/08 17:04",
    from: "Sophie Renard",
    fromMail: "s.renard@example.be",
    subject: "Fwd: demande pour un week-end en octobre",
    mailbox: "info@unite.be",
    attachments: [],
    omitted: [{ name: "visite-local.mp4", size: "68 Mo", reason: "Trop volumineuse (max 15 Mo)" }],
    bulk: false,
    links: [],
    candidates: [
      { module: "Locations", scope: null, ref: "LOC-2026-0051", label: "La Grange — 10–12 oct.", evidence: "Adresse du locataire + période compatible" },
      { module: "Locations", scope: null, ref: "LOC-2026-0058", label: "Le Terrain — 17–19 oct.", evidence: "Adresse du locataire + période compatible" },
    ],
    body:
      "Bonjour,\n\nJe vous transfère ma demande initiale ci-dessous.\n\n---------- Message transféré ----------\nDe : s.renard@example.be\nObjet : Week-end d'octobre\n\nNous serions une vingtaine de personnes...",
  },
  {
    id: 137,
    date: "28/08 08:30",
    from: "Les Scouts ASBL",
    fromMail: "newsletter@lesscouts.be",
    subject: "La lettre des unités — septembre",
    mailbox: "info@unite.be",
    attachments: [],
    omitted: [],
    bulk: true,
    links: [],
    candidates: [],
    body: "Au sommaire ce mois-ci : la rentrée, les formations d'animateurs, le calendrier fédéral.",
  },
  {
    id: 136,
    date: "27/08 22:11",
    from: "Postmaster",
    fromMail: "mailer-daemon@example.net",
    subject: "Undelivered Mail Returned to Sender",
    mailbox: "info@unite.be",
    attachments: [],
    omitted: [],
    bulk: true,
    links: [],
    candidates: [],
    body: "This is the mail system at host example.net. Your message could not be delivered.",
  },
];

const MAILBOXES = [
  { name: "info@unite.be", label: "Boîte générale de l'unité" },
  { name: "camps@unite.be", label: "Boîte des camps" },
  { name: "locations@unite.be", label: "Boîte des locations" },
];

const Chip = ({ children, tone = "muted" }) => {
  const tones = {
    muted: "bg-slate-100 text-slate-600 border-slate-200",
    link: "bg-emerald-50 text-emerald-800 border-emerald-200",
    cand: "bg-amber-50 text-amber-800 border-amber-200",
    none: "bg-slate-50 text-slate-500 border-slate-200",
    bulk: "bg-slate-100 text-slate-500 border-slate-200",
    warn: "bg-red-50 text-red-700 border-red-200",
  };
  return (
    <span className={`inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] leading-tight ${tones[tone]}`}>
      {children}
    </span>
  );
};

const Btn = ({ children, variant = "outline", size = "sm", onClick, disabled }) => {
  const base = "rounded font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed";
  const sizes = { sm: "px-2.5 py-1 text-[13px] min-h-[34px]", md: "px-3 py-2 text-sm min-h-[44px]" };
  const variants = {
    primary: "bg-blue-600 text-white hover:bg-blue-700",
    outline: "border border-slate-300 text-slate-700 hover:bg-slate-50",
    ghost: "text-blue-600 hover:bg-blue-50",
    danger: "border border-red-300 text-red-700 hover:bg-red-50",
  };
  return (
    <button className={`${base} ${sizes[size]} ${variants[variant]}`} onClick={onClick} disabled={disabled}>
      {children}
    </button>
  );
};

/* ---------------------------------------------------------------- liste */

function InboxList({ mobile, role, onOpen, showBulk, setShowBulk, filterLink, setFilterLink, filterMailbox, setFilterMailbox }) {
  const rentalScope = ["LOC-2026-0042", "LOC-2026-0051"]; // biens gérés par ce gestionnaire

  const visible = useMemo(() => {
    let list = MESSAGES;
    if (role === "rental") {
      // Tri métier : uniquement les messages ayant un candidat ou un lien
      // vers un booking que CET utilisateur gère.
      list = list.filter((m) =>
        [...m.links, ...m.candidates].some((x) => x.module === "Locations" && rentalScope.includes(x.ref))
      );
      return list;
    }
    if (!showBulk) list = list.filter((m) => !m.bulk);
    if (filterLink === "none") list = list.filter((m) => m.links.length === 0);
    if (filterLink === "some") list = list.filter((m) => m.links.length > 0);
    if (filterMailbox !== "all") list = list.filter((m) => m.mailbox === filterMailbox);
    return list;
  }, [role, showBulk, filterLink, filterMailbox]);

  const bulkHidden = MESSAGES.filter((m) => m.bulk).length;

  return (
    <div>
      <div className="mb-1 flex items-baseline justify-between gap-3">
        <h1 className="text-[21px] font-semibold text-slate-900">
          {role === "rental" ? "Courrier — Locations" : "Courrier entrant"}
        </h1>
        {!mobile && role !== "rental" && <Btn variant="outline">Rafraîchir maintenant</Btn>}
      </div>
      <p className="mb-3 text-[13px] text-slate-500">
        {role === "rental"
          ? "Les messages liés à vos biens, ou que ScoutMagic pense leur être liés."
          : "Tout le courrier reçu dans les boîtes synchronisées. Associer un message ne le retire pas de la boîte distante."}
      </p>

      {role !== "rental" && (
        <div className={`mb-3 flex gap-2 ${mobile ? "flex-col" : "flex-wrap items-center"}`}>
          <select
            value={filterLink}
            onChange={(e) => setFilterLink(e.target.value)}
            className="min-h-[38px] rounded border border-slate-300 bg-white px-2 text-[13px]"
          >
            <option value="none">Sans association</option>
            <option value="some">Avec association</option>
            <option value="all">Toutes les associations</option>
          </select>
          <select
            value={filterMailbox}
            onChange={(e) => setFilterMailbox(e.target.value)}
            className="min-h-[38px] rounded border border-slate-300 bg-white px-2 text-[13px]"
          >
            <option value="all">Toutes les boîtes</option>
            {MAILBOXES.map((b) => (
              <option key={b.name} value={b.name}>
                {b.name}
              </option>
            ))}
          </select>
          {mobile && <Btn variant="outline">Rafraîchir maintenant</Btn>}
        </div>
      )}

      <ul className="divide-y divide-slate-200 border-y border-slate-200">
        {visible.map((m) => (
          <li key={m.id} className="py-3">
            <button onClick={() => onOpen(m)} className="w-full text-left">
              <div className={`flex ${mobile ? "flex-col gap-1" : "items-start justify-between gap-4"}`}>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-baseline gap-x-2 text-[13px] text-slate-500">
                    <span>{m.date}</span>
                    <span className="font-medium text-slate-800">{m.from}</span>
                    {m.bulk && <Chip tone="bulk">automatique</Chip>}
                  </div>
                  <div className="truncate text-[15px] font-medium text-slate-900">{m.subject}</div>
                  <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px] text-slate-500">
                    <span>{m.mailbox}</span>
                    {m.attachments.length > 0 && (
                      <span>
                        · {m.attachments.length} pièce{m.attachments.length > 1 ? "s" : ""} jointe
                        {m.attachments.length > 1 ? "s" : ""}
                      </span>
                    )}
                    {m.omitted.length > 0 && <Chip tone="warn">1 pièce jointe non conservée</Chip>}
                  </div>
                  <div className="mt-1.5 flex flex-wrap gap-1.5">
                    {m.links.length === 0 && m.candidates.length === 0 && <Chip tone="none">Aucune association</Chip>}
                    {m.links.map((l) => (
                      <Chip key={l.ref} tone="link">
                        {l.module} — {l.ref}
                      </Chip>
                    ))}
                    {m.candidates.map((c, i) => (
                      <Chip key={i} tone="cand">
                        Proposition {c.module} — {c.ref}
                      </Chip>
                    ))}
                  </div>
                </div>
                {!mobile && (
                  <span className="shrink-0 pt-1 text-[13px] font-medium text-blue-600">Ouvrir</span>
                )}
              </div>
            </button>
          </li>
        ))}
        {visible.length === 0 && (
          <li className="py-8 text-center text-[13px] text-slate-500">
            Aucun message ne correspond à ce filtre.
          </li>
        )}
      </ul>

      {role !== "rental" && bulkHidden > 0 && (
        <div className="mt-3 text-[13px]">
          {showBulk ? (
            <button className="text-blue-600 hover:underline" onClick={() => setShowBulk(false)}>
              Masquer le courrier automatique
            </button>
          ) : (
            <button className="text-blue-600 hover:underline" onClick={() => setShowBulk(true)}>
              Afficher le courrier automatique ({bulkHidden})
            </button>
          )}
        </div>
      )}
      <p className="mt-4 text-[12px] text-slate-400">
        Les messages sans association ni proposition sont supprimés automatiquement après 90 jours.
      </p>
    </div>
  );
}

/* --------------------------------------------------------------- détail */

function MessageDetail({ m, mobile, role, onBack }) {
  const [links, setLinks] = useState(m.links);
  const [candidates, setCandidates] = useState(m.candidates);
  const [adding, setAdding] = useState(false);
  const rentalScope = ["LOC-2026-0042", "LOC-2026-0051"];

  const visibleCandidates =
    role === "rental" ? candidates.filter((c) => c.module === "Locations" && rentalScope.includes(c.ref)) : candidates;
  const visibleLinks =
    role === "rental" ? links.filter((l) => l.module === "Locations" && rentalScope.includes(l.ref)) : links;

  const confirm = (c) => {
    setLinks([...links, { module: c.module, ref: c.ref, label: c.label ?? c.ref, origin: "Manuelle" }]);
    setCandidates(candidates.filter((x) => x !== c));
  };

  return (
    <div>
      <button onClick={onBack} className="mb-3 text-[13px] text-blue-600 hover:underline">
        ← Retour au courrier
      </button>
      <h1 className="text-[19px] font-semibold text-slate-900">{m.subject}</h1>
      <div className="mt-1 text-[13px] text-slate-500">
        {m.from} &lt;{m.fromMail}&gt; · {m.date} · reçu dans {m.mailbox}
      </div>

      <div className="mt-4 whitespace-pre-line rounded border border-slate-200 bg-slate-50 p-3 text-[14px] leading-relaxed text-slate-800">
        {m.body}
      </div>

      {(m.attachments.length > 0 || m.omitted.length > 0) && (
        <section className="mt-5">
          <h2 className="mb-2 text-[13px] font-semibold uppercase tracking-wide text-slate-500">Pièces jointes</h2>
          <ul className="space-y-1.5">
            {m.attachments.map((a) => (
              <li key={a.id} className="flex items-center justify-between gap-3 rounded border border-slate-200 px-3 py-2">
                <span className="truncate text-[14px] text-slate-800">
                  {a.name} <span className="text-slate-400">· {a.size}</span>
                </span>
                <div className="flex shrink-0 gap-1.5">
                  <Btn variant="ghost">Télécharger</Btn>
                  <Btn variant="outline">Associer</Btn>
                </div>
              </li>
            ))}
            {m.omitted.map((o, i) => (
              <li key={i} className="rounded border border-dashed border-red-200 bg-red-50 px-3 py-2 text-[13px] text-red-800">
                <span className="font-medium">{o.name}</span> · {o.size} — non conservée : {o.reason}. Le message a bien
                été reçu ; le fichier reste disponible dans la boîte d'origine.
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="mt-5">
        <h2 className="mb-2 text-[13px] font-semibold uppercase tracking-wide text-slate-500">Associations</h2>
        {visibleLinks.length === 0 && <p className="text-[14px] text-slate-500">Ce message n'est associé à rien.</p>}
        <ul className="space-y-1.5">
          {visibleLinks.map((l) => (
            <li key={l.ref} className={`rounded border border-slate-200 px-3 py-2 ${mobile ? "" : "flex items-center justify-between gap-3"}`}>
              <div>
                <div className="text-[14px] text-slate-900">
                  {l.module} — {l.ref}
                </div>
                <div className="text-[12px] text-slate-500">
                  {l.label} · association {l.origin.toLowerCase()}
                </div>
              </div>
              <div className={`flex gap-1.5 ${mobile ? "mt-2" : ""}`}>
                <Btn variant="outline">Modifier</Btn>
                <Btn variant="danger">Retirer</Btn>
              </div>
            </li>
          ))}
        </ul>
        {visibleLinks.length > 0 && (
          <p className="mt-1.5 text-[12px] text-slate-400">
            Retirer une association ne supprime pas le message ni les autres associations.
          </p>
        )}
      </section>

      {visibleCandidates.length > 0 && (
        <section className="mt-5">
          <h2 className="mb-2 text-[13px] font-semibold uppercase tracking-wide text-slate-500">
            Propositions à confirmer
          </h2>
          <ul className="space-y-1.5">
            {visibleCandidates.map((c, i) => (
              <li key={i} className={`rounded border border-amber-200 bg-amber-50 px-3 py-2 ${mobile ? "" : "flex items-center justify-between gap-3"}`}>
                <div>
                  <div className="text-[14px] text-slate-900">
                    {c.module} — {c.ref}
                    {c.scope && <span className="text-slate-500"> (pièce jointe {c.scope})</span>}
                  </div>
                  <div className="text-[12px] text-slate-600">{c.evidence}</div>
                </div>
                <div className={`flex gap-1.5 ${mobile ? "mt-2" : ""}`}>
                  <Btn variant="primary" onClick={() => confirm(c)}>
                    Associer
                  </Btn>
                  <Btn variant="outline" onClick={() => setCandidates(candidates.filter((x) => x !== c))}>
                    Écarter
                  </Btn>
                </div>
              </li>
            ))}
          </ul>
          {visibleCandidates.length > 1 && (
            <p className="mt-1.5 text-[12px] text-slate-500">
              Plusieurs cibles sont possibles : ScoutMagic n'en choisit aucune tant que vous n'avez pas tranché.
            </p>
          )}
        </section>
      )}

      <div className="mt-5">
        {adding ? (
          <div className="rounded border border-slate-300 p-3">
            <label className="mb-1 block text-[13px] font-medium text-slate-700">Associer à</label>
            <select className="mb-2 min-h-[38px] w-full rounded border border-slate-300 px-2 text-[13px]">
              <option>Locations</option>
              <option>Camps</option>
              <option>Finances</option>
            </select>
            <input
              placeholder="Rechercher une réservation, un séjour, un compte…"
              className="mb-2 min-h-[38px] w-full rounded border border-slate-300 px-2 text-[13px]"
            />
            <p className="mb-2 text-[12px] text-slate-500">
              Seuls les éléments que vous pouvez gérer apparaissent dans cette recherche.
            </p>
            <div className="flex gap-2">
              <Btn variant="primary" onClick={() => setAdding(false)}>
                Associer
              </Btn>
              <Btn variant="outline" onClick={() => setAdding(false)}>
                Annuler
              </Btn>
            </div>
          </div>
        ) : (
          <Btn variant="outline" size="md" onClick={() => setAdding(true)}>
            Ajouter une association
          </Btn>
        )}
      </div>
    </div>
  );
}

/* --------------------------------------------------------- configuration */

function MailboxConfig({ mobile }) {
  const [modules, setModules] = useState({
    Locations: { on: true, mode: "relevant" },
    Camps: { on: true, mode: "none" },
    Finances: { on: false, mode: "none" },
  });

  const set = (name, patch) => setModules({ ...modules, [name]: { ...modules[name], ...patch } });

  return (
    <div>
      <h1 className="text-[21px] font-semibold text-slate-900">info@unite.be</h1>
      <p className="mb-4 text-[13px] text-slate-500">
        Boîte générale de l'unité · synchronisation activée · dernière lecture il y a 7 minutes
      </p>

      <h2 className="mb-1 text-[15px] font-semibold text-slate-900">Modules qui utilisent cette boîte</h2>
      <p className="mb-3 text-[13px] text-slate-500">
        Autoriser un module lui permet d'analyser les messages de cette boîte pour les rattacher à ses propres
        éléments. Le tri décide de ce que ses utilisateurs peuvent lire.
      </p>

      <div className="space-y-3">
        {Object.entries(modules).map(([name, cfg]) => (
          <div key={name} className="rounded border border-slate-200 p-3">
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={cfg.on} onChange={(e) => set(name, { on: e.target.checked, mode: e.target.checked ? cfg.mode : "none" })} className="h-4 w-4" />
              <span className="text-[15px] font-medium text-slate-900">{name}</span>
            </label>

            {cfg.on && (
              <div className="mt-2 space-y-1.5 pl-6">
                {[
                  ["none", "Aucun tri", "Le module rattache automatiquement ce qu'il reconnaît, mais n'ouvre aucune liste de courrier à ses utilisateurs."],
                  ["relevant", "Messages concernés uniquement", "Ses utilisateurs voient les messages que le module a rattachés à un élément qu'ils gèrent, ou pense pouvoir y rattacher."],
                  ["all", "Tous les messages de la boîte", "Ses utilisateurs voient l'intégralité du courrier reçu ici, associé ou non."],
                ].map(([value, label, help]) => (
                  <label key={value} className="flex cursor-pointer gap-2">
                    <input
                      type="radio"
                      name={`mode-${name}`}
                      checked={cfg.mode === value}
                      onChange={() => set(name, { mode: value })}
                      className="mt-1 h-4 w-4"
                    />
                    <span>
                      <span className="text-[14px] text-slate-900">{label}</span>
                      <span className="block text-[12px] text-slate-500">{help}</span>
                    </span>
                  </label>
                ))}

                {cfg.mode === "all" && (
                  <div className="mt-2 rounded border border-red-200 bg-red-50 p-2.5 text-[13px] text-red-800">
                    <strong>Attention.</strong> Les utilisateurs à qui {name} donne accès à son courrier pourront lire{" "}
                    <strong>tous</strong> les messages reçus dans info@unite.be, y compris ceux qui ne concernent pas ce
                    module. Réservez ce réglage à une boîte dédiée.
                    <div className="mt-1.5 text-[12px]">
                      Aujourd'hui, {name} ouvre son courrier au Staff d'U et aux gestionnaires de biens (14 personnes).
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        ))}
      </div>

      <div className="mt-5 rounded border border-slate-200 bg-slate-50 p-3 text-[13px] text-slate-600">
        <div className="mb-1 font-medium text-slate-800">Conservation</div>
        Les messages sans association ni proposition sont supprimés après <strong>90 jours</strong>. Les messages
        associés sont conservés tant que l'élément qui les utilise existe. Réglable dans Configuration &gt; Paramètres.
      </div>

      <div className="mt-4">
        <Btn variant="primary" size="md">Enregistrer</Btn>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ app */

export default function App() {
  const [mobile, setMobile] = useState(false);
  const [role, setRole] = useState("admin");
  const [screen, setScreen] = useState("list");
  const [open, setOpen] = useState(null);
  const [showBulk, setShowBulk] = useState(false);
  const [filterLink, setFilterLink] = useState("none");
  const [filterMailbox, setFilterMailbox] = useState("all");

  const roles = [
    ["admin", "Chef d'Unité", "Boîte générale — tout le courrier de l'unité"],
    ["rental", "Gestionnaire de biens", "Tri Locations — seulement ses biens"],
    ["superadmin", "Superadmin", "Configuration technique des boîtes"],
  ];
  const current = roles.find((r) => r[0] === role);

  const goList = () => {
    setScreen("list");
    setOpen(null);
  };

  return (
    <div className="min-h-screen bg-slate-100 p-4 font-sans text-slate-900">
      <div className="mx-auto max-w-[1100px]">
        <div className="mb-4 rounded-lg border border-slate-300 bg-white p-3">
          <div className="mb-2 text-[12px] font-semibold uppercase tracking-wide text-slate-500">
            Maquette — contrôles
          </div>
          <div className="flex flex-wrap items-center gap-4">
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
            <div className="flex flex-wrap gap-1">
              {roles.map(([k, l]) => (
                <button
                  key={k}
                  onClick={() => {
                    setRole(k);
                    setScreen(k === "superadmin" ? "config" : "list");
                    setOpen(null);
                  }}
                  className={`rounded px-3 py-1.5 text-[13px] ${
                    role === k ? "bg-blue-600 text-white" : "border border-slate-300 text-slate-600"
                  }`}
                >
                  {l}
                </button>
              ))}
            </div>
          </div>
          <div className="mt-2 text-[12px] text-slate-500">{current[2]}</div>
        </div>

        <div
          className={`mx-auto rounded-lg border border-slate-300 bg-white shadow-sm ${
            mobile ? "w-[375px] p-4" : "w-full p-6"
          }`}
        >
          {role === "superadmin" ? (
            <MailboxConfig mobile={mobile} />
          ) : screen === "list" ? (
            <InboxList
              mobile={mobile}
              role={role}
              onOpen={(m) => {
                setOpen(m);
                setScreen("detail");
              }}
              showBulk={showBulk}
              setShowBulk={setShowBulk}
              filterLink={filterLink}
              setFilterLink={setFilterLink}
              filterMailbox={filterMailbox}
              setFilterMailbox={setFilterMailbox}
            />
          ) : (
            <MessageDetail m={open} mobile={mobile} role={role} onBack={goList} />
          )}
        </div>
      </div>
    </div>
  );
}
