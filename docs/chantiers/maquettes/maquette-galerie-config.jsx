import React, { useState } from "react";

/**
 * ScoutMagic — maquette : « Copie de sauvegarde » du module galerie
 * (Configuration > Galerie, superadmin).
 *
 * Fait foi pour : la sortie de la galerie des archives de sauvegarde, le
 * réglage de la copie synchronisée, le délai de grâce avant suppression, et
 * la procédure de restauration affichée sur place.
 *
 * Le produit est en Bootstrap 5 et suit design.md §7 ; Tailwind n'est ici que
 * parce qu'une maquette se dessine plus vite avec.
 */

const LOCATIONS = [
  { id: 1, label: "Disque du serveur", type: "Disque du serveur", current: true },
  { id: 2, label: "Nextcloud de l'unité", type: "WebDAV" },
  { id: 3, label: "Drive sauvegardes", type: "Google Drive" },
];

const RUNS = [
  { date: "cette nuit, 03:12", added: 34, removed: 0, size: "182 Mo", ok: true, dur: "4 min" },
  { date: "hier, 03:11", added: 0, removed: 0, size: "0 o", ok: true, dur: "11 s" },
  { date: "11 sept., 03:14", added: 212, removed: 8, size: "1,4 Go", ok: true, dur: "22 min" },
  { date: "10 sept., 03:10", added: 0, removed: 0, size: "—", ok: false, dur: "—", err: "Destination injoignable — identifiants WebDAV refusés" },
];

const PAGES = [
  ["general", "Général"],
  ["photos", "Photos"],
  ["videos", "Vidéos"],
  ["albums", "Albums"],
];

const ALBUMS = [
  { id: 1, title: "Camp Baladins 2026", loc: "Nextcloud de l'unité", size: "1,4 Go", state: null },
  { id: 2, title: "Week-end Louveteaux", loc: "Disque du serveur", size: "310 Mo", state: "running", pct: 62 },
  { id: 3, title: "Photos du groupe « Staff d'U »", loc: "Disque du serveur", size: "88 Mo", state: null, owner: "Groupes de discussion" },
  { id: 4, title: "Fête d'unité", loc: "Bucket Scaleway", size: "2,1 Go", state: null },
];

function Row({ label, hint, children }) {
  return (
    <div className="border-b px-4 py-3 last:border-0">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-sm">{label}</span>
        {children}
      </div>
      {hint && <div className="mt-1 text-xs text-slate-500">{hint}</div>}
    </div>
  );
}

export default function MaquetteGalerieSauvegarde() {
  const [page, setPage] = useState("general");
  const [on, setOn] = useState(true);
  const [dest, setDest] = useState(2);
  const [grace, setGrace] = useState("30");

  return (
    <div className="min-h-screen bg-slate-100 p-4 text-slate-800">
      <div className="mx-auto" style={{ maxWidth: 620 }}>
        <div className="mb-1 text-xs text-slate-500">Configuration</div>
        <h1 className="mb-3 text-xl font-semibold">Galerie</h1>

        <div className="mb-4 flex gap-2 overflow-x-auto pb-1">
          {PAGES.map(([k, l]) => (
            <button key={k} onClick={() => setPage(k)}
              className={`whitespace-nowrap rounded border px-3 py-2 text-xs ${page === k ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>
              {l}
            </button>
          ))}
        </div>

        {page === "general" && (
          <div className="mb-4 rounded-lg border bg-white">
            <Row label="Autoriser les albums externes"
              hint="Un album qui pointe vers un lien de partage plutôt que d'héberger ses médias — un album Google Photos partagé par un parent, par exemple. Rien n'est copié sur le site : si le partage est retiré, l'album se vide.">
              <input type="checkbox" defaultChecked />
            </Row>
            <Row label="Nombre max. de médias par album">
              <input defaultValue="500" className="w-24 rounded border px-2 py-1 text-sm" />
            </Row>
            <Row label="Emplacement des nouveaux albums"
              hint="S'applique aux albums créés à partir de maintenant. Les albums existants restent où ils sont — pour les déplacer, voir l'onglet Albums. Les emplacements se déclarent dans Configuration › Stockage.">
              <select defaultValue="2" className="rounded border px-2 py-1 text-sm">
                <option value="1">Disque du serveur</option>
                <option value="2">Nextcloud de l'unité</option>
                <option value="4">Bucket Scaleway</option>
              </select>
            </Row>
          </div>
        )}

        {page === "photos" && (
          <div className="mb-4 rounded-lg border bg-white">
            <Row label="Taille max. par photo (Mo)">
              <input defaultValue="15" className="w-24 rounded border px-2 py-1 text-sm" />
            </Row>
            <Row label="Dimension max. (px)"
              hint="Les photos plus grandes sont réduites avant stockage.">
              <input defaultValue="2560" className="w-24 rounded border px-2 py-1 text-sm" />
            </Row>
          </div>
        )}

        {page === "videos" && (
          <div className="mb-4 rounded-lg border bg-white">
            <Row label="Autoriser les vidéos">
              <input type="checkbox" defaultChecked />
            </Row>
            <Row label="Taille max. par vidéo (Mo)">
              <input defaultValue="200" className="w-24 rounded border px-2 py-1 text-sm" />
            </Row>
            <Row label="Durée max. (s)">
              <input defaultValue="180" className="w-24 rounded border px-2 py-1 text-sm" />
            </Row>
            <Row label="Conserver la vidéo originale"
              hint="En plus de la version ré-encodée. Double l'espace occupé.">
              <input type="checkbox" />
            </Row>
            <div className="border-t bg-amber-50 px-4 py-3 text-xs leading-relaxed">
              L'emplacement actuel des galeries sait lire par plage d'octets, donc les vidéos y sont
              lisibles. Sur un emplacement qui ne le sait pas — Google Drive par exemple — leur
              téléversement serait refusé.
            </div>
          </div>
        )}

        {page === "albums" && (
          <div className="mb-4 rounded-lg border bg-white">
            <div className="border-b px-4 py-3 text-xs leading-relaxed text-slate-600">
              Déplace les photos et vidéos d'un album d'un emplacement de stockage à un autre, en
              arrière-plan. <b>L'album est indisponible pour les membres pendant l'opération.</b>
            </div>

            {ALBUMS.map((a) => (
              <div key={a.id} className="border-b px-4 py-3 last:border-0">
                <div className="flex flex-wrap items-baseline gap-2">
                  <span className="flex-1 text-sm">{a.title}</span>
                  <span className="text-xs text-slate-400">{a.size}</span>
                </div>
                <div className="mt-1 text-xs text-slate-500">
                  {a.loc}
                  {a.owner && (
                    <span className="ml-2 rounded bg-slate-100 px-2 py-0.5 text-slate-600">
                      appartient à « {a.owner} »
                    </span>
                  )}
                </div>

                {a.state === "running" ? (
                  <div className="mt-2">
                    <div className="h-2 w-full overflow-hidden rounded bg-slate-200">
                      <div className="h-2 bg-blue-600" style={{ width: `${a.pct}%` }} />
                    </div>
                    <div className="mt-1 text-xs text-amber-700">
                      Déplacement en cours vers Nextcloud de l'unité — {a.pct} %. L'album est
                      indisponible jusqu'à la fin.
                    </div>
                  </div>
                ) : (
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    <select className="rounded border px-2 py-1 text-sm">
                      <option>Déplacer vers…</option>
                      <option>Disque du serveur</option>
                      <option>Nextcloud de l'unité</option>
                      <option>Bucket Scaleway</option>
                    </select>
                    <button className="rounded border border-blue-600 px-3 py-1 text-sm text-blue-600">
                      Déplacer
                    </button>
                  </div>
                )}
              </div>
            ))}

            <div className="bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-600">
              Les albums appartenant à un autre module n'apparaissent nulle part ailleurs dans la
              galerie, mais ils occupent une place réelle sur un emplacement réel : les lister ici
              est ce qui permet d'expliquer l'espace consommé.
            </div>
          </div>
        )}

      </div>
    </div>
  );
}
