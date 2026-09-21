import React, { useState } from "react";

/**
 * ScoutMagic — maquette : page « Modules » (Configuration, superadmin).
 *
 * Plus de glisser-déposer. Les modules sont groupés par la catégorie que
 * chacun déclare dans son module.json, puis triés alphabétiquement.
 *
 * L'activation reste un interrupteur (Bootstrap `form-switch`, comme
 * aujourd'hui), jamais une case à cocher.
 *
 * « Outils de test » et « Supervision » n'apparaissent pas ici : leur
 * visible_when les réserve à l'installation de référence, aux installations
 * locales et à celle qui reçoit les statistiques. C'est l'écran d'une unité
 * ordinaire.
 *
 * Le produit est en Bootstrap 5 ; Tailwind n'est ici que pour dessiner vite.
 */

const CATEGORIES = [
  ["communication", "Communication"],
  ["activites", "Activités"],
  ["membres", "Membres et effectifs"],
  ["argent", "Argent"],
  ["services", "Services de l'unité"],
  ["site", "Le site"],
  ["technique", "Technique"],
];

const MODULES = [
  { name: "Actualités", cat: "communication", v: "3.0.2", on: true, desc: "Articles publics, avec formulaire d'inscription et paiement." },
  { name: "Courrier entrant", cat: "communication", v: "2.3.1", on: true, desc: "Relève les boîtes mail de l'unité et répartit les messages." },
  { name: "Envoi de mails", cat: "communication", v: "1.15.0", on: true, desc: "Envois groupés et listes de diffusion." },

  { name: "Calendrier", cat: "activites", v: "1.9.0", on: true, desc: "Un calendrier par section, abonnable depuis un téléphone." },
  { name: "Camps", cat: "activites", v: "0.8.4", on: true, desc: "Les lieux de camp de l'unité et les séjours qui s'y sont tenus." },
  { name: "Photos et vidéos", cat: "activites", v: "4.2.0", on: true, desc: "Albums par section." },
  { name: "Présences", cat: "activites", v: "1.1.0", on: true, desc: "Une feuille de présence par réunion." },
  { name: "Rétrospectives", cat: "activites", v: "1.4.2", on: false, desc: "Tableaux anonymes de retour après une activité." },

  { name: "Attestations", cat: "membres", v: "2.0.1", on: true, desc: "Distribue à chaque famille son attestation fiscale et de mutuelle." },
  { name: "Discussions", cat: "membres", v: "1.6.1", on: true, desc: "Groupes de discussion privés, un par section." },
  { name: "Documents officiels", cat: "membres", v: "1.0.0", on: false, desc: "Autorisation parentale et fiche santé, pré-remplies." },
  { name: "Encadrement", cat: "membres", v: "1.2.0", on: true, desc: "Qui contacter pour les formations et le parcours des animateurs." },
  { name: "Inscriptions", cat: "membres", v: "6.9.0", on: true, desc: "Inscriptions, réinscriptions, passages de branche et départs." },
  { name: "Statistiques", cat: "membres", v: "1.0.3", on: true, desc: "Nombre d'animés par branche et par année." },
  { name: "Trombinoscope", cat: "membres", v: "2.5.0", on: true, desc: "Les animateurs de chaque section, en photo." },

  { name: "Cotisations", cat: "argent", v: "1.3.0", on: true, desc: "Vérifie ce que la fédération facture, famille par famille." },
  { name: "Finances", cat: "argent", v: "5.1.0", on: true, desc: "Comptes, mouvements et justificatifs." },

  { name: "Locations", cat: "services", v: "3.7.2", on: true, desc: "Location des biens de l'unité : locaux, terrains, tentes, remorques." },
  { name: "Téléphone d'urgence", cat: "services", v: "1.0.0", on: false, desc: "Dévie le numéro d'urgence de l'unité vers le staff de garde." },

  { name: "Bannière", cat: "site", v: "1.1.0", on: true, desc: "Un message en une de la page d'accueil." },
  { name: "Fréquentation", cat: "site", v: "1.0.0", on: true, desc: "Les pages les plus consultées, par mois. Aucun suivi des personnes." },

  { name: "Intelligence artificielle", cat: "technique", v: "2.2.0", on: true, desc: "Fournisseur d'IA utilisé par d'autres modules." },
];

export default function MaquetteModules() {
  const [mods, setMods] = useState(MODULES);
  const [only, setOnly] = useState("all");

  const toggle = (name) => setMods((m) => m.map((x) => (x.name === name ? { ...x, on: !x.on } : x)));
  const visible = mods.filter((m) => only === "all" || (only === "on" ? m.on : !m.on));
  const active = mods.filter((m) => m.on).length;

  return (
    <div className="min-h-screen bg-slate-100 p-4 text-slate-800">
      <div className="mx-auto" style={{ maxWidth: 620 }}>
        <div className="mb-1 text-xs text-slate-500">Configuration</div>
        <h1 className="mb-2 text-xl font-semibold">Modules</h1>
        <p className="mb-3 text-xs leading-relaxed text-slate-600">
          Activez ou désactivez les fonctionnalités du site. Les pages et paramètres d'un module
          n'apparaissent que lorsqu'il est actif ; ses données sont conservées s'il est désactivé.
        </p>

        <div className="mb-4 flex flex-wrap gap-2">
          {[["all", `Tous (${mods.length})`], ["on", `Actifs (${active})`], ["off", `Inactifs (${mods.length - active})`]].map(([k, l]) => (
            <button key={k} onClick={() => setOnly(k)}
              className={`rounded border px-3 py-1 text-xs ${only === k ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>{l}</button>
          ))}
        </div>

        {CATEGORIES.map(([key, label]) => {
          const items = visible.filter((m) => m.cat === key);
          if (!items.length) return null;
          return (
            <div key={key} className="mb-4">
              <div className="mb-1 px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
              <div className="overflow-hidden rounded-lg border bg-white">
                {items.map((m) => (
                  <div key={m.name} className={`flex items-start gap-3 border-b px-4 py-3 last:border-0 ${m.on ? "" : "bg-slate-50"}`}>
                    <div className="flex-1">
                      <span className={`text-sm font-medium ${m.on ? "" : "text-slate-500"}`}>{m.name}</span>
                      <span className="ml-2 text-xs text-slate-400">v{m.v}</span>
                      <div className="mt-1 text-xs text-slate-500">{m.desc}</div>
                    </div>
                    <button type="button" role="switch" aria-checked={m.on} aria-label={`Activer ${m.name}`}
                      onClick={() => toggle(m.name)}
                      className={`relative mt-1 shrink-0 rounded-full transition-colors ${m.on ? "bg-blue-600" : "bg-slate-300"}`}
                      style={{ width: 40, height: 22 }}>
                      <span className="absolute rounded-full bg-white shadow transition-all"
                        style={{ width: 18, height: 18, top: 2, left: m.on ? 20 : 2 }} />
                    </button>
                  </div>
                ))}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
