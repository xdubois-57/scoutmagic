import React, { useState } from "react";

/**
 * ScoutMagic — maquette : « Stockage » (Configuration, superadmin).
 *
 * Sous-pages avec un rail de navigation, sur le modèle de
 * modules/finance/views/config/_nav.html.twig (partials/page_picker.html.twig).
 *
 * Fait foi pour : l'ordre Espace / Usages / Emplacements, la matrice des
 * capacités, le comportement requis/recommandé au rattachement d'un usage,
 * et l'état de santé mis en cache.
 *
 * Le produit est en Bootstrap 5 et suit design.md §7 ; Tailwind n'est ici que
 * parce qu'une maquette se dessine plus vite avec.
 */

// Ce que la page montre : des conséquences concrètes, pas des capacités
// techniques. Les capacités restent dans le code et servent à produire ces
// lignes — elles n'apparaissent jamais telles quelles à l'écran.
const CAPS = [
  ["photos", "Photos", "Affichage des photos aux visiteurs"],
  ["videos", "Vidéos", "Lecture d'une vidéo, avec la possibilité d'avancer dedans"],
  ["backup", "Sauvegardes", "Envoi de fichiers volumineux, repris s'il est coupé"],
  ["quota", "Place restante", "Le site sait vous dire combien il reste d'espace"],
];

const VERDICT = {
  ok: ["Oui", "bg-green-100 text-green-800"],
  fast: ["Oui, le plus rapide", "bg-green-100 text-green-800"],
  slow: ["Oui, un peu plus lent", "bg-amber-100 text-amber-800"],
  bad: ["Déconseillé", "bg-amber-100 text-amber-800"],
  no: ["Non", "bg-red-100 text-red-800"],
  dep: ["Selon l'hébergeur", "bg-slate-100 text-slate-600"],
  unk: ["Non indiquée", "bg-slate-100 text-slate-600"],
};

const TYPES = {
  local: {
    label: "Disque du serveur", caps: ["range"],
    v: { photos: "slow", videos: "ok", backup: "ok", quota: "dep" },
    why: "Tout passe par le site. Simple et sans configuration, mais c'est votre hébergement qui porte le volume.",
  },
  s3: {
    label: "S3 et compatibles", caps: ["range", "signed", "resumable"],
    v: { photos: "fast", videos: "ok", backup: "ok", quota: "unk" },
    why: "Les photos vont directement du stockage au visiteur sans passer par le site. En contrepartie, S3 ne sait pas dire combien vous occupez sans tout parcourir.",
  },
  webdav: {
    label: "WebDAV (Nextcloud, kDrive…)", caps: ["range", "quota"],
    v: { photos: "slow", videos: "ok", backup: "ok", quota: "ok" },
    why: "Le plus simple à raccorder : une adresse, un identifiant, un mot de passe. Les photos transitent par le site, donc un peu plus lent qu'S3.",
  },
  drive: {
    label: "Google Drive", caps: ["resumable", "quota"],
    v: { photos: "bad", videos: "no", backup: "ok", quota: "ok" },
    why: "Chaque photo demande un appel à Google : un album de 200 photos est lent. Et la lecture d'une vidéo y est impossible. Très bien pour des sauvegardes, mal adapté à une galerie.",
  },
};

const LOCATIONS = [
  { id: 1, label: "Disque du serveur", type: "local", detail: "storage/modules/gallery",
    used: "1,9 Go", pct: 62, total: "4 Go déclarés", ok: true, checked: "aujourd'hui, 08:12" },
  { id: 2, label: "Nextcloud de l'unité", type: "webdav", detail: "https://cloud.25sv.be/remote.php/dav",
    used: "4,2 Go", pct: 8, total: "50 Go", ok: true, checked: "aujourd'hui, 08:12" },
  { id: 3, label: "Drive sauvegardes", type: "drive", detail: "sauvegardes@25sv.be",
    used: "3,1 Go", pct: 21, total: "15 Go", ok: false, checked: "hier, 02:04",
    error: "Jeton expiré — l'écran de consentement Google est resté en statut « Test »" },
  { id: 4, label: "Bucket Scaleway", type: "s3", detail: "s3.fr-par.scw.cloud / 25sv-photos",
    used: null, pct: null, total: null, ok: true, checked: "aujourd'hui, 08:12" },
];

const PROTECTION = {
  1: null,
  2: { dest: "Drive sauvegardes", grace: 30, last: "cette nuit, 03:12", added: 34, removed: 0, size: "182 Mo", ok: true },
  3: null,
  4: null,
};

function Card({ title, children }) {
  return (
    <div className="mb-4 rounded-lg border bg-white">
      {title && <div className="border-b px-4 py-3 text-sm font-semibold">{title}</div>}
      {children}
    </div>
  );
}

export default function MaquetteStockage() {
  const [page, setPage] = useState("space");
  const USED_BY = { 1: [], 2: ["Galeries photo", "Copie des médias"], 3: ["Sauvegardes distantes"] };
  const [openCaps, setOpenCaps] = useState(false);


  return (
    <div className="min-h-screen bg-slate-100 p-4 text-slate-800">
      <div className="mx-auto" style={{ maxWidth: 640 }}>
        <div className="mb-1 text-xs text-slate-500">Configuration</div>
        <h1 className="mb-3 text-xl font-semibold">Stockage</h1>

        <div className="mb-4 flex gap-2 overflow-x-auto pb-1">
          {[["space", "Tableau de bord"], ["locations", "Emplacements"]].map(([k, l]) => (
            <button key={k} onClick={() => setPage(k)}
              className={`whitespace-nowrap rounded border px-3 py-2 text-xs ${page === k ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>
              {l}
            </button>
          ))}
        </div>

        {/* ───────── ESPACE ───────── */}
        {page === "space" && (
          <>
            <Card title="État">
              <div className="border-b px-4 py-3">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-sm">Drive sauvegardes</span>
                  <span className="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">En erreur</span>
                </div>
                <div className="mt-1 text-xs text-slate-600">
                  Jeton expiré — l'écran de consentement Google est resté en statut « Test ».
                  Les sauvegardes distantes ne partent plus depuis le 10 septembre.
                </div>
                <button onClick={() => setPage("locations")} className="mt-1 text-xs text-blue-600 underline">Corriger</button>
              </div>
              <div className="px-4 py-3">
                <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Usages</div>
                {[["Galeries photo", "Nextcloud de l'unité"], ["Sauvegardes", "Drive sauvegardes"], ["Copie des médias", "Nextcloud de l'unité"]].map(([u, l]) => (
                  <div key={u} className="flex justify-between border-b py-1 text-xs last:border-0">
                    <span>{u}</span><span className="text-slate-500">{l}</span>
                  </div>
                ))}
              </div>
            </Card>

            <Card title="Emplacements sans protection">
              <div className="px-4 py-3">
                <p className="mb-2 text-xs leading-relaxed text-slate-600">
                  Le contenu d'un emplacement n'est <b>pas</b> repris dans les archives de
                  sauvegarde : il a son propre cycle de vie. Sa protection, c'est une copie tenue à
                  jour vers un autre emplacement.
                </p>
                {LOCATIONS.filter((l) => !PROTECTION[l.id]).map((l) => (
                  <div key={l.id} className="flex flex-wrap items-center gap-2 border-b py-2 text-xs last:border-0">
                    <span className="flex-1">{l.label}</span>
                    <span className="rounded bg-amber-100 px-2 py-0.5 text-amber-800">Aucune copie</span>
                    <button onClick={() => setPage("locations")} className="text-blue-600 underline">Protéger</button>
                  </div>
                ))}
              </div>
            </Card>

            <div className="mb-3 px-1 text-xs text-slate-600">
              L'espace se mesure <b>par volume</b>, pas par emplacement : deux dossiers sur le même
              disque partagent la même place libre, et les afficher séparément laisserait croire
              qu'on en a deux fois plus.
            </div>

            <Card title="Volume principal">
              <div className="px-4 py-3">
                <div className="mb-1 text-xs text-slate-500">storage/ · quota déclaré</div>
                <div className="mb-2 h-2 w-full overflow-hidden rounded bg-slate-200">
                  <div className="h-2 bg-blue-600" style={{ width: "62%" }} />
                </div>
                <p className="text-xs text-slate-600"><b>2,4 Go</b> sur les 4 Go déclarés — 62 %.</p>
                <div className="mt-2 space-y-1 text-xs text-slate-600">
                  {[["Sauvegardes", "380 Mo"], ["Pièces jointes", "90 Mo"], ["Temporaire", "30 Mo"]].map(([k, v]) => (
                    <div key={k} className="flex justify-between border-b py-1 last:border-0">
                      <span>{k}</span><span className="text-slate-500">{v}</span>
                    </div>
                  ))}
                </div>
                <p className="mt-2 text-xs text-slate-500">
                  Mesure basée sur le quota que vous avez déclaré : sur un hébergement partagé, le
                  système rapporte la taille du volume entier, bien plus grande que votre part.
                </p>
              </div>
            </Card>

            <Card title="Disque réseau">
              <div className="px-4 py-3">
                <div className="mb-1 text-xs text-slate-500">/mnt/nas/photos · mesure système</div>
                <div className="mb-2 h-2 w-full overflow-hidden rounded bg-slate-200">
                  <div className="h-2 bg-green-600" style={{ width: "4%" }} />
                </div>
                <p className="text-xs text-slate-600"><b>4,2 Go</b> sur 900 Go — 0,5 %.</p>
                <p className="mt-1 text-xs text-slate-500">
                  Volume distinct du précédent : le site l'a reconnu à son numéro de périphérique,
                  pas au chemin. Aucun quota déclaré n'est nécessaire ici, le système dit vrai.
                </p>
                <div className="mt-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-relaxed">
                  <b>Ce dossier est hors de <code>storage/</code>.</b> Il n'est donc pas effacé
                  par une réinitialisation complète du site.
                </div>
                <div className="mt-2 text-xs text-slate-500">
                  Vérifié aujourd'hui 08:12 par écriture d'un fichier témoin.
                  <span className="block">
                    Un montage réseau peut disparaître, passer en lecture seule, ou figer sans
                    répondre : le test écrit réellement, avec un délai maximal.
                  </span>
                </div>
              </div>
            </Card>

            <Card title="Emplacements distants">
              {LOCATIONS.filter((l) => l.type !== "local").map((l) => (
                <div key={l.id} className="border-b px-4 py-3 last:border-0">
                  <div className="flex items-center justify-between">
                    <span className="text-sm">{l.label}</span>
                    <span className="text-xs text-slate-500">
                      {l.pct === null ? "occupation inconnue" : `${l.used} sur ${l.total}`}
                    </span>
                  </div>
                  {l.pct === null ? (
                    <p className="mt-1 text-xs text-slate-500">
                      S3 ne sait pas dire ce qu'occupe un bucket sans le parcourir entièrement.
                      Mieux vaut ne rien afficher qu'une barre fausse.
                    </p>
                  ) : (
                    <div className="mt-2 h-2 w-full overflow-hidden rounded bg-slate-200">
                      <div className="h-2 bg-blue-600" style={{ width: `${l.pct}%` }} />
                    </div>
                  )}
                </div>
              ))}
            </Card>
          </>
        )}

        {/* ───────── EMPLACEMENTS ───────── */}
        {page === "locations" && (
          <>
            {LOCATIONS.map((l) => {
              const served = USED_BY[l.id] ?? [];
              return (
                <Card key={l.id}>
                  <div className="px-4 py-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="flex-1 text-sm font-medium">{l.label}</span>
                      <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{TYPES[l.type].label}</span>
                      <span className={`rounded px-2 py-0.5 text-xs ${l.ok ? "bg-green-100 text-green-800" : "bg-red-100 text-red-800"}`}>
                        {l.ok ? "Joignable" : "En erreur"}
                      </span>
                    </div>
                    <div className="mt-1 break-all text-xs text-slate-500">{l.detail}</div>

                    {!l.ok && (
                      <div className="mt-2 rounded border border-red-300 bg-red-50 px-3 py-2 text-xs leading-relaxed">
                        {l.error}
                      </div>
                    )}

                    {l.pct !== null && (
                      <>
                        <div className="mt-2 h-2 w-full overflow-hidden rounded bg-slate-200">
                          <div className="h-2 bg-blue-600" style={{ width: `${l.pct}%` }} />
                        </div>
                        <div className="mt-1 text-xs text-slate-500">{l.used} sur {l.total}</div>
                      </>
                    )}
                    {l.pct === null && (
                      <div className="mt-2 text-xs text-slate-500">Occupation inconnue pour ce type.</div>
                    )}

                    <div className="mt-2 flex flex-wrap gap-1">
                      {CAPS.map(([k, label]) => {
                        const [txt, cls] = VERDICT[TYPES[l.type].v[k]];
                        return (
                          <span key={k} className={`rounded px-2 py-0.5 text-xs ${cls}`}>
                            {label} : {txt.toLowerCase()}
                          </span>
                        );
                      })}
                    </div>

                    <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                      <span>Vérifié {l.checked}</span>
                      <button className="text-blue-600 underline">Tester</button>
                      <button className="text-blue-600 underline">Modifier</button>
                      {served.length === 0 && <button className="text-slate-500 underline">Supprimer</button>}
                    </div>

                    {served.length > 0 && (
                      <div className="mt-1 text-xs text-slate-500">
                        Sert : {served.join(", ")} — suppression impossible tant
                        qu'un usage en dépend.
                      </div>
                    )}

                    <div className="mt-3 rounded border px-3 py-2">
                      <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Protection
                      </div>
                      {PROTECTION[l.id] ? (
                        <>
                          <div className="text-xs text-slate-700">
                            Copie tenue à jour vers <b>{PROTECTION[l.id].dest}</b>, chaque nuit.
                            Un fichier retiré d'ici y reste {PROTECTION[l.id].grace} jours.
                          </div>
                          <div className="mt-1 text-xs text-slate-500">
                            Dernière passe {PROTECTION[l.id].last} — +{PROTECTION[l.id].added} fichiers,
                            {" "}{PROTECTION[l.id].size}.
                          </div>
                          <div className="mt-1 flex gap-2 text-xs">
                            <button className="text-blue-600 underline">Modifier</button>
                            <button className="text-blue-600 underline">Lancer maintenant</button>
                          </div>
                        </>
                      ) : (
                        <>
                          <div className="text-xs text-slate-600">
                            Aucune copie. Le contenu de cet emplacement n'est repris dans aucune
                            archive de sauvegarde.
                          </div>
                          <button className="mt-1 text-xs text-blue-600 underline">
                            Protéger cet emplacement
                          </button>
                        </>
                      )}
                    </div>
                  </div>
                </Card>
              );
            })}

            <div className="mb-4 px-1">
              <button className="text-sm text-blue-600 underline">+ Ajouter un emplacement</button>
            </div>

            <Card title="Quel type choisir">
              <div className="px-4 py-2">
                <button onClick={() => setOpenCaps(!openCaps)} className="mb-2 text-xs text-blue-600 underline">
                  {openCaps ? "Masquer la comparaison" : "Comparer les types de stockage"}
                </button>
              </div>

              {openCaps && (
                <>
                  {Object.entries(TYPES).map(([k, t]) => (
                    <div key={k} className="border-t px-4 py-3">
                      <div className="mb-2 text-sm font-medium">{t.label}</div>
                      <div className="space-y-1">
                        {CAPS.map(([c, label, hint]) => {
                          const [txt, cls] = VERDICT[t.v[c]];
                          return (
                            <div key={c} className="flex flex-wrap items-baseline gap-2 border-b py-1 last:border-0">
                              <span className="text-xs" style={{ minWidth: 110 }}>{label}</span>
                              <span className={`rounded px-2 py-0.5 text-xs ${cls}`}>{txt}</span>
                              <span className="w-full text-xs text-slate-400">{hint}</span>
                            </div>
                          );
                        })}
                      </div>
                      <p className="mt-2 text-xs text-slate-600">{t.why}</p>
                    </div>
                  ))}
                  <div className="border-t bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-600">
                    <b>« Un peu plus lent »</b> veut dire que chaque photo transite par votre site
                    avant d'arriver au visiteur, au lieu d'être servie directement par le stockage.
                    Sur un album de vacances ouvert par trente parents le même soir, ça se sent.
                    <div className="mt-2">
                      Ce tableau est produit à partir de ce que chaque type sait réellement faire :
                      il ne peut pas se désynchroniser du code.
                    </div>
                  </div>
                </>
              )}
            </Card>
          </>
        )}
      </div>
    </div>
  );
}
