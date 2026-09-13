import React, { useState } from "react";

/**
 * ScoutMagic — maquette : « Délivrabilité » (Configuration, superadmin).
 *
 * Sous-pages avec un rail de navigation, sur le modèle de
 * modules/finance/views/config/_nav.html.twig (partials/page_picker.html.twig).
 *
 * Fait foi pour : le découpage en sous-pages, la mise en avant de la
 * configuration essentielle face aux options avancées, le parcours de la
 * sonde, le tri des sources DMARC, et le mode « boîtes témoins ».
 *
 * Le produit est en Bootstrap 5 et suit design.md §7 ; Tailwind n'est ici que
 * parce qu'une maquette se dessine plus vite avec.
 */

const PAGES = [
  ["dash", "Tableau de bord"],
  ["auth", "Authentification"],
  ["prov", "Fournisseurs"],
  ["dmarc", "Rapports DMARC"],
  ["probe", "Sonde"],
  ["seeds", "Boîtes témoins"],
  ["bounce", "Rebonds"],
];

const PROVIDERS = [
  { id: "a", name: "Brevo", host: "smtp-relay.brevo.com", used: 214, quota: 300, batch: 50, interval: 10 },
  { id: "b", name: "OVH", host: "ssl0.ovh.net", used: 0, quota: 500, batch: 100, interval: 5 },
  { id: "local", name: "Envoi local", host: "sans relais, directement depuis le serveur", used: 0, quota: null, batch: 20, interval: 15, permanent: true },
];

const LANES = [
  { key: "auth", label: "Authentification", detail: "Liens magiques, confirmations d'adresse", reserve: true },
  { key: "tx", label: "Transactionnel", detail: "Notifications, alertes, accusés de réception" },
  { key: "bulk", label: "Masse", detail: "Publipostage uniquement" },
];

const INITIAL_CHAINS = {
  auth: [{ id: "a", on: true }, { id: "b", on: true }, { id: "local", on: true }],
  tx: [{ id: "a", on: true }, { id: "b", on: true }, { id: "local", on: false }],
  bulk: [{ id: "b", on: true }, { id: "a", on: false }, { id: "local", on: false }],
};

const DMARC_SOURCES = [
  { ip: "1.179.112.23", name: "Brevo", mine: true, count: 318, ok: true },
  { ip: "51.68.45.9", name: "ssl0.ovh.net", mine: true, count: 44, ok: true },
  { ip: "209.85.220.41", name: "mail-sor-f41.google.com", mine: false, count: 26, ok: false, disp: "aucune action" },
  { ip: "185.230.63.107", name: "(nom inconnu)", mine: false, count: 9, ok: false, disp: "quarantaine" },
];

const SEEDS = [
  { box: "temoin.25sv@gmail.com", prov: "Gmail", last: "réception", ok: true },
  { box: "temoin.25sv@outlook.com", prov: "Outlook", last: "indésirables", ok: false },
  { box: "temoin.25sv@yahoo.com", prov: "Yahoo", last: "réception", ok: true },
];

const SEED_HISTORY = [
  { date: "9 sept.", camp: "Infos camp Baladins", prov: "Brevo", g: "inbox", o: "spam", y: "inbox" },
  { date: "22 août", camp: "Réunion de rentrée", prov: "Brevo", g: "inbox", o: "spam", y: "inbox" },
  { date: "3 août", camp: "Rappel cotisations", prov: "OVH", g: "inbox", o: "inbox", y: "inbox" },
];

const BOUNCES = [
  { id: 1, email: "m.dupont@skynet.be", count: 3, last: "8 sept.", reason: "550 — boîte inexistante", blocked: true },
  { id: 2, email: "famille.leroy@hotmail.com", count: 2, last: "9 sept.", reason: "452 — boîte pleine", blocked: false },
];

const DOMAINS = [
  { d: "gmail.com", sent: 186, failed: 1 },
  { d: "hotmail.com", sent: 120, failed: 9 },
  { d: "skynet.be", sent: 74, failed: 3 },
];

const V = {
  inbox: ["Réception", "bg-green-100 text-green-800"],
  spam: ["Indésirables", "bg-amber-100 text-amber-800"],
  promo: ["Promotions", "bg-blue-100 text-blue-800"],
  none: ["Jamais reçu", "bg-red-100 text-red-800"],
};

function Card({ title, children, tone }) {
  const border = tone === "warn" ? "border-amber-300" : tone === "bad" ? "border-red-300" : "border-slate-200";
  return (
    <div className={`mb-4 rounded-lg border bg-white ${border}`}>
      {title && <div className="border-b px-4 py-3 text-sm font-semibold">{title}</div>}
      {children}
    </div>
  );
}

function Status({ ok, children }) {
  return (
    <span className={`rounded px-2 py-0.5 text-xs ${ok ? "bg-green-100 text-green-800" : "bg-amber-100 text-amber-800"}`}>
      {children}
    </span>
  );
}

export default function MaquetteDelivrabilite() {
  const [page, setPage] = useState("dash");
  const [seedsOn, setSeedsOn] = useState(false);
  const [autoRoute, setAutoRoute] = useState(false);
  const [pending, setPending] = useState(null);
  const [probes, setProbes] = useState([
    { id: 2, date: "9 sept.", to: "xavier@hotmail.com", provider: "Brevo", verdict: "spam" },
    { id: 1, date: "9 sept.", to: "xavier@hotmail.com", provider: "OVH", verdict: "inbox" },
  ]);
  const [bounces, setBounces] = useState(BOUNCES);
  const [check, setCheck] = useState("ok");
  const [chains, setChains] = useState(INITIAL_CHAINS);

  function move(lane, i, dir) {
    setChains((c) => {
      const list = [...c[lane]];
      const j = i + dir;
      if (j < 0 || j >= list.length) return c;
      [list[i], list[j]] = [list[j], list[i]];
      return { ...c, [lane]: list };
    });
  }
  function toggle(lane, i) {
    setChains((c) => {
      const list = c[lane].map((x, k) => (k === i ? { ...x, on: !x.on } : x));
      if (!list.some((x) => x.on)) return c; // jamais zéro actif
      return { ...c, [lane]: list };
    });
  }
  const provOf = (id) => PROVIDERS.find((p) => p.id === id);

  return (
    <div className="min-h-screen bg-slate-100 p-4 text-slate-800">
      <div className="mx-auto" style={{ maxWidth: 640 }}>
        <div className="mb-1 text-xs text-slate-500">Configuration</div>
        <h1 className="mb-3 text-xl font-semibold">Courrier sortant</h1>

        {/* rail de sous-pages */}
        <div className="mb-4 flex gap-2 overflow-x-auto pb-1">
          {PAGES.map(([k, label]) => (
            <button key={k} onClick={() => setPage(k)}
              className={`whitespace-nowrap rounded border px-3 py-2 text-xs ${page === k ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>
              {label}
            </button>
          ))}
        </div>

        {/* ─────────────── TABLEAU DE BORD ─────────────── */}
        {page === "dash" && (
          <>
            <Card title="Configuration essentielle">
              <div className="px-4 py-2 text-xs text-slate-600">
                Ces trois points suffisent. Tout le reste est facultatif.
              </div>
              {[
                ["Authentification du domaine", false, "DMARC manquant — c'est la cause la plus fréquente de messages en indésirables", "auth"],
                ["Un fournisseur d'envoi", true, "Brevo pour l'authentification et le transactionnel · réserve de 50 · OVH pour la masse", "prov"],
                ["Retours relevés par le site", true, "25sv@25sv.be arrive dans « Boîte générale » · vérifié le 9 sept.", "auth"],
              ].map(([label, ok, detail, target]) => (
                <div key={label} className="border-b px-4 py-3 last:border-0">
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-sm font-medium">{label}</span>
                    <Status ok={ok}>{ok ? "En place" : "À compléter"}</Status>
                  </div>
                  <div className="mt-1 text-xs text-slate-600">{detail}</div>
                  <button onClick={() => setPage(target)} className="mt-1 text-xs text-blue-600 underline">
                    {ok ? "Voir" : "Corriger"}
                  </button>
                </div>
              ))}
            </Card>

            <Card title="Ce que disent les retours">
              <div className="grid grid-cols-3 gap-2 px-4 py-3 text-center">
                <div>
                  <div className="text-lg font-semibold text-green-700">91 %</div>
                  <div className="text-xs text-slate-500">authentifiés (DMARC)</div>
                </div>
                <div>
                  <div className="text-lg font-semibold">13</div>
                  <div className="text-xs text-slate-500">rebonds ce mois</div>
                </div>
                <div>
                  <div className="text-lg font-semibold text-amber-700">1</div>
                  <div className="text-xs text-slate-500">adresse exclue</div>
                </div>
              </div>
              <div className="border-t px-4 py-3 text-xs leading-relaxed text-slate-600">
                Un message classé en indésirables n'apparaît nulle part ici : il a été accepté.
                Pour le savoir, utilisez la <button onClick={() => setPage("probe")} className="text-blue-600 underline">sonde</button>.
              </div>
            </Card>

            <Card title="Options avancées">
              {[
                ["Second fournisseur d'envoi", true, "OVH, en secours si Brevo échoue ou atteint son quota", "prov"],
                ["Boîtes témoins sur chaque envoi", seedsOn, "Mesure où arrivent réellement vos publipostages", "seeds"],
                ["Routage automatique par domaine", autoRoute, "Choisit le fournisseur selon le destinataire", "prov"],
              ].map(([label, on, detail, target]) => (
                <div key={label} className="border-b px-4 py-3 last:border-0">
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-sm">{label}</span>
                    <span className={`rounded px-2 py-0.5 text-xs ${on ? "bg-blue-100 text-blue-800" : "bg-slate-200 text-slate-600"}`}>
                      {on ? "Activé" : "Désactivé"}
                    </span>
                  </div>
                  <div className="mt-1 text-xs text-slate-500">{detail}</div>
                  <button onClick={() => setPage(target)} className="mt-1 text-xs text-blue-600 underline">Configurer</button>
                </div>
              ))}
            </Card>
          </>
        )}

        {/* ─────────────── AUTHENTIFICATION ─────────────── */}
        {page === "auth" && (
          <>
            <Card title="Enregistrements DNS">
              {[
                ["SPF", true, "v=spf1 include:_spf.brevo.com ~all"],
                ["DKIM", true, "sélecteur « scoutmagic » · clé RSA 2048 trouvée"],
                ["DMARC", false, "v=DMARC1; p=none; rua=mailto:25sv@25sv.be"],
              ].map(([n, ok, detail]) => (
                <div key={n} className="border-b px-4 py-3 last:border-0">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium">{n}</span>
                    <Status ok={ok}>{ok ? "En place" : "Absent"}</Status>
                  </div>
                  <div className="mt-1 break-all text-xs text-slate-600" style={{ fontFamily: "ui-monospace, monospace" }}>{detail}</div>
                  {!ok && (
                    <div className="mt-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-relaxed">
                      À créer chez votre registrar sur <b>_dmarc.25sv.be</b>. Google et Microsoft
                      exigent les trois pour accepter du courrier en nombre.
                      <button className="ml-1 underline">Copier</button>
                    </div>
                  )}
                </div>
              ))}
            </Card>

            <Card title="Adresses">
              <div className="px-4 py-4">
                <p className="mb-3 text-xs text-slate-600">
                  Une seule adresse joue quatre rôles : expéditeur affiché, réponses des parents,
                  retour des rebonds, et rapports DMARC. C'est le fonctionnement normal.
                </p>
                <label className="mb-1 block text-xs text-slate-500">Adresse d'expédition</label>
                <input defaultValue="25sv@25sv.be" className="mb-3 w-full rounded border px-2 py-2 text-sm" />
                <label className="mb-1 block text-xs text-slate-500">Adresse de réponse (facultatif)</label>
                <input placeholder="identique à l'expédition" className="mb-3 w-full rounded border px-2 py-2 text-sm" />
                <label className="mb-1 block text-xs text-slate-500">Adresse des rapports DMARC</label>
                <input defaultValue="25sv@25sv.be" className="mb-3 w-full rounded border px-2 py-2 text-sm" />

                <div className="rounded border px-3 py-3">
                  <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Les retours arrivent-ils dans une boîte relevée par le site ?
                  </div>
                  {check === "ok" && (
                    <div className="mb-2 rounded border border-green-300 bg-green-50 px-3 py-2 text-xs leading-relaxed">
                      <b>Vérifié le 9 sept.</b> Arrivé dans « Boîte générale de l'unité ».
                      Les rebonds et les rapports DMARC y seront traités.
                    </div>
                  )}
                  {check === "ko" && (
                    <div className="mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-relaxed">
                      <b>Jamais arrivé.</b> Les rebonds ne seront pas traités, ni les rapports DMARC.
                      Les envois continuent normalement.
                    </div>
                  )}
                  <div className="flex gap-2">
                    <button onClick={() => setCheck("ok")} className="rounded border border-blue-600 px-3 py-2 text-sm text-blue-600">
                      Vérifier les retours
                    </button>
                    <button onClick={() => setCheck("ko")} className="rounded border px-3 py-2 text-xs text-slate-400">
                      (maquette : échec)
                    </button>
                  </div>
                </div>
                <p className="mt-3 text-xs text-slate-500">
                  Un alias ou une redirection convient : c'est pour ça que la vérification envoie un
                  vrai message plutôt que de comparer des noms.
                </p>
              </div>
            </Card>
          </>
        )}

        {/* ─────────────── FOURNISSEURS ─────────────── */}
        {page === "prov" && (
          <>
            <Card title="Acheminement">
              <div className="px-4 py-3 text-xs text-slate-600">
                Pour chaque type de message, l'ordre de la liste donne la chaîne de repli : on
                essaie le premier actif, puis le suivant s'il échoue ou atteint son quota.
                Glisser-déposer sur grand écran, flèches sur mobile.
              </div>

              {LANES.map((lane) => (
                <div key={lane.key} className="border-b px-4 py-3 last:border-0">
                  <div className="text-sm font-medium">{lane.label}</div>
                  <div className="mb-2 text-xs text-slate-500">{lane.detail}</div>

                  <div className="overflow-hidden rounded border">
                    {chains[lane.key].map((entry, i) => {
                      const p = provOf(entry.id);
                      const rank = chains[lane.key].filter((x, k) => x.on && k < i).length + 1;
                      return (
                        <div key={entry.id}
                          className={`flex items-center gap-2 border-b px-2 py-2 last:border-0 ${entry.on ? "" : "bg-slate-50"}`}>
                          <span className="cursor-grab text-slate-300">⠿</span>
                          <div className="flex-1">
                            <div className={`text-sm ${entry.on ? "" : "text-slate-400"}`}>
                              {entry.on && <span className="mr-1 text-xs text-slate-400">{rank}.</span>}
                              {p.name}
                              {p.permanent && (
                                <span className="ml-2 rounded bg-slate-200 px-1 text-xs text-slate-600">toujours présent</span>
                              )}
                              {p.permanent && lane.key === "bulk" && entry.on && (
                                <span className="ml-1 rounded bg-amber-100 px-1 text-xs text-amber-800">déconseillé ici</span>
                              )}
                            </div>
                            <div className="text-xs text-slate-400">{p.host}</div>
                          </div>
                          <button onClick={() => move(lane.key, i, -1)} className="px-1 text-slate-400">↑</button>
                          <button onClick={() => move(lane.key, i, 1)} className="px-1 text-slate-400">↓</button>
                          <label className="flex items-center">
                            <input type="checkbox" checked={entry.on} onChange={() => toggle(lane.key, i)} />
                          </label>
                        </div>
                      );
                    })}
                  </div>

                  {lane.reserve && (
                    <div className="mt-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-relaxed">
                      <b>Réservé sur Brevo : 42 messages par jour.</b> C'est votre pointe hors
                      publipostage des 30 derniers jours, plus une marge — le publipostage s'arrête
                      avant d'y toucher et reprend le lendemain. Calculé automatiquement, et
                      seulement sur un fournisseur qui sert aussi la voie masse.
                    </div>
                  )}
                </div>
              ))}

              <div className="border-t bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-600">
                Ici Brevo est désactivé dans la voie « Masse » : le publipostage ne peut donc plus
                rien consommer du quota dont dépendent les liens magiques. L'envoi local reste en
                dernier recours partout ; il ne peut pas être retiré, seulement désactivé.
                <div className="mt-2">
                  Quand une voie bascule sur le fournisseur suivant — échec ou quota atteint — elle
                  adopte <b>sa</b> cadence et <b>son</b> quota, jamais ceux du précédent.
                </div>
              </div>
            </Card>

            <Card title="Fournisseurs configurés">
              {PROVIDERS.map((p) => (
                <div key={p.id} className="border-b px-4 py-3 last:border-0">
                  <div className="flex items-center justify-between">
                    <div>
                      <span className="text-sm font-medium">{p.name}</span>
                      <span className="ml-2 text-xs text-slate-500">{p.host}</span>
                    </div>
                    <button className="text-xs text-blue-600 underline">
                      {p.permanent ? "Voir" : "Modifier"}
                    </button>
                  </div>
                  {p.quota !== null ? (
                    <>
                      <div className="mt-2 h-2 w-full overflow-hidden rounded bg-slate-200">
                        <div className="h-2 bg-blue-600" style={{ width: `${(p.used / p.quota) * 100}%` }} />
                      </div>
                      <div className="mt-1 text-xs text-slate-600">
                        {p.used} / {p.quota} aujourd'hui
                        {p.id === "a" && <span className="text-amber-700"> · dont 42 réservés</span>}
                      </div>
                    </>
                  ) : (
                    <div className="mt-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-relaxed">
                      Aucun quota journalier connu. Part directement depuis le serveur, sans relais :
                      la délivrabilité dépend de la réputation de son adresse IP, et beaucoup
                      d'hébergeurs bloquent le port 25 sortant. À garder en dernier recours.
                    </div>
                  )}
                  <div className="mt-1 flex flex-wrap gap-1 text-xs">
                    {LANES.filter((l) => chains[l.key].some((e) => e.id === p.id && e.on)).map((l) => (
                      <span key={l.key} className="rounded bg-slate-100 px-2 py-0.5 text-slate-600">{l.label}</span>
                    ))}
                  </div>

                  <details className="mt-2">
                    <summary className="cursor-pointer text-xs text-blue-600">Avancé</summary>
                    <div className="mt-2 rounded border px-3 py-2">
                      <div className="mb-2 text-xs text-slate-500">
                        Cadence — s'applique à la voie masse uniquement. L'authentification et le
                        transactionnel partent immédiatement.
                      </div>
                      <div className="flex flex-wrap items-center gap-2 text-xs">
                        <input defaultValue={p.batch} className="w-16 rounded border px-2 py-1" />
                        <span>messages toutes les</span>
                        <input defaultValue={p.interval} className="w-16 rounded border px-2 py-1" />
                        <span>minutes</span>
                      </div>
                      {p.permanent && (
                        <div className="mt-2 rounded bg-slate-50 px-2 py-1 text-xs text-slate-600">
                          Défaut volontairement prudent : sans relais pour absorber les à-coups,
                          une file locale qui gonfle est ce qui fait suspendre un compte
                          d'hébergement ou bloquer la sortie.
                        </div>
                      )}
                      <div className="mt-2 text-xs text-slate-500">
                        {p.quota !== null ? (
                          <>Quota journalier<input defaultValue={p.quota} className="ml-2 w-20 rounded border px-2 py-1" /></>
                        ) : (
                          <>Pas de quota journalier — rien à réserver sur ce fournisseur.</>
                        )}
                      </div>
                    </div>
                  </details>
                </div>
              ))}
              <div className="border-t px-4 py-3">
                <button className="text-sm text-blue-600 underline">+ Ajouter un fournisseur</button>
                <p className="mt-2 text-xs text-slate-500">
                  Un fournisseur ajouté arrive en bas des trois listes, désactivé partout : il
                  n'est utilisé nulle part tant que vous ne l'avez pas activé dans une voie, depuis
                  <button onClick={() => setPage("prov")} className="mx-1 text-blue-600 underline">Acheminement</button>
                  ci-dessus.
                </p>
              </div>
            </Card>

            <Card title="Routage par domaine destinataire">
              <div className="px-4 py-3">
                <div className="mb-3 rounded border border-blue-200 bg-blue-50 px-3 py-3 text-xs leading-relaxed">
                  <b>Constat sur les 6 derniers envois :</b> chez <b>hotmail.com</b>, Brevo place
                  4 exemplaires sur 6 en indésirables, OVH aucun.
                  <button className="mt-2 block rounded bg-blue-600 px-3 py-2 text-xs text-white">
                    Router hotmail.com via OVH
                  </button>
                </div>
                <label className="flex items-start gap-2 text-sm">
                  <input type="checkbox" checked={autoRoute} onChange={(e) => setAutoRoute(e.target.checked)} className="mt-1" />
                  <span>
                    Appliquer ces règles automatiquement
                    <span className="block text-xs text-slate-500">
                      Demande au moins 10 observations par domaine. Fractionner les envois prive
                      chaque fournisseur du volume régulier dont sa réputation dépend — à n'activer
                      que si un problème est avéré.
                    </span>
                  </span>
                </label>
                <p className="mt-2 text-xs text-slate-500">
                  Ce routage ne s'applique qu'à la voie « masse ». L'authentification ne change
                  jamais de chemin selon le destinataire.
                </p>
              </div>
            </Card>
          </>
        )}

        {/* ─────────────── DMARC ─────────────── */}
        {page === "dmarc" && (
          <>
            <Card title="30 derniers jours">
              <div className="px-4 py-3">
                <div className="flex items-baseline justify-between">
                  <span className="text-sm">41 rapports de 6 fournisseurs</span>
                  <span className="text-sm font-semibold text-green-700">91 % authentifiés</span>
                </div>
                <div className="mt-2 flex h-2 w-full overflow-hidden rounded bg-slate-200">
                  <div className="h-2 bg-green-500" style={{ width: "91%" }} />
                  <div className="h-2 bg-red-500" style={{ width: "9%" }} />
                </div>
              </div>
            </Card>

            <Card title="Vos fournisseurs">
              {DMARC_SOURCES.filter((s) => s.mine).map((s) => (
                <div key={s.ip} className="border-b px-4 py-3 last:border-0">
                  <div className="flex items-center gap-2 text-sm">
                    <span className="flex-1">{s.name}</span>
                    <span className="text-xs text-slate-500">{s.count} msg</span>
                    <Status ok>DKIM + SPF alignés</Status>
                  </div>
                  <div className="text-xs text-slate-400" style={{ fontFamily: "ui-monospace, monospace" }}>{s.ip}</div>
                </div>
              ))}
            </Card>

            <Card title="Autres sources écrivant en votre nom" tone="warn">
              {DMARC_SOURCES.filter((s) => !s.mine).map((s) => (
                <div key={s.ip} className="border-b px-4 py-3 last:border-0">
                  <div className="flex items-center gap-2 text-sm">
                    <span className="flex-1">{s.name}</span>
                    <span className="text-xs text-slate-500">{s.count} msg</span>
                    <span className="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">Non authentifié</span>
                  </div>
                  <div className="text-xs text-slate-400" style={{ fontFamily: "ui-monospace, monospace" }}>
                    {s.ip} · traité en {s.disp}
                  </div>
                </div>
              ))}
              <div className="border-t bg-amber-50 px-4 py-3 text-xs leading-relaxed">
                Une source inconnue est presque toujours un <b>outil oublié</b> — ancienne
                plateforme d'inscription, service de newsletter, boîte personnelle configurée avec
                l'adresse de l'unité — plus rarement une usurpation. Identifiez-la avant de passer
                à <b>p=reject</b> : ces messages-là seraient rejetés aussi.
              </div>
            </Card>

            <div className="px-1 text-xs text-slate-500">
              Un rapport DMARC dit si le message était <b>authentifié</b>, pas s'il a été lu. Ces
              rapports ne nomment aucun destinataire : ce ne sont que des compteurs.
            </div>
          </>
        )}

        {/* ─────────────── SONDE ─────────────── */}
        {page === "probe" && (
          <>
            <Card title="Envoyer une sonde">
              {!pending ? (
                <div className="px-4 py-4">
                  <p className="mb-3 text-xs text-slate-600">
                    Un message identique à un vrai envoi de l'unité, par le chemin de votre choix.
                    Regardez ensuite où il est arrivé dans votre boîte, et notez-le ici.
                  </p>
                  <label className="mb-1 block text-xs text-slate-500">Destination</label>
                  <input defaultValue="xavier@hotmail.com" className="mb-1 w-full rounded border px-2 py-2 text-sm" />
                  <p className="mb-3 text-xs text-slate-500">
                    Vous pouvez aussi coller ici l'adresse témoin d'un service d'analyse extérieur
                    pour obtenir un verdict chez plusieurs fournisseurs d'un coup.
                  </p>
                  <label className="mb-1 block text-xs text-slate-500">Fournisseur</label>
                  <select className="mb-3 w-full rounded border px-2 py-2 text-sm">
                    {PROVIDERS.map((p) => <option key={p.id}>{p.name}</option>)}
                  </select>
                  <label className="mb-1 block text-xs text-slate-500">Voie</label>
                  <select className="mb-1 w-full rounded border px-2 py-2 text-sm">
                    <option>Masse — celle du publipostage</option>
                    <option>Transactionnel</option>
                  </select>
                  <p className="mb-3 text-xs text-slate-500">La voie « masse » est celle qui pose problème.</p>
                  <button onClick={() => setPending({ code: "SM-7K2X" })}
                    className="w-full rounded bg-blue-600 px-4 py-2 text-sm text-white">
                    Envoyer la sonde
                  </button>
                </div>
              ) : (
                <div className="px-4 py-4">
                  <div className="mb-3 rounded border border-blue-200 bg-blue-50 px-3 py-3 text-sm leading-relaxed">
                    Message envoyé. Cherchez <b style={{ fontFamily: "ui-monospace, monospace" }}>{pending.code}</b> dans
                    le sujet — <b>y compris dans les indésirables</b>.
                  </div>
                  <div className="mb-2 text-xs text-slate-500">Où est-il arrivé ?</div>
                  <div className="flex flex-wrap gap-2">
                    {["inbox", "spam", "none"].map((v) => (
                      <button key={v}
                        onClick={() => { setProbes((ps) => [{ id: Date.now(), date: "aujourd'hui", to: "xavier@hotmail.com", provider: "Brevo", verdict: v }, ...ps]); setPending(null); }}
                        className="flex-1 rounded border px-3 py-2 text-sm">
                        {V[v][0]}
                      </button>
                    ))}
                  </div>
                  <p className="mt-2 text-xs text-slate-500">
                    Ne le sortez pas des indésirables : ça fausserait les tests suivants.
                  </p>
                </div>
              )}
            </Card>

            <Card title="Historique">
              <div className="px-4 py-2">
                {probes.map((p) => (
                  <div key={p.id} className="flex flex-wrap items-center gap-2 border-b py-2 text-xs last:border-0">
                    <span className="text-slate-500" style={{ minWidth: 80 }}>{p.date}</span>
                    <span className="flex-1">{p.to}</span>
                    <span className="rounded bg-slate-100 px-2 py-0.5">{p.provider}</span>
                    <span className={`rounded px-2 py-0.5 ${V[p.verdict][1]}`}>{V[p.verdict][0]}</span>
                  </div>
                ))}
              </div>
              <div className="border-t px-4 py-2 text-xs text-slate-500">
                Les deux lignes du 9 septembre tranchent : même destinataire, réception via OVH,
                indésirables via Brevo.
              </div>
            </Card>
          </>
        )}

        {/* ─────────────── BOÎTES TÉMOINS ─────────────── */}
        {page === "seeds" && (
          <>
            <Card title="Boîtes témoins sur chaque envoi de masse">
              <div className="px-4 py-4">
                <label className="mb-3 flex items-start gap-2 text-sm">
                  <input type="checkbox" checked={seedsOn} onChange={(e) => setSeedsOn(e.target.checked)} className="mt-1" />
                  <span>
                    Activer
                    <span className="block text-xs text-slate-500">
                      Chaque publipostage part aussi vers les boîtes ci-dessous. Le site y lit le
                      dossier d'arrivée, consigne le résultat, puis supprime le message.
                    </span>
                  </span>
                </label>

                <div className="rounded border border-amber-300 bg-amber-50 px-3 py-3 text-xs leading-relaxed">
                  <b>Réservé aux configurations avancées.</b> Chaque boîte demande ses identifiants
                  IMAP — chez Gmail et Outlook, un mot de passe d'application avec double
                  authentification. Utilisez uniquement des boîtes appartenant à l'unité : les
                  copies témoins contiennent le contenu réel de vos envois.
                </div>
              </div>
            </Card>

            {seedsOn && (
              <>
                <Card title="Boîtes déclarées">
                  {SEEDS.map((s) => (
                    <div key={s.box} className="flex flex-wrap items-center gap-2 border-b px-4 py-3 text-sm last:border-0">
                      <span className="flex-1">{s.box}</span>
                      <span className="rounded bg-slate-100 px-2 py-0.5 text-xs">{s.prov}</span>
                      <span className={`rounded px-2 py-0.5 text-xs ${s.ok ? V.inbox[1] : V.spam[1]}`}>{s.last}</span>
                    </div>
                  ))}
                  <div className="border-t px-4 py-3">
                    <button className="text-sm text-blue-600 underline">+ Ajouter une boîte témoin</button>
                    <p className="mt-2 text-xs text-slate-500">
                      Trois à cinq suffisent. Ces boîtes ne lisent jamais leur courrier : trop
                      nombreuses, elles pèsent sur l'engagement mesuré par les fournisseurs.
                    </p>
                  </div>
                </Card>

                <Card title="Résultats par envoi">
                  <div className="px-4 py-2">
                    <div className="flex gap-2 border-b py-2 text-xs font-semibold text-slate-500">
                      <span className="flex-1">Envoi</span>
                      <span style={{ width: 46 }}>Gmail</span>
                      <span style={{ width: 46 }}>Outlook</span>
                      <span style={{ width: 46 }}>Yahoo</span>
                    </div>
                    {SEED_HISTORY.map((h) => (
                      <div key={h.date} className="border-b py-2 last:border-0">
                        <div className="flex items-center gap-2 text-xs">
                          <span className="flex-1">
                            {h.camp}
                            <span className="block text-slate-400">{h.date} · {h.prov}</span>
                          </span>
                          {[h.g, h.o, h.y].map((v, i) => (
                            <span key={i} className={`rounded px-1 py-0.5 text-center ${V[v][1]}`} style={{ width: 46, fontSize: 10 }}>
                              {v === "inbox" ? "Récept." : v === "spam" ? "Indés." : "—"}
                            </span>
                          ))}
                        </div>
                      </div>
                    ))}
                    <p className="mt-2 text-xs text-slate-500">
                      Outlook classe systématiquement les envois passés par Brevo en indésirables,
                      jamais ceux passés par OVH. Le routage par domaine se règle dans
                      <button onClick={() => setPage("prov")} className="ml-1 text-blue-600 underline">Fournisseurs</button>.
                    </p>
                  </div>
                </Card>
              </>
            )}
          </>
        )}

        {/* ─────────────── REBONDS ─────────────── */}
        {page === "bounce" && (
          <>
            <Card title="Adresses en échec">
              {bounces.map((b) => (
                <div key={b.id} className="border-b px-4 py-3 last:border-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="flex-1 text-sm">{b.email}</span>
                    {b.blocked
                      ? <span className="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">Exclue des envois</span>
                      : <span className="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-800">Échec temporaire</span>}
                  </div>
                  <div className="mt-1 text-xs text-slate-500">
                    {b.count} échec{b.count > 1 ? "s" : ""} · dernier le {b.last} · {b.reason}
                  </div>
                  {b.blocked && (
                    <button onClick={() => setBounces((bs) => bs.map((x) => x.id === b.id ? { ...x, blocked: false, count: 0 } : x))}
                      className="mt-1 text-xs text-blue-600 underline">Réactiver cette adresse</button>
                  )}
                </div>
              ))}
              <div className="border-t bg-slate-50 px-4 py-2 text-xs text-slate-600">
                Une adresse n'est jamais supprimée automatiquement. Un échec temporaire est
                réessayé ; un échec définitif exclut l'adresse jusqu'à réactivation manuelle.
              </div>
            </Card>

            <Card title="Envois par domaine destinataire">
              <div className="px-4 py-3">
                <div className="mb-2 text-xs text-slate-500">30 derniers jours</div>
                {DOMAINS.map((d) => {
                  const rate = (d.failed / d.sent) * 100;
                  return (
                    <div key={d.d} className="border-b py-2 last:border-0">
                      <div className="flex items-center justify-between text-sm">
                        <span>{d.d}</span>
                        <span className={rate > 5 ? "text-red-700" : "text-slate-600"}>
                          {d.sent} envoyés · {d.failed} refusés
                        </span>
                      </div>
                      <div className="mt-1 h-1 w-full overflow-hidden rounded bg-slate-200">
                        <div className={`h-1 ${rate > 5 ? "bg-red-500" : "bg-green-500"}`} style={{ width: `${Math.max(rate, 1)}%` }} />
                      </div>
                    </div>
                  );
                })}
                <p className="mt-3 text-xs text-slate-500">
                  Un refus est un rejet explicite du serveur destinataire. Un message classé en
                  indésirables n'apparaît pas ici : il a été accepté.
                </p>
              </div>
            </Card>
          </>
        )}
      </div>
    </div>
  );
}
