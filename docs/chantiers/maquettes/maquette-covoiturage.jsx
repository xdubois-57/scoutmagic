import React, { useState } from "react";

/**
 * ScoutMagic — maquette : module Covoiturage.
 *
 * Trois objets, et c'est le point de la maquette :
 *   1. un COVOITURAGE — un évènement (facultatif), un lieu, une date aller
 *      et une date retour. Créé une fois.
 *   2. des OFFRES DE PLACES — une par voiture, dans un covoiturage.
 *   3. des DEMANDES — dans une offre, pour des personnes nommées.
 *
 * Le lieu appartient au covoiturage, jamais à l'offre : à l'aller la
 * destination en est verrouillée, au retour c'est le départ. Le conducteur
 * ne choisit que l'autre extrémité. Sans ça chaque conducteur retape la
 * destination et l'écrit chaque fois autrement.
 *
 * Le produit est en Bootstrap 5 ; Tailwind n'est ici que pour dessiner vite.
 */

const VIEWERS = [
  { key: "driver", label: "Parent conducteur", sub: "Sophie Martin" },
  { key: "rider", label: "Parent passager", sub: "Famille Leroy" },
  { key: "chief", label: "Chef de section", sub: "Louveteaux" },
  { key: "unit", label: "Staff d'U", sub: "voit tout" },
];

const FAMILY = ["Tom Leroy", "Léa Leroy"];

const DATA = [
  {
    id: 1, event: "Week-end de section — Louveteaux",
    events: [{ t: "Week-end de section — Louveteaux", s: "Louveteaux" }], sections: ["Louveteaux"],
    place: "Gîte de Han-sur-Lesse, rue des Grottes 12", lat: "50.1234", lng: "5.1876", manual: false,
    aller: "samedi 7 novembre", retour: "dimanche 8 novembre", past: false,
    offers: [
      { id: 11, dir: "Aller", time: "8 h 30", endpoint: "Parking des locaux", seats: 4,
        driver: "Sophie Martin", mine: true, note: "Coffre déjà bien pris, un sac par enfant.",
        requests: [
          { id: 111, by: "Famille Leroy", who: ["Tom Leroy", "Léa Leroy"], state: "pending", when: "hier, 21:04" },
          { id: 112, by: "Famille Nguyen", who: ["Kim Nguyen"], state: "accepted", phone: "0478 12 34 56" },
        ] },
      { id: 12, dir: "Aller", time: "9 h 00", endpoint: "Gare de Wavre", seats: 2,
        driver: "Anne Petit", mine: false, note: "", requests: [] },
      { id: 13, dir: "Retour", time: "16 h 00", endpoint: "Parking des locaux", seats: 2,
        driver: "Marc Dubois", mine: false, note: "",
        requests: [{ id: 131, by: "Famille Leroy", who: ["Tom Leroy"], state: "accepted", phone: "0495 88 77 66" }] },
    ],
  },
  {
    id: 2, event: null, events: [], sections: ["Pionniers"],
    place: "Bastogne, centre scout", lat: null, lng: null, manual: false,
    aller: "samedi 14 novembre", retour: null, past: false,
    offers: [
      { id: 21, dir: "Aller", time: "7 h 00", endpoint: "Gare de Wavre", seats: 3,
        driver: "Anne Petit", mine: false, note: "Je peux passer par Ottignies.", requests: [] },
    ],
  },
  {
    id: 3, event: "Fête d'unité",
    events: [{ t: "Fête d'unité — Baladins", s: "Baladins" }, { t: "Fête d'unité — Louveteaux", s: "Louveteaux" }, { t: "Fête d'unité — Éclaireurs", s: "Éclaireurs" }],
    sections: ["Baladins", "Louveteaux", "Éclaireurs"],
    place: "Plaine de Basse-Wavre", lat: "50.7201", lng: "4.6412", manual: true,
    aller: "samedi 4 octobre", retour: null, past: true,
    offers: [
      { id: 31, dir: "Aller", time: "13 h 30", endpoint: "Parking des locaux", seats: 4,
        driver: "Sophie Martin", mine: true, note: "",
        requests: [{ id: 311, by: "Famille Leroy", who: ["Tom Leroy"], state: "accepted", phone: "0495 88 77 66" }] },
    ],
  },
];

// Ce que la recherche interroge : le titre de l'évènement, le nom du
// calendrier où il est publié, et la section de ce calendrier.
const EVENT_POOL = [
  { t: "Fête d'unité — Baladins · 6 décembre", cal: "Baladins", sec: "Baladins" },
  { t: "Fête d'unité — Louveteaux · 6 décembre", cal: "Louveteaux", sec: "Louveteaux" },
  { t: "Fête d'unité — Éclaireurs · 6 décembre", cal: "Éclaireurs", sec: "Éclaireurs", dup: true },
  { t: "Fête d'unité — Pionniers · 6 décembre", cal: "Pionniers", sec: "Pionniers" },
  { t: "Week-end de rentrée — Baladins · 20-21 septembre", cal: "Baladins", sec: "Baladins" },
  { t: "Week-end de rentrée — Louveteaux · 20-21 septembre", cal: "Louveteaux", sec: "Louveteaux" },
  { t: "Week-end de section — Louveteaux · 7-8 novembre", cal: "Louveteaux", sec: "Louveteaux" },
  { t: "Camp de sélection · 14 novembre", cal: "Animateurs", sec: "Staff d'U" },
  { t: "Réunion de staff · 3 décembre", cal: "Animateurs", sec: "Staff d'U" },
];

const NOTIFS = [
  { id: "covoiturage.request_received", to: "driver", title: "Nouvelle demande de place",
    body: "Famille Leroy demande 2 places — aller du samedi 7 novembre, 8 h 30.",
    ch: "in-app verrouillé · push et e-mail par défaut" },
  { id: "covoiturage.request_pending", to: "driver", title: "Une demande attend votre réponse",
    body: "Famille Leroy, depuis 3 jours. Départ dans 5 jours.",
    ch: "in-app verrouillé · push par défaut · e-mail non" },
  { id: "covoiturage.request_withdrawn", to: "driver", title: "Une demande a été retirée",
    body: "Famille Nguyen s'est désistée — 1 place se libère sur l'aller du 7 novembre.",
    ch: "in-app verrouillé · push par défaut · e-mail non" },
  { id: "covoiturage.request_accepted", to: "rider", title: "Votre place est confirmée",
    body: "Sophie Martin a accepté 2 places — aller du 7 novembre, parking des locaux, 8 h 30.",
    ch: "in-app verrouillé · push et e-mail par défaut" },
  { id: "covoiturage.request_refused", to: "rider", title: "Demande refusée",
    body: "Sophie Martin ne peut pas vous prendre — aller du 7 novembre. D'autres voitures existent.",
    ch: "in-app verrouillé · push et e-mail par défaut" },
  { id: "covoiturage.seat_revoked", to: "rider", title: "Votre place a été retirée",
    body: "Sophie Martin ne peut plus vous prendre — aller du 7 novembre.",
    ch: "in-app verrouillé · push et e-mail par défaut" },
  { id: "covoiturage.offer_changed", to: "rider", title: "La voiture a changé",
    body: "Aller du 7 novembre : départ à 9 h 00 au lieu de 8 h 30, parking des locaux.",
    ch: "in-app verrouillé · push et e-mail par défaut" },
  { id: "covoiturage.offer_cancelled", to: "rider", title: "Voiture annulée",
    body: "Sophie Martin a annulé son aller du 7 novembre. Votre place n'est plus réservée.",
    ch: "in-app verrouillé · push et e-mail par défaut" },
];

function Card({ children, tone }) {
  return <div className={`mb-4 rounded-lg border bg-white ${tone === "warn" ? "border-amber-300" : ""}`}>{children}</div>;
}

export default function MaquetteCovoiturage() {
  const [viewer, setViewer] = useState("driver");
  const [tab, setTab] = useState("screen");
  const [page, setPage] = useState("list");
  const [openId, setOpenId] = useState(1);
  const [data, setData] = useState(DATA);
  const [showPast, setShowPast] = useState(false);
  const [dir, setDir] = useState("Aller");
  const [asking, setAsking] = useState(null);
  const [who, setWho] = useState([]);
  const [revoking, setRevoking] = useState(null);
  const [editing, setEditing] = useState(null);
  const [seats, setSeats] = useState(4);
  const [space, setSpace] = useState("members");
  const [q, setQ] = useState("");
  const [geo, setGeo] = useState("none"); // none | found | moved
  const [picked, setPicked] = useState(["Fête d'unité — Baladins · 6 décembre", "Fête d'unité — Louveteaux · 6 décembre"]);

  const v = VIEWERS.find((x) => x.key === viewer);
  const isStaff = viewer === "chief" || viewer === "unit";
  const cp = data.find((c) => c.id === openId);

  const takenOf = (o) => o.requests.filter((r) => r.state === "accepted").reduce((n, r) => n + r.who.length, 0);
  const seesRequests = (c, o) =>
    viewer === "unit" || (viewer === "chief" && c.sections.includes("Louveteaux")) || (viewer === "driver" && o.mine);
  const myRequest = (o) => (viewer === "rider" ? o.requests.find((r) => r.by === "Famille Leroy") : null);

  function decide(offerId, reqId, state) {
    setData((ds) => ds.map((c) => ({
      ...c,
      offers: c.offers.map((o) => o.id !== offerId ? o : {
        ...o, requests: o.requests.map((r) => r.id === reqId ? { ...r, state, phone: state === "accepted" ? "0478 12 34 56" : undefined } : r),
      }),
    })));
  }

  return (
    <div className="min-h-screen bg-slate-100 p-4 text-slate-800">
      <div className="mx-auto" style={{ maxWidth: 620 }}>
        <div className="mb-3 flex flex-wrap gap-2">
          {VIEWERS.map((x) => (
            <button key={x.key} onClick={() => { setViewer(x.key); setPage("list"); setAsking(null); }}
              className={`rounded border px-3 py-2 text-xs ${viewer === x.key ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>
              {x.label}<span className="block text-slate-400">{x.sub}</span>
            </button>
          ))}
        </div>

        {isStaff && tab === "screen" && (
          <div className="mb-3 flex gap-2">
            {[["members", "Espace membres"], ["staff", "Espace animateurs"]].map(([k, l]) => (
              <button key={k} onClick={() => { setSpace(k); setPage(k === "staff" ? "manage" : "list"); }}
                className={`rounded border px-3 py-2 text-xs ${space === k ? "border-slate-800 bg-slate-800 text-white" : "border-slate-300 bg-white text-slate-600"}`}>{l}</button>
            ))}
          </div>
        )}

        <div className="mb-3 flex gap-2">
          {[["screen", "L'écran"], ["notif", "Notifications et agenda"]].map(([k, l]) => (
            <button key={k} onClick={() => setTab(k)}
              className={`rounded border px-3 py-2 text-xs ${tab === k ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>{l}</button>
          ))}
        </div>

        {/* ═════════ NOTIFICATIONS ET AGENDA ═════════ */}
        {tab === "notif" && (
          <>
            <h1 className="mb-3 text-xl font-semibold">Ce que reçoit {v.label.toLowerCase()}</h1>
            {NOTIFS.filter((n) => (viewer === "driver" && n.to === "driver") || (viewer === "rider" && n.to === "rider")).map((n) => (
              <Card key={n.id}>
                <div className="px-4 py-3">
                  <div className="text-sm font-medium">{n.title}</div>
                  <div className="mt-1 text-xs text-slate-600">{n.body}</div>
                  <div className="mt-2 text-xs text-slate-400">{n.id}</div>
                  <div className="text-xs text-slate-500">{n.ch}</div>
                </div>
              </Card>
            ))}
            {(viewer === "chief" || viewer === "unit") && (
              <Card><div className="px-4 py-3 text-xs leading-relaxed text-slate-600">
                Le staff ne reçoit aucune notification : il consulte, il n'arbitre pas. Un chef qui
                conduit reçoit celles du conducteur, et rien de plus au titre de son rôle — sinon il
                serait notifié deux fois de la même demande.
              </div></Card>
            )}
            <Card>
              <div className="border-b px-4 py-3 text-sm font-semibold">Dans l'agenda</div>
              <div className="px-4 py-3">
                <div className="mb-2 text-xs leading-relaxed text-slate-600">
                  Rien de nouveau dans l'agenda : la ligne s'ajoute à la description de l'évènement
                  qui s'y trouve déjà, et <b>uniquement dans le flux personnel</b> — le seul dont le
                  lecteur est identifié.
                </div>
                <div className="rounded border bg-slate-50 px-3 py-3 text-xs" style={{ fontFamily: "ui-monospace, monospace" }}>
                  <div>Week-end de section — Louveteaux</div>
                  <div className="mt-2 text-slate-500">samedi 7 novembre — dimanche 8 novembre</div>
                  <div className="mt-2">Description :</div>
                  <div className="mt-1 text-slate-600">
                    {viewer === "driver"
                      ? <>Covoiturage — vous conduisez à l'aller, 8 h 30, parking des locaux. 2 places libres.<br />Voir : scoutmagic.be/covoiturage/1</>
                      : <>Covoiturage — aller 8 h 30, parking des locaux : <b>en attente</b>.<br />Covoiturage — retour 16 h 00 : <b>confirmé</b>.<br />Voir : scoutmagic.be/covoiturage/1</>}
                  </div>
                </div>
                <ul className="mt-3 space-y-1 text-xs text-slate-500">
                  <li>· Le statut figure dans la ligne : une place obtenue ne se confond pas avec une demande en attente.</li>
                  <li>· Conducteur à l'aller et passager au retour font deux lignes.</li>
                  <li>· <b>Aucun numéro de téléphone</b> : un ICS atterrit en clair chez Google ou iCloud. Seul le lien y va, et il exige une session.</li>
                  <li>· Une demande refusée fait disparaître la ligne au rafraîchissement suivant : le flux est résolu à chaque requête.</li>
                </ul>
              </div>
            </Card>
          </>
        )}

        {/* ═════════ LISTE DES COVOITURAGES ═════════ */}
        {tab === "screen" && space === "members" && page === "list" && (
          <>
            <div className="mb-1 text-xs text-slate-500">Espace membres</div>
            <h1 className="mb-1 text-xl font-semibold">Covoiturage</h1>
            <p className="mb-4 text-xs leading-relaxed text-slate-600">
              Un covoiturage par sortie. À l'intérieur, chaque famille propose les places libres de
              sa voiture, et le conducteur accepte ou refuse chaque demande.
            </p>

            <p className="mb-4 rounded bg-slate-50 px-3 py-2 text-xs text-slate-500">
              Les covoiturages sont organisés par le staff. Vous y proposez les places libres de
              votre voiture, ou vous en demandez une.
            </p>

            {data.filter((c) => !c.past).map((c) => {
              const free = c.offers.reduce((n, o) => n + (o.seats - takenOf(o)), 0);
              return (
                <Card key={c.id}>
                  <button onClick={() => { setOpenId(c.id); setPage("trip"); setDir("Aller"); }} className="w-full px-4 py-3 text-left">
                    <div className="text-sm font-medium">{c.event ?? "Trajet libre"}</div>
                    <div className="mt-1 text-xs text-slate-500">{c.place}</div>
                    <div className="mt-1 text-xs text-slate-500">
                      {c.aller}{c.retour ? ` et ${c.retour}` : ""} · {c.offers.length} voiture{c.offers.length > 1 ? "s" : ""} ·{" "}
                      <span className={free === 0 ? "text-red-700" : ""}>{free === 0 ? "complet" : `${free} place${free > 1 ? "s" : ""} libre${free > 1 ? "s" : ""}`}</span>
                    </div>
                  </button>
                </Card>
              );
            })}

            <div className="mb-4 rounded-lg border bg-white">
              <button onClick={() => setShowPast(!showPast)} className="flex w-full items-center gap-2 px-4 py-3 text-left text-sm">
                <span className="flex-1 font-medium">Covoiturages passés</span>
                <span className="text-xs text-slate-500">30 derniers jours</span>
                <span className="text-slate-400">{showPast ? "▾" : "▸"}</span>
              </button>
              {showPast && (
                <div className="border-t px-4 py-3">
                  {data.filter((c) => c.past).map((c) => (
                    <div key={c.id} className="border-b py-2 last:border-0">
                      <div className="text-sm text-slate-600">{c.event} · {c.aller}</div>
                      {c.offers.map((o) => (
                        <div key={o.id} className="text-xs text-slate-500">
                          {o.dir} {o.time} · {o.driver} · {o.requests.filter((r) => r.state === "accepted").flatMap((r) => r.who).join(", ") || "personne"}
                        </div>
                      ))}
                    </div>
                  ))}
                  <p className="mt-2 text-xs leading-relaxed text-slate-500">
                    Un covoiturage et ses demandes sont effacés 30 jours après la dernière date —
                    durée réglable. Les numéros de téléphone partent avec : cette liste ne doit pas
                    devenir un registre des déplacements des familles.
                  </p>
                </div>
              )}
            </div>
          </>
        )}

        {/* ═════════ UN COVOITURAGE ═════════ */}
        {tab === "screen" && space === "members" && page === "trip" && cp && (
          <>
            <button onClick={() => setPage("list")} className="mb-2 text-xs text-blue-600">‹ Tous les covoiturages</button>
            <h1 className="text-xl font-semibold">{cp.event ?? "Trajet libre"}</h1>
            <p className="mb-1 text-sm text-slate-600">{cp.place}</p>
            <a href="#" onClick={(e) => e.preventDefault()} className="mb-1 inline-block text-xs text-blue-600 underline">
              Ouvrir dans une application de cartes
            </a>
            <p className="mb-1 text-xs text-slate-400">
              {cp.lat ? `Point précis : ${cp.lat}, ${cp.lng}` : "Aucun point enregistré : l'application ouvrira l'adresse."}
            </p>
            <p className="mb-2 text-xs text-slate-500">{cp.aller}{cp.retour ? ` et ${cp.retour}` : ""}</p>
            {cp.events.length > 1 && (
              <div className="mb-4 flex flex-wrap gap-1">
                {cp.events.map((e) => (
                  <span key={e.t} className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{e.t}</span>
                ))}
              </div>
            )}

            {cp.retour && (
              <div className="mb-4 flex gap-2">
                {["Aller", "Retour"].map((d) => (
                  <button key={d} onClick={() => setDir(d)}
                    className={`rounded border px-3 py-2 text-xs ${dir === d ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>
                    {d} · {d === "Aller" ? cp.aller : cp.retour}
                  </button>
                ))}
              </div>
            )}

            <button onClick={() => setPage("offer")} className="mb-4 w-full rounded bg-blue-600 px-4 py-2 text-sm text-white">
              Proposer des places {cp.retour ? `pour le ${dir.toLowerCase()}` : ""}
            </button>

            {cp.offers.filter((o) => !cp.retour || o.dir === dir).map((o) => {
              const taken = takenOf(o);
              const left = o.seats - taken;
              const mine = myRequest(o);
              return (
                <Card key={o.id}>
                  <div className="px-4 py-3">
                    <div className="flex flex-wrap items-baseline gap-2">
                      <span className="flex-1 text-sm font-medium">{o.driver}{o.mine ? " (vous)" : ""}</span>
                      <span className={`text-xs ${left === 0 ? "text-red-700" : "text-slate-500"}`}>
                        {left === 0 ? "complet" : `${left} place${left > 1 ? "s" : ""} libre${left > 1 ? "s" : ""}`}
                      </span>
                    </div>
                    <div className="mt-1 text-sm">
                      {o.dir === "Aller" ? <>{o.endpoint} → <span className="text-slate-500">{cp.place}</span></> : <><span className="text-slate-500">{cp.place}</span> → {o.endpoint}</>}
                    </div>
                    <div className="mt-1 text-xs text-slate-500">Départ à {o.time}</div>
                    {o.note && <div className="mt-1 text-xs italic text-slate-500">« {o.note} »</div>}

                    {viewer === "driver" && o.mine && (
                      editing === o.id ? (
                        <div className="mt-3 rounded border px-3 py-3">
                          <div className="mb-2 text-xs font-semibold">Modifier ma voiture</div>
                          <label className="mb-1 block text-xs text-slate-500">Places</label>
                          <input type="number" value={seats} min={taken} onChange={(e) => setSeats(Number(e.target.value))}
                            className="mb-1 w-24 rounded border px-2 py-2 text-sm" />
                          <div className={`mb-3 text-xs ${seats < taken ? "text-red-700" : "text-slate-500"}`}>
                            {seats < taken
                              ? `Impossible : ${taken} place${taken > 1 ? "s sont déjà accordées" : " est déjà accordée"}. Retirez d'abord une place accordée.`
                              : `${taken} déjà accordée${taken > 1 ? "s" : ""}.`}
                          </div>
                          <label className="mb-1 block text-xs text-slate-500">Heure de départ</label>
                          <input type="time" defaultValue="08:30" className="mb-3 w-28 rounded border px-2 py-2 text-sm" />
                          <label className="mb-1 block text-xs text-slate-500">
                            {o.dir === "Aller" ? "Lieu de départ" : "Lieu d'arrivée"}
                          </label>
                          <input defaultValue={o.endpoint} className="mb-1 w-full rounded border px-2 py-2 text-sm" />
                          <p className="mb-3 text-xs text-slate-500">
                            {o.dir === "Aller" ? "La destination" : "Le départ"} est celui du covoiturage — {cp.place} — et ne se modifie pas ici.
                          </p>
                          {taken > 0 && (
                            <div className="mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-relaxed">
                              {taken} personne{taken > 1 ? "s ont" : " a"} déjà une place : un changement d'heure ou de lieu leur sera notifié.
                            </div>
                          )}
                          <div className="flex flex-wrap gap-2">
                            <button disabled={seats < taken} className={`rounded px-3 py-2 text-sm text-white ${seats < taken ? "bg-slate-300" : "bg-blue-600"}`}>Enregistrer</button>
                            <button onClick={() => setEditing(null)} className="rounded border px-3 py-2 text-sm text-slate-600">Annuler</button>
                            <button className="rounded border border-red-300 px-3 py-2 text-sm text-red-700">Retirer ma voiture</button>
                          </div>
                        </div>
                      ) : (
                        <button onClick={() => { setEditing(o.id); setSeats(o.seats); }} className="mt-2 text-xs text-blue-600 underline">Modifier ma voiture</button>
                      )
                    )}

                    {viewer === "rider" && mine && (
                      <div className={`mt-3 rounded border px-3 py-2 text-xs leading-relaxed ${mine.state === "accepted" ? "border-green-300 bg-green-50" : "border-amber-300 bg-amber-50"}`}>
                        {mine.state === "accepted"
                          ? <><b>Votre place est confirmée</b> pour {mine.who.join(" et ")}.<div className="mt-1">Téléphone du conducteur : <b>{mine.phone}</b></div></>
                          : <><b>Demande envoyée</b> pour {mine.who.join(" et ")} — en attente de la réponse du conducteur.</>}
                      </div>
                    )}

                    {viewer === "rider" && !mine && left > 0 && (
                      asking === o.id ? (
                        <div className="mt-3 rounded border px-3 py-3">
                          <div className="mb-2 text-xs font-semibold">Pour qui ?</div>
                          {FAMILY.map((p) => (
                            <label key={p} className="mb-1 flex items-center gap-2 text-sm">
                              <input type="checkbox" checked={who.includes(p)}
                                onChange={() => setWho((w) => w.includes(p) ? w.filter((x) => x !== p) : [...w, p])} />{p}
                            </label>
                          ))}
                          <div className="mt-3 text-xs font-semibold">Votre téléphone</div>
                          <input defaultValue="0471 23 45 67" className="mt-1 w-full rounded border px-2 py-2 text-sm" />
                          <div className="mt-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-relaxed">
                            Ce numéro vient de votre fiche et vous pouvez le corriger. Il ne sera montré
                            au conducteur que <b>s'il accepte</b>. Le staff de la section voit qui monte
                            dans quelle voiture.
                          </div>
                          <div className="mt-3 flex gap-2">
                            <button disabled={!who.length} onClick={() => setAsking(null)}
                              className={`rounded px-3 py-2 text-sm text-white ${who.length ? "bg-blue-600" : "bg-slate-300"}`}>Envoyer la demande</button>
                            <button onClick={() => setAsking(null)} className="rounded border px-3 py-2 text-sm text-slate-600">Annuler</button>
                          </div>
                        </div>
                      ) : (
                        <button onClick={() => { setAsking(o.id); setWho([]); }} className="mt-3 rounded border border-blue-600 px-3 py-2 text-sm text-blue-600">
                          Demander une place
                        </button>
                      )
                    )}

                    {seesRequests(cp, o) && o.requests.length > 0 && (
                      <div className="mt-3 rounded border">
                        <div className="border-b bg-slate-50 px-3 py-2 text-xs font-semibold">Demandes ({o.requests.length})</div>
                        {o.requests.map((r) => (
                          <div key={r.id} className="border-b px-3 py-2 last:border-0">
                            <div className="flex flex-wrap items-baseline gap-2">
                              <span className="flex-1 text-sm">{r.who.join(", ")}</span>
                              <span className={`rounded px-2 py-0.5 text-xs ${r.state === "accepted" ? "bg-green-100 text-green-800" : r.state === "refused" ? "bg-slate-200 text-slate-600" : "bg-amber-100 text-amber-800"}`}>
                                {r.state === "accepted" ? "acceptée" : r.state === "refused" ? "refusée" : "en attente"}
                              </span>
                            </div>
                            <div className="text-xs text-slate-500">
                              {r.by}{r.when ? ` · ${r.when}` : ""}{r.state === "accepted" && r.phone ? ` · ${r.phone}` : ""}
                            </div>
                            {r.state === "accepted" && viewer === "driver" && o.mine && (
                              <button onClick={() => setRevoking({ offer: o.id, req: r.id, who: r.who })} className="mt-1 text-xs text-red-700 underline">Retirer cette place</button>
                            )}
                            {r.state === "pending" && viewer === "driver" && o.mine && (
                              <div className="mt-2 flex gap-2">
                                <button onClick={() => decide(o.id, r.id, "accepted")} className="rounded bg-blue-600 px-3 py-1 text-xs text-white">
                                  Accepter {r.who.length > 1 ? `les ${r.who.length} places` : "la place"}
                                </button>
                                <button onClick={() => decide(o.id, r.id, "refused")} className="rounded border border-slate-300 px-3 py-1 text-xs text-slate-600">Refuser</button>
                              </div>
                            )}
                          </div>
                        ))}
                        <div className="bg-slate-50 px-3 py-2 text-xs text-slate-500">
                          Une demande s'accepte entière : le conducteur refuse, et la famille peut redemander pour moins de personnes.
                        </div>
                      </div>
                    )}

                    {(viewer === "chief" || viewer === "unit") && !seesRequests(cp, o) && (
                      <div className="mt-3 rounded bg-slate-50 px-3 py-2 text-xs text-slate-500">
                        Vous ne voyez pas les passagers : ce covoiturage ne concerne pas votre section.
                      </div>
                    )}
                  </div>
                </Card>
              );
            })}
          </>
        )}

        {/* ═════════ PROPOSER DES PLACES ═════════ */}
        {tab === "screen" && space === "members" && page === "offer" && cp && (
          <>
            <button onClick={() => setPage("trip")} className="mb-2 text-xs text-blue-600">‹ {cp.event ?? "Trajet libre"}</button>
            <h1 className="mb-1 text-xl font-semibold">Proposer des places</h1>
            <p className="mb-4 text-xs text-slate-500">{cp.event ?? "Trajet libre"} · {cp.place}</p>

            <Card>
              <div className="px-4 py-4">
                <label className="mb-1 block text-xs text-slate-500">Sens</label>
                <select defaultValue={dir} className="mb-3 rounded border px-2 py-2 text-sm">
                  <option>Aller · {cp.aller}</option>
                  {cp.retour && <option>Retour · {cp.retour}</option>}
                </select>

                <label className="mb-1 block text-xs text-slate-500">Heure de départ</label>
                <input type="time" defaultValue="08:30" className="mb-3 w-28 rounded border px-2 py-2 text-sm" />

                <label className="mb-1 block text-xs text-slate-500">
                  {dir === "Aller" ? "Lieu de départ" : "Lieu d'arrivée"}
                </label>
                <input placeholder="Parking des locaux, gare de Wavre…" className="mb-1 w-full rounded border px-2 py-2 text-sm" />
                <p className="mb-3 text-xs text-slate-500">
                  Un point de rendez-vous, pas votre adresse : ce texte est visible de tous les membres.
                </p>

                <label className="mb-1 block text-xs text-slate-500">{dir === "Aller" ? "Destination" : "Départ"}</label>
                <input value={cp.place} readOnly className="mb-1 w-full rounded border bg-slate-100 px-2 py-2 text-sm text-slate-500" />
                <p className="mb-3 text-xs text-slate-500">
                  Fixé par le covoiturage : toutes les voitures {dir === "Aller" ? "vont" : "partent"} au même endroit.
                </p>

                <label className="mb-1 block text-xs text-slate-500">Places disponibles</label>
                <input type="number" defaultValue="4" min="1" className="mb-3 w-24 rounded border px-2 py-2 text-sm" />

                <label className="mb-1 block text-xs text-slate-500">Conducteur</label>
                <input defaultValue={v.sub} className="mb-1 w-full rounded border px-2 py-2 text-sm" />
                <p className="mb-3 text-xs text-slate-500">
                  Pré-rempli avec le titulaire du compte. Corrigez-le si c'est quelqu'un d'autre de la famille qui conduit.
                </p>

                <label className="mb-1 block text-xs text-slate-500">Votre téléphone</label>
                <input defaultValue="0471 23 45 67" className="mb-1 w-full rounded border px-2 py-2 text-sm" />
                <div className="mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-relaxed">
                  Ce numéro vient de votre fiche et vous pouvez le corriger. Il n'est montré qu'aux
                  familles dont vous <b>acceptez</b> la demande. Le staff de la section voit qui monte
                  dans quelle voiture.
                </div>

                <label className="mb-1 block text-xs text-slate-500">Note (facultatif)</label>
                <textarea rows={2} placeholder="Coffre déjà bien pris, un sac par enfant." className="mb-3 w-full rounded border px-2 py-2 text-sm" />

                {cp.retour && (
                  <label className="mb-3 flex items-start gap-2 text-sm">
                    <input type="checkbox" className="mt-1" />
                    <span>Je propose aussi des places au retour
                      <span className="block text-xs text-slate-500">Deux voitures séparées, chacune avec ses places et ses demandes.</span>
                    </span>
                  </label>
                )}

                <div className="flex gap-2">
                  <button onClick={() => setPage("trip")} className="rounded bg-blue-600 px-4 py-2 text-sm text-white">Proposer</button>
                  <button onClick={() => setPage("trip")} className="rounded border px-4 py-2 text-sm text-slate-600">Annuler</button>
                </div>
              </div>
            </Card>
          </>
        )}

        {/* ═════════ GESTION (ESPACE ANIMATEURS) ═════════ */}
        {tab === "screen" && space === "staff" && page === "manage" && (
          <>
            <div className="mb-1 text-xs text-slate-500">Espace animateurs</div>
            <h1 className="mb-1 text-xl font-semibold">Covoiturage</h1>
            <p className="mb-4 text-xs leading-relaxed text-slate-600">
              Organisez un covoiturage par sortie : il fixe le lieu et les dates. Les familles y
              ajoutent ensuite les places libres de leur voiture, et s'arrangent entre elles.
            </p>

            <button onClick={() => setPage("newcp")} className="mb-4 w-full rounded bg-blue-600 px-4 py-2 text-sm text-white">
              Nouveau covoiturage
            </button>

            {data.filter((c) => !c.past).map((c) => {
              const cars = c.offers.length;
              const seatsTotal = c.offers.reduce((n, o) => n + o.seats, 0);
              const taken = c.offers.reduce((n, o) => n + takenOf(o), 0);
              const pending = c.offers.reduce((n, o) => n + o.requests.filter((r) => r.state === "pending").length, 0);
              const visible = viewer === "unit" || c.sections.includes("Louveteaux");
              return (
                <Card key={c.id}>
                  <div className="px-4 py-3">
                    <div className="flex flex-wrap items-baseline gap-2">
                      <span className="flex-1 text-sm font-medium">
                        {c.events.length > 1 ? `${c.events[0].t.split(" — ")[0]} · ${c.events.length} évènements liés` : (c.event ?? "Sans évènement")}
                      </span>
                      <span className="flex flex-wrap gap-1">
                        {c.sections.map((sec) => (
                          <span key={sec} className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{sec}</span>
                        ))}
                      </span>
                    </div>
                    <div className="mt-1 text-xs text-slate-500">{c.place}</div>
                    <div className="mt-1 text-xs text-slate-500">{c.aller}{c.retour ? ` et ${c.retour}` : ""}</div>

                    {visible ? (
                      <>
                        <div className="mt-2 text-sm">
                          {cars} voiture{cars > 1 ? "s" : ""} · {taken} sur {seatsTotal} places prises
                          {pending > 0 && <span className="text-amber-700"> · {pending} demande{pending > 1 ? "s" : ""} en attente</span>}
                        </div>
                        <div className="mt-2 flex flex-wrap gap-2 text-xs">
                          <button onClick={() => { setSpace("members"); setOpenId(c.id); setPage("trip"); }} className="text-blue-600 underline">Voir les voitures</button>
                          <button className="text-blue-600 underline">Modifier</button>
                          {cars === 0 && <button className="text-slate-500 underline">Supprimer</button>}
                        </div>
                        {cars > 0 && (
                          <p className="mt-1 text-xs text-slate-500">
                            Suppression impossible : des familles se sont déjà organisées. Modifiez, ou annulez en prévenant.
                          </p>
                        )}
                      </>
                    ) : (
                      <div className="mt-2 rounded bg-slate-50 px-3 py-2 text-xs text-slate-500">
                        Ce covoiturage ne concerne pas votre section : vous ne voyez ni les voitures ni les passagers.
                      </div>
                    )}
                  </div>
                </Card>
              );
            })}
          </>
        )}

        {/* ═════════ ORGANISER UN COVOITURAGE ═════════ */}
        {tab === "screen" && space === "staff" && page === "newcp" && (
          <>
            <button onClick={() => setPage("manage")} className="mb-2 text-xs text-blue-600">‹ Covoiturage</button>
            <div className="mb-1 text-xs text-slate-500">Espace animateurs</div>
            <h1 className="mb-1 text-xl font-semibold">Organiser un covoiturage</h1>
            <p className="mb-4 text-xs leading-relaxed text-slate-600">
              Un covoiturage par sortie. Il fixe le lieu et les dates ; chaque famille y ajoute
              ensuite les places libres de sa voiture.
            </p>
            <Card>
              <div className="px-4 py-4">
                <label className="mb-1 block text-xs text-slate-500">Évènements concernés</label>

                {picked.length > 0 && (
                  <div className="mb-2 flex flex-wrap gap-1">
                    {picked.map((t) => (
                      <button key={t} onClick={() => setPicked((p) => p.filter((x) => x !== t))}
                        className="rounded bg-blue-600 px-2 py-1 text-xs text-white">
                        {t.split(" · ")[0]} ✕
                      </button>
                    ))}
                  </div>
                )}

                <input value={q} onChange={(e) => setQ(e.target.value)}
                  placeholder="Chercher un évènement, un calendrier, une section…"
                  className="mb-1 w-full rounded border px-2 py-2 text-sm" />

                {q.trim() !== "" && (
                  <div className="mb-1 max-h-56 overflow-y-auto rounded border">
                    {EVENT_POOL
                      .filter((e) => !picked.includes(e.t))
                      .filter((e) => [e.t, e.cal, e.sec].join(" ").toLowerCase().includes(q.trim().toLowerCase()))
                      .map((e) => (
                        <button key={e.t} onClick={() => { setPicked((p) => [...p, e.t]); setQ(""); }}
                          className="flex w-full items-start gap-2 border-b px-3 py-2 text-left text-sm last:border-0">
                          <span className="flex-1">
                            {e.t}
                            <span className="block text-xs text-slate-500">calendrier {e.cal}</span>
                            {e.dup && <span className="block text-xs text-amber-700">Un covoiturage existe déjà pour cet évènement — mieux vaut le rejoindre.</span>}
                          </span>
                          <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{e.sec}</span>
                        </button>
                      ))}
                    {EVENT_POOL.filter((e) => !picked.includes(e.t))
                      .filter((e) => [e.t, e.cal, e.sec].join(" ").toLowerCase().includes(q.trim().toLowerCase())).length === 0 && (
                      <div className="px-3 py-3 text-xs text-slate-500">Aucun évènement ne correspond.</div>
                    )}
                  </div>
                )}

                <p className="mb-3 text-xs text-slate-500">
                  Cherchez par titre, par calendrier ou par section. Un évènement répliqué dans
                  plusieurs calendriers — fête d'unité, week-end de rentrée — se sélectionne autant de
                  fois qu'il apparaît : un seul covoiturage, une seule liste de voitures. Le staff de
                  chacune des sections retenues voit les passagers.
                </p>

                <p className="mb-3 rounded bg-slate-50 px-3 py-2 text-xs text-slate-500">
                  Sans aucun évènement coché, choisissez la section concernée : c'est elle qui
                  décidera quel staff voit les voitures et les passagers.
                </p>

                <label className="mb-1 block text-xs text-slate-500">Adresse</label>
                <input defaultValue="Plaine de Basse-Wavre" className="mb-1 w-full rounded border px-2 py-2 text-sm" />
                <p className="mb-1 text-xs text-slate-500">
                  Reprise du lieu des évènements retenus. C'est la destination de tous les allers et
                  le départ de tous les retours.
                </p>
                <div className="mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-relaxed">
                  Les évènements retenus n'indiquent pas tous le même lieu. Vérifiez : un covoiturage
                  n'a qu'une destination, et s'ils divergent c'est qu'ils n'appartiennent probablement
                  pas au même trajet.
                </div>

                <label className="mb-1 block text-xs text-slate-500">Point sur la carte (facultatif)</label>
                {geo === "none" ? (
                  <>
                    <button onClick={() => setGeo("found")} className="mb-1 rounded border border-blue-600 px-3 py-2 text-sm text-blue-600">
                      Retrouver le point depuis l'adresse
                    </button>
                    <p className="mb-3 text-xs text-slate-500">
                      Facultatif : sans point, le lien de cartes ouvrira simplement l'adresse. Un point
                      sert quand l'adresse est imprécise — un pré, une entrée de bois, un gîte isolé.
                    </p>
                  </>
                ) : (
                  <>
                    <div className="mb-1 overflow-hidden rounded border">
                      <div className="relative bg-slate-200" style={{ height: 180 }}>
                        <div className="absolute inset-0 opacity-40"
                          style={{ backgroundImage: "repeating-linear-gradient(0deg,#cbd5e1 0 1px,transparent 1px 28px),repeating-linear-gradient(90deg,#cbd5e1 0 1px,transparent 1px 28px)" }} />
                        <button onClick={() => setGeo("moved")}
                          className="absolute text-2xl"
                          style={{ left: geo === "moved" ? "58%" : "46%", top: geo === "moved" ? "38%" : "50%", transform: "translate(-50%,-100%)" }}>
                          📍
                        </button>
                        <div className="absolute bottom-1 right-1 rounded bg-white/80 px-1 text-xs text-slate-500">
                          © OpenStreetMap
                        </div>
                      </div>
                      <div className="flex flex-wrap items-center gap-2 border-t px-3 py-2 text-xs">
                        <span className="text-slate-600">
                          {geo === "moved" ? "50.720100, 4.641200" : "50.718400, 4.635900"}
                        </span>
                        {geo === "moved"
                          ? <span className="rounded bg-blue-100 px-2 py-0.5 text-blue-800">point placé à la main</span>
                          : <span className="rounded bg-slate-100 px-2 py-0.5 text-slate-600">trouvé depuis l'adresse</span>}
                        <span className="flex-1" />
                        <button onClick={() => setGeo("none")} className="text-slate-500 underline">Retirer</button>
                      </div>
                    </div>
                    <p className="mb-3 text-xs leading-relaxed text-slate-500">
                      {geo === "moved"
                        ? "Déplacé à la main : aucune reprise automatique ne le réécrira, même si l'adresse change."
                        : "Déplacez l'épingle si le point est à côté — c'est fréquent pour un terrain ou une entrée de bois. Le point déplacé est alors figé."}
                    </p>
                  </>
                )}

                <div className="mb-3 flex flex-wrap gap-3">
                  <div>
                    <label className="mb-1 block text-xs text-slate-500">Date de l'aller</label>
                    <input type="date" defaultValue="2026-11-07" className="rounded border px-2 py-2 text-sm" />
                  </div>
                  <div>
                    <label className="mb-1 block text-xs text-slate-500">Date du retour</label>
                    <input type="date" defaultValue="2026-11-08" className="rounded border px-2 py-2 text-sm" />
                  </div>
                </div>
                <p className="mb-3 text-xs text-slate-500">Laissez le retour vide pour une sortie d'un seul trajet.</p>

                <div className="flex gap-2">
                  <button onClick={() => setPage("manage")} className="rounded bg-blue-600 px-4 py-2 text-sm text-white">Créer</button>
                  <button onClick={() => setPage("manage")} className="rounded border px-4 py-2 text-sm text-slate-600">Annuler</button>
                </div>
              </div>
            </Card>
          </>
        )}

        {revoking && (
          <div className="fixed inset-0 flex items-center justify-center p-4" style={{ backgroundColor: "rgba(0,0,0,0.5)" }}>
            <div className="w-full rounded-lg bg-white shadow-xl" style={{ maxWidth: 420 }}>
              <div className="border-b px-4 py-3 text-base font-semibold">Retirer une place accordée ?</div>
              <div className="px-4 py-4 text-sm leading-relaxed">
                {revoking.who.join(" et ")} avai{revoking.who.length > 1 ? "ent" : "t"} une place confirmée
                et s'organis{revoking.who.length > 1 ? "aient" : "ait"} en conséquence. La famille sera
                prévenue, et la place redeviendra libre.
              </div>
              <div className="flex justify-end gap-2 border-t px-4 py-3">
                <button onClick={() => setRevoking(null)} className="rounded border px-4 py-2 text-sm">Garder</button>
                <button onClick={() => { decide(revoking.offer, revoking.req, "refused"); setRevoking(null); }}
                  className="rounded bg-red-600 px-4 py-2 text-sm text-white">Retirer la place</button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
