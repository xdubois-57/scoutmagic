import React, { useState } from "react";

/**
 * ScoutMagic — maquette : gestion des réinscriptions en deux sous-pages.
 *
 *   Tableau de bord — l'état de la campagne et la boîte « Relancer maintenant »,
 *                     où chaque étape dit ce qui est parti OU ce qui est prévu.
 *   Réglages        — les dates, les rappels et les deux interrupteurs.
 *
 * Le rail de pastilles est le page_picker partagé ; le fil d'Ariane porte
 * tous les niveaux : Espace chefs d'U / Réinscriptions / Réglages.
 *
 * La règle de fond : il n'y a, à tout moment, qu'UNE campagne qu'on puisse
 * ouvrir — celle de l'année visée, c'est-à-dire l'année scoute qui vient. Ouvrir
 * à la main l'ouvre maintenant ; sa fermeture, ses rappels et sa clôture
 * restent ceux des réglages. Hors des dates, il n'y a donc plus à deviner de
 * quelle campagne il s'agit, et le dialogue nomme toujours l'année.
 *
 * Règle de l'enregistrement : tout enregistrement qui change quelque chose se
 * confirme, et le dialogue répond toujours à « un e-mail va-t-il partir ? » —
 * oui, combien et lesquels ; non, et pourquoi.
 *
 * Huit situations : la boîte « Relancer maintenant » ne dit pas la même chose
 * selon l'endroit où l'on en est dans l'année.
 *
 * Le produit est en Bootstrap 5 ; Tailwind n'est ici que pour dessiner vite.
 */

const STEP_LABELS = {
  opening: "Ouverture",
  reminder_1: "Premier rappel",
  reminder_2: "Second rappel",
  closing: "Fermeture",
};

const CAMPAIGN = {
  target: "2027-2028",
  closeLabel: "15/05/2027",
  remindersLabel: "le 01/05/2027 et le 13/05/2027",
};

const PLANNED = [
  { key: "opening", state: "planned", date: "01/03/2027" },
  { key: "reminder_1", state: "planned", date: "01/05/2027" },
  { key: "reminder_2", state: "planned", date: "13/05/2027" },
  { key: "closing", state: "planned", date: "15/05/2027" },
];

// openingEmailSent / closingEmailSent : l'e-mail de CETTE campagne est-il déjà
// parti ? Les e-mails sont marqués par campagne : rouvrir ou refermer ne les
// renvoie jamais.
const SITUATIONS = {
  before: {
    label: "Avant l'ouverture", sub: "20 février 2027 — fermée", todayMD: "02-20",
    isOpen: false, emailsOn: true, openingEmailSent: false, closingEmailSent: false, ...CAMPAIGN,
    campaign: "Campagne pour 2027-2028 : du 01/03/2027 au 15/05/2027",
    steps: PLANNED, rem1: 14,
  },
  between: {
    label: "Entre deux campagnes", sub: "4 octobre 2026 — fermée", todayMD: "10-04",
    isOpen: false, emailsOn: true, openingEmailSent: false, closingEmailSent: false, ...CAMPAIGN,
    durationNote: "soit plus de sept mois",
    campaign: "Campagne pour 2027-2028 : du 01/03/2027 au 15/05/2027",
    steps: PLANNED,
    history: "Campagne précédente, pour 2026-2027 : clôturée le 15/05/2026.",
    rem1: 14,
  },
  byhand: {
    label: "Ouverte à la main", sub: "4 octobre 2026 — 10:35", todayMD: "10-04",
    isOpen: true, openedByHand: true, emailsOn: true, openingEmailSent: true, closingEmailSent: false, ...CAMPAIGN,
    campaign: "Campagne pour 2027-2028 : ouverte le 04/10/2026, fermeture le 15/05/2027",
    steps: [
      { key: "opening", state: "sent", at: "04/10/2026 à 10:35 (ouverte à la main)" },
      { key: "reminder_1", state: "planned", date: "01/05/2027" },
      { key: "reminder_2", state: "planned", date: "13/05/2027" },
      { key: "closing", state: "planned", date: "15/05/2027" },
    ],
    rem1: 14,
  },
  running: {
    label: "Campagne en cours", sub: "20 avril 2027", todayMD: "04-20",
    isOpen: true, emailsOn: true, openingEmailSent: true, closingEmailSent: false, ...CAMPAIGN,
    campaign: "Campagne pour 2027-2028 : du 01/03/2027 au 15/05/2027",
    steps: [
      { key: "opening", state: "sent", at: "01/03/2027 à 08:04" },
      { key: "reminder_1", state: "planned", date: "01/05/2027" },
      { key: "reminder_2", state: "planned", date: "13/05/2027" },
      { key: "closing", state: "planned", date: "15/05/2027" },
    ],
    rem1: 14,
  },
  missed: {
    label: "Date manquée", sub: "3 mai 2027", todayMD: "05-03",
    isOpen: true, emailsOn: true, openingEmailSent: true, closingEmailSent: false, ...CAMPAIGN,
    campaign: "Campagne pour 2027-2028 : du 01/03/2027 au 15/05/2027",
    steps: [
      { key: "opening", state: "sent", at: "01/03/2027 à 08:04" },
      { key: "reminder_1", state: "missed", date: "01/05/2027" },
      { key: "reminder_2", state: "planned", date: "13/05/2027" },
      { key: "closing", state: "planned", date: "15/05/2027" },
    ],
    rem1: 14,
  },
  skipped: {
    label: "Rappel sauté", sub: "20 avril 2027 — premier rappel à 90 jours", todayMD: "04-20",
    isOpen: true, emailsOn: true, openingEmailSent: true, closingEmailSent: false, ...CAMPAIGN,
    campaign: "Campagne pour 2027-2028 : du 01/03/2027 au 15/05/2027",
    steps: [
      { key: "opening", state: "sent", at: "01/03/2027 à 08:04" },
      { key: "reminder_1", state: "skipped", date: "14/02/2027" },
      { key: "reminder_2", state: "planned", date: "13/05/2027" },
      { key: "closing", state: "planned", date: "15/05/2027" },
    ],
    rem1: 90,
  },
  off: {
    label: "E-mails désactivés", sub: "20 avril 2027", todayMD: "04-20",
    isOpen: true, emailsOn: false, openingEmailSent: false, closingEmailSent: false, ...CAMPAIGN,
    campaign: "Campagne pour 2027-2028 : du 01/03/2027 au 15/05/2027",
    steps: [
      { key: "opening", state: "off" },
      { key: "reminder_1", state: "off" },
      { key: "reminder_2", state: "off" },
      { key: "closing", state: "off" },
    ],
    rem1: 14,
  },
  late: {
    label: "Après la clôture", sub: "12 juin 2027 — fermée", todayMD: "06-12",
    isOpen: false, emailsOn: true, openingEmailSent: true, closingEmailSent: true, ...CAMPAIGN,
    campaign: "Campagne pour 2027-2028 : du 01/03/2027 au 15/05/2027",
    steps: [
      { key: "opening", state: "sent", at: "01/03/2027 à 08:04" },
      { key: "reminder_1", state: "sent", at: "01/05/2027 à 08:02" },
      { key: "reminder_2", state: "sent", at: "13/05/2027 à 08:03" },
      { key: "closing", state: "sent", at: "15/05/2027 à 23:30" },
    ],
    rem1: 14,
  },
};

function StepValue({ s }) {
  if (s.state === "sent") return <span>Envoyé le {s.at}</span>;
  if (s.state === "planned")
    return (
      <span>
        <span className="text-slate-500">Pas encore envoyé</span>
        <span className="text-slate-400"> · </span>
        <b>prévu le {s.date}</b>
      </span>
    );
  if (s.state === "missed")
    return (
      <span>
        <span className="text-amber-700">Pas envoyé</span>
        <span className="text-slate-400"> · </span>
        <span className="text-slate-600">prévu le {s.date}, date passée</span>
      </span>
    );
  if (s.state === "skipped")
    return (
      <span>
        <span className="text-slate-500">Sauté</span>
        <span className="text-slate-400"> · </span>
        <span className="text-slate-600">{s.date} tombe avant l'ouverture</span>
      </span>
    );
  return <span className="text-slate-500">Désactivé</span>;
}

// La phrase du dialogue « Relancer maintenant » vient des MÊMES données que la
// boîte : il n'y a pas deux sources qui puissent se contredire.
function dialogText(sit) {
  const sentReminders = sit.steps.filter((s) => s.key.startsWith("reminder") && s.state === "sent");
  const next = sit.steps.find((s) => s.key.startsWith("reminder") && s.state === "planned");
  let t = "27 e-mails vont partir : une relance à chaque famille qui n'a pas encore répondu. L'envoi est programmé : il part dans quelques minutes, et rien ici ne le rappelle.";
  t += sentReminders.length ? " Un rappel automatique a déjà été envoyé." : " Aucun rappel automatique n'a encore été envoyé.";
  t += next ? ` Prochain rappel automatique prévu le ${next.date}.` : " Aucun autre rappel automatique n'est prévu.";
  return t + " Relancer maintenant ?";
}

export default function MaquetteReinscriptions() {
  const [situation, setSituation] = useState("between");
  const [page, setPage] = useState("dash");
  const [dialog, setDialog] = useState(false);

  const init = (k) => ({ open: SITUATIONS[k].isOpen, emails: SITUATIONS[k].emailsOn, openAt: "03-01", closeAt: "05-15", rem1: String(SITUATIONS[k].rem1), rem2: "2" });
  const [saved, setSaved] = useState(init("between"));
  const [form, setForm] = useState(init("between"));
  const [sent, setSent] = useState({ opening: false, closing: false });
  const [saveDialog, setSaveDialog] = useState(null);
  const [saveMsg, setSaveMsg] = useState(null);
  const setF = (k, v) => { setForm((f) => ({ ...f, [k]: v })); setSaveMsg(null); };

  const sit = SITUATIONS[situation];

  function pickSituation(k) {
    setSituation(k); setDialog(false); setSaveDialog(null); setSaveMsg(null);
    setSaved(init(k)); setForm(init(k));
    setSent({ opening: SITUATIONS[k].openingEmailSent, closing: SITUATIONS[k].closingEmailSent });
  }

  // Le plan : ce qui change, ce qui partira. Calculé une seule fois côté
  // serveur ; le dialogue l'affiche, l'enregistrement l'applique.
  function plan() {
    const changes = [];
    if (form.openAt !== saved.openAt) changes.push(`Ouverture : ${saved.openAt} → ${form.openAt}`);
    if (form.closeAt !== saved.closeAt) changes.push(`Fermeture : ${saved.closeAt} → ${form.closeAt}`);
    if (form.rem1 !== saved.rem1) changes.push(`Premier rappel : ${saved.rem1} → ${form.rem1} jours avant la fermeture`);
    if (form.rem2 !== saved.rem2) changes.push(`Second rappel : ${saved.rem2} → ${form.rem2} jours avant la fermeture`);
    if (form.emails !== saved.emails) changes.push(form.emails ? "E-mails de la campagne : désactivés → activés" : "E-mails de la campagne : activés → désactivés");
    if (form.open !== saved.open) changes.push(form.open ? "Campagne : fermée → ouverte" : "Campagne : ouverte → fermée");
    if (!changes.length) return null;

    const opening = form.open && !saved.open;
    const closing = !form.open && saved.open;
    const opensToday = !saved.open && !form.open && form.openAt !== saved.openAt && form.openAt === sit.todayMD;
    let mail = null, none = "Cet enregistrement ne change que des réglages : rien ne part maintenant.";
    let campaign = null;

    if (opening || opensToday) {
      campaign = {
        title: `Ouvre la campagne de réinscription pour ${sit.target}`,
        detail: `Fermeture le ${sit.closeLabel}, rappels ${sit.remindersLabel}.`
          + (sit.durationNote ? ` La campagne restera ouverte jusqu'au ${sit.closeLabel}, ${sit.durationNote}.` : ""),
      };
      if (!form.emails) none = "Les e-mails de la campagne sont désactivés : l'ouverture n'écrit à personne.";
      else if (sent.opening) none = "L'e-mail d'ouverture de cette campagne est déjà parti : le rouvrir n'écrit à personne.";
      else mail = { n: 41, what: "l'e-mail d'ouverture", to: "à toutes les familles", kind: "opening" };
    } else if (closing) {
      campaign = {
        title: `Ferme la campagne de réinscription pour ${sit.target}`,
        detail: `Elle devait se fermer le ${sit.closeLabel}.`,
      };
      if (!form.emails) none = "Les e-mails de la campagne sont désactivés : la fermeture n'écrit à personne.";
      else if (sent.closing) none = "L'e-mail de clôture de cette campagne est déjà parti : la refermer n'écrit à personne.";
      else mail = { n: 27, what: "l'e-mail de clôture", to: "aux familles qui n'ont pas répondu", kind: "closing" };
    } else if (form.emails !== saved.emails) {
      none = form.emails
        ? "Rien ne part maintenant : les e-mails prévus partiront à leurs dates."
        : "Plus aucun e-mail ne partira, ni automatique ni à la demande.";
    }
    return { changes, mail, none, campaign };
  }

  function save() {
    const p = plan();
    if (!p) return setSaveMsg("Aucun changement à enregistrer.");
    setSaveDialog(p);
  }

  function confirmSave() {
    const p = saveDialog;
    setSaved(form);
    if (p.mail) setSent((s) => ({ ...s, [p.mail.kind]: true }));
    setSaveMsg(p.mail
      ? `Enregistré. ${p.mail.what.charAt(0).toUpperCase() + p.mail.what.slice(1)} est programmé : il part dans quelques minutes.`
      : "Enregistré. Aucun e-mail n'est parti.");
    setSaveDialog(null);
  }

  const canRemind = saved.open && saved.emails;

  return (
    <div className="min-h-screen bg-slate-100 p-4 text-slate-800">
      <div className="mx-auto" style={{ maxWidth: 640 }}>
        <div className="mb-4 flex flex-wrap gap-2">
          {Object.entries(SITUATIONS).map(([k, s]) => (
            <button key={k} onClick={() => pickSituation(k)}
              className={`rounded border px-3 py-2 text-xs ${situation === k ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>
              {s.label}<span className="block text-slate-400">{s.sub}</span>
            </button>
          ))}
        </div>

        <div className="mb-2 text-xs leading-relaxed text-slate-500">
          <span className="text-blue-600">⌂</span> / <span className="text-blue-600">Espace chefs d'U</span> /{" "}
          {page === "dash" ? <span>Réinscriptions</span> : <><span className="text-blue-600">Réinscriptions</span> / <span>Réglages</span></>}
        </div>
        <h1 className="mb-1 text-xl font-semibold">Réinscriptions</h1>
        <p className="mb-3 text-xs text-slate-500">Quand demander aux familles si leur enfant revient, et où en est la campagne.</p>

        <div className="mb-4 flex gap-2 overflow-x-auto pb-1">
          {[["dash", "Tableau de bord"], ["settings", "Réglages"]].map(([k, l]) => (
            <button key={k} onClick={() => setPage(k)}
              className={`whitespace-nowrap rounded border px-3 py-2 text-xs ${page === k ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>{l}</button>
          ))}
        </div>

        {/* ═════════ TABLEAU DE BORD ═════════ */}
        {page === "dash" && (
          <>
            <div className="mb-3 rounded-lg border bg-white px-4 py-4">
              <h2 className="mb-3 text-base font-semibold">État</h2>
              <p className="mb-3 text-sm">
                {saved.open
                  ? <span className="rounded bg-green-600 px-2 py-0.5 text-xs text-white">Ouverte</span>
                  : <span className="rounded bg-slate-500 px-2 py-0.5 text-xs text-white">Fermée</span>}
                {sit.openedByHand && saved.open && <span className="ml-2 text-xs text-slate-600">Ouverte à la main, avant la date prévue.</span>}
              </p>
              <div className="grid grid-cols-2 gap-2">
                {[["31 / 58", "réponses reçues"], ["27", "sans réponse"], ["3", "départs annoncés"], [sit.target, "année visée"]].map(([v, l]) => (
                  <div key={l} className="rounded border p-2 text-center">
                    <div className="text-lg">{v}</div>
                    <div className="text-xs text-slate-500">{l}</div>
                  </div>
                ))}
              </div>
              <p className="mt-3 text-xs text-slate-500">
                Ces chiffres comptent les animés de l'année en cours. Les décisions individuelles se lisent sur les
                pages « Départs de l'unité » et « Passages de branche ».
              </p>
            </div>

            <div className="mb-3 rounded-lg border bg-white px-4 py-4">
              <h2 className="mb-2 text-base font-semibold">Relancer maintenant</h2>
              <p className="mb-3 text-xs text-slate-600">
                Écrit à toutes les familles qui n'ont pas encore répondu pour tous leurs enfants. Un email par adresse,
                listant les enfants concernés.
              </p>

              <div className="mb-2 text-xs font-medium text-slate-700">{sit.campaign}</div>
              <dl className="mb-3 text-sm">
                {sit.steps.map((s) => (
                  <div key={s.key} className="mb-2">
                    <dt className="text-xs text-slate-500">{STEP_LABELS[s.key]}</dt>
                    <dd><StepValue s={s} /></dd>
                  </div>
                ))}
              </dl>

              {sit.history && <p className="mb-3 text-xs text-slate-500">{sit.history}</p>}

              {!saved.emails && (
                <p className="mb-3 text-xs leading-relaxed text-slate-600">
                  Les e-mails de la campagne sont désactivés : aucun ne part, ni automatique ni à la demande.{" "}
                  <button onClick={() => setPage("settings")} className="text-blue-600 underline">Les réactiver dans les réglages</button>.
                </p>
              )}

              <button disabled={!canRemind} onClick={() => setDialog(true)}
                className={`rounded border px-4 py-2 text-sm ${canRemind ? "border-blue-600 text-blue-600" : "border-slate-300 text-slate-400"}`}>
                Relancer les familles sans réponse
              </button>
            </div>
          </>
        )}

        {/* ═════════ RÉGLAGES ═════════ */}
        {page === "settings" && (
          <div className="rounded-lg border bg-white px-4 py-4">
            <h2 className="mb-3 text-base font-semibold">Réglages</h2>

            <label className="mb-1 block text-sm">Ouverture (MM-JJ)</label>
            <input value={form.openAt} onChange={(e) => setF("openAt", e.target.value)} className="mb-1 w-full rounded border px-3 py-2 text-sm" />
            <p className="mb-3 text-xs text-slate-500">
              Mois puis jour, sans année — la même date se rejoue chaque année. Une date manquée est manquée : la
              campagne ne s'ouvre pas rétroactivement.
            </p>

            <label className="mb-1 block text-sm">Fermeture (MM-JJ)</label>
            <input value={form.closeAt} onChange={(e) => setF("closeAt", e.target.value)} className="mb-3 w-full rounded border px-3 py-2 text-sm" />

            <label className="mb-1 block text-sm">Premier rappel (jours avant la fermeture)</label>
            <input value={form.rem1} onChange={(e) => setF("rem1", e.target.value)} className="mb-1 w-full rounded border px-3 py-2 text-sm" />
            <p className="mb-3 text-xs text-slate-500">
              Un rappel dont la date tombe avant l'ouverture est sauté, jamais envoyé en retard.
            </p>

            <label className="mb-1 block text-sm">Second rappel (jours avant la fermeture)</label>
            <input value={form.rem2} onChange={(e) => setF("rem2", e.target.value)} className="mb-3 w-full rounded border px-3 py-2 text-sm" />

            <div className="mb-3 flex items-start gap-3">
              <button type="button" role="switch" aria-checked={form.emails} onClick={() => setF("emails", !form.emails)}
                className={`relative mt-1 shrink-0 rounded-full ${form.emails ? "bg-blue-600" : "bg-slate-300"}`} style={{ width: 40, height: 22 }}>
                <span className="absolute rounded-full bg-white shadow" style={{ width: 18, height: 18, top: 2, left: form.emails ? 20 : 2 }} />
              </button>
              <div>
                <div className="text-sm">Envoyer les e-mails de la campagne</div>
                <p className="mt-1 text-xs leading-relaxed text-slate-500">
                  Actif, la campagne écrit d'elle-même aux familles : un e-mail d'<b>ouverture</b> à toutes quand elle
                  s'ouvre (à la date prévue ou par l'interrupteur ci-dessous), les deux <b>rappels automatiques</b> aux
                  familles sans réponse, et un e-mail de <b>clôture</b> à celles-ci quand elle se ferme. Chacun part une
                  seule fois par campagne. « Relancer maintenant » envoie en plus une relance à la demande. Inactif,
                  aucun de ces e-mails ne part : la campagne s'ouvre et se ferme quand même aux dates prévues.
                </p>
              </div>
            </div>

            <div className="mb-3 flex items-start gap-3">
              <button type="button" role="switch" aria-checked={form.open} onClick={() => setF("open", !form.open)}
                className={`relative mt-1 shrink-0 rounded-full ${form.open ? "bg-blue-600" : "bg-slate-300"}`} style={{ width: 40, height: 22 }}>
                <span className="absolute rounded-full bg-white shadow" style={{ width: 18, height: 18, top: 2, left: form.open ? 20 : 2 }} />
              </button>
              <div>
                <div className="text-sm">Campagne ouverte</div>
                <p className="mt-1 text-xs leading-relaxed text-slate-500">
                  Interrupteur manuel pour la campagne de réinscription pour <b>{sit.target}</b>. L'ouvrir ici l'ouvre
                  tout de suite : l'e-mail d'ouverture part s'il n'est pas encore parti pour cette campagne, et sa
                  fermeture, ses rappels et sa clôture restent ceux des réglages. La fermer envoie l'e-mail de clôture
                  aux familles sans réponse, s'il n'est pas déjà parti et si les e-mails sont actifs. Une confirmation
                  est demandée avant tout enregistrement.
                </p>
              </div>
            </div>

            <button onClick={save} className="rounded bg-blue-600 px-4 py-2 text-sm text-white">Enregistrer</button>
            {saveMsg && <p className="mt-3 rounded border border-green-300 bg-green-50 px-3 py-2 text-xs leading-relaxed">{saveMsg}</p>}
          </div>
        )}

        {/* ═════════ CONFIRMATION D'ENREGISTREMENT ═════════ */}
        {saveDialog && (
          <div className="fixed inset-0 flex items-center justify-center p-4" style={{ backgroundColor: "rgba(0,0,0,0.5)" }}>
            <div className="w-full rounded-lg bg-white shadow-xl" style={{ maxWidth: 440 }}>
              <div className="flex items-center border-b px-4 py-3">
                <span className="flex-1 text-base font-semibold">Confirmer l'enregistrement</span>
                <button onClick={() => setSaveDialog(null)} aria-label="Fermer" className="text-slate-500">✕</button>
              </div>
              <div className="px-4 py-4 text-sm leading-relaxed">
                {saveDialog.campaign && (
                  <div className="mb-4 rounded bg-slate-50 px-3 py-2">
                    <b>{saveDialog.campaign.title}</b>
                    <div className="mt-1 text-xs text-slate-600">{saveDialog.campaign.detail}</div>
                  </div>
                )}
                <div className="mb-1 text-xs font-semibold text-slate-500">Ce qui change</div>
                <ul className="mb-4 list-disc pl-5">
                  {saveDialog.changes.map((c) => <li key={c}>{c}</li>)}
                </ul>
                {saveDialog.mail ? (
                  <div className="rounded border border-amber-300 bg-amber-50 px-3 py-2">
                    <b>{saveDialog.mail.n} e-mails vont partir</b> dans quelques minutes : {saveDialog.mail.what}, {saveDialog.mail.to}.
                    <div className="mt-1 text-xs text-slate-600">Un e-mail envoyé ne se rappelle pas.</div>
                  </div>
                ) : (
                  <div className="rounded border border-green-300 bg-green-50 px-3 py-2">
                    <b>Aucun e-mail ne partira.</b>
                    <div className="mt-1 text-xs text-slate-600">{saveDialog.none}</div>
                  </div>
                )}
              </div>
              <div className="flex flex-col gap-2 border-t px-4 py-3">
                <button onClick={() => setSaveDialog(null)} className="rounded border border-slate-400 px-4 py-2 text-sm text-slate-600">Annuler</button>
                <button onClick={confirmSave}
                  className={`rounded px-4 py-2 text-sm text-white ${saveDialog.mail ? "bg-red-600" : "bg-blue-600"}`}>
                  {saveDialog.mail ? "Enregistrer et envoyer" : "Enregistrer"}
                </button>
              </div>
            </div>
          </div>
        )}

        {/* ═════════ DIALOGUE « RELANCER MAINTENANT » ═════════ */}
        {dialog && (
          <div className="fixed inset-0 flex items-center justify-center p-4" style={{ backgroundColor: "rgba(0,0,0,0.5)" }}>
            <div className="w-full rounded-lg bg-white shadow-xl" style={{ maxWidth: 440 }}>
              <div className="flex items-center border-b px-4 py-3">
                <span className="flex-1 text-base font-semibold">Confirmation</span>
                <button onClick={() => setDialog(false)} aria-label="Fermer" className="text-slate-500">✕</button>
              </div>
              <div className="px-4 py-4 text-sm leading-relaxed">{dialogText(sit)}</div>
              <div className="flex flex-col gap-2 border-t px-4 py-3">
                <button onClick={() => setDialog(false)} className="rounded border border-slate-400 px-4 py-2 text-sm text-slate-600">Annuler</button>
                <button onClick={() => setDialog(false)} className="rounded bg-red-600 px-4 py-2 text-sm text-white">Envoyer la relance</button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
