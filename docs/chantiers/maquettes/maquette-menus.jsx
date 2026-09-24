import React, { useState } from "react";

/**
 * ScoutMagic — maquette : réorganisation des menus.
 *
 * Bascule « Aujourd'hui / Proposé » et changement de persona, pour comparer
 * ce que chacun voit réellement. Rend l'offcanvas mobile (accordéon, un menu
 * ouvert à la fois), vue de référence du site : design.md §1.2. Sur grand
 * écran, chaque groupe est une colonne titrée du méga-menu.
 *
 * « Aujourd'hui » reproduit l'ordre réellement rendu par MenuBuilder : les
 * pages du cœur passent toujours avant celles des modules, quel que soit
 * leur menu_order — c'est ce qui relègue « Inscriptions » en fin de liste.
 *
 * Aucun changement de permission : chaque page garde son role_min.
 * Le produit est en Bootstrap 5 ; Tailwind n'est ici que pour dessiner vite.
 */

const PERSONAS = [
  { key: "public", label: "Visiteur", sub: "un parent qui découvre", role: 0 },
  { key: "parent", label: "Parent / animé", sub: "identifié", role: 1 },
  { key: "intendant", label: "Intendant", sub: "rôle intendant", role: 2 },
  { key: "anim", label: "Animateur", sub: "20-25 ans", role: 3 },
  { key: "cu", label: "Chef d'unité", sub: "adulte, pas informaticien", role: 4 },
  { key: "sa", label: "Super-admin", sub: "un peu plus à l'aise", role: 5 },
];

const BEFORE = [
  { label: "Notre unité", icon: "🏠", role: 0, groups: [{ label: null, items: [
    ["Accueil", 0], ["Contact", 0], ["Sections", 0], ["Protection des données", 0],
    ["Calendrier", 0], ["Actualités", 0], ["Inscriptions", 0]] }] },
  { label: "Espace membres", icon: "👥", role: 1, groups: [
    { label: "Mes membres", items: [["Lutin (Baladins)", 1], ["Hibou (Louveteaux)", 1]] },
    { label: "Pages", items: [["Notifications", 1], ["Trombinoscope", 1], ["Galerie", 1], ["Groupes", 1]] }] },
  { label: "Espace animateurs", icon: "⭐", role: 2, groups: [
    { label: "Ma section", items: [["Staffs", 2], ["Membres par section", 2], ["Présences", 3], ["Départs", 3]] },
    { label: "Activités", items: [["Calendrier", 3], ["Camps", 3], ["Galerie", 3], ["Rétrospectives", 2]] },
    { label: "Communication", items: [["Envoi de mails", 3], ["Actualités", 3]] },
    { label: "Gestion", items: [["Finances", 2], ["Statistiques", 3], ["Prévisions", 3]] }] },
  { label: "Espace chefs d'U", icon: "⚙️", role: 4, groups: [
    { label: "Membres & année", items: [["Import Desk", 4], ["Points d'attention", 4], ["Membres", 4], ["Année scoute", 4], ["Attestations", 4], ["Passage", 4]] },
    { label: "Contenu du site", items: [["Édition du site", 4]] },
    { label: "Services", items: [["SOS Staff d'U", 4], ["Listes de diffusion", 4], ["Réinscription", 4], ["Inscriptions", 4], ["Locations", 4]] },
    { label: "Suivi", items: [["Journal", 4], ["Cotisations", 4], ["Courrier", 4], ["Encadrement", 4]] }] },
  { label: "Configuration", icon: "🎛️", role: 5, groups: [
    { label: "Unité & données", items: [["Badges", 5], ["Correspondances Desk", 5], ["RGPD", 5]] },
    { label: "Site", items: [["Installation & serveur", 5], ["Modules", 5], ["Pages de texte", 5], ["Réglages", 5]] },
    { label: "Réglages des modules", items: [["Calendrier", 5], ["Camps", 5], ["Finances", 5], ["Galerie", 5], ["Courrier entrant", 5], ["Intelligence artificielle", 5], ["SOS Staff d'U", 5]] },
    { label: "Exploitation", items: [["Actions planifiées", 5], ["Comptes superadmin", 5], ["Maintenance", 4], ["Notifications", 5], ["E-mails", 5], ["Courrier sortant", 5], ["Stockage", 5], ["Support", 5], ["Synchronisation des contacts", 5], ["Fréquentation", 5]] }] },
];

const AFTER = [
  { label: "Notre unité", icon: "🏠", role: 0, groups: [{ label: null, items: [
    ["Accueil", 0], ["Sections", 0], ["Inscriptions", 0], ["Actualités", 0], ["Calendrier", 0], ["Contact", 0]] }],
    note: "« Protection des données » reste accessible en pied de page, où elle figure déjà." },
  { label: "Espace membres", icon: "👥", role: 1, groups: [
    { label: "Mes membres", items: [["Lutin (Baladins)", 1], ["Hibou (Louveteaux)", 1], ["Notifications", 1]] },
    // Chantier covoiturage (IT-06) : « Activités » rejoint l'Espace membres ;
    // « Photos » y passe, à côté de « Covoiturage ».
    { label: "Activités", items: [["Photos", 1], ["Covoiturage", 1]] },
    { label: "L'unité", items: [["Les animateurs", 1], ["Discussions", 1]] }] },
  { label: "Espace animateurs", icon: "⭐", role: 2, groups: [
    { label: "Ma section", items: [["Présences", 3], ["Animés de la section", 2], ["Staffs et badges", 2]] },
    { label: "Activités", items: [["Calendrier", 3], ["Camps", 3], ["Gérer les photos", 3], ["Rétrospectives", 2], ["Organiser les covoiturages", 3]] },
    { label: "Communication", items: [["Rédiger les actualités", 3], ["Envoi de mails", 3]] },
    { label: "Effectifs", items: [["Statistiques", 3], ["Prévisions d'effectifs", 3], ["Départs de l'unité", 3]] },
    { label: "Argent", items: [["Finances", 2]] }] },
  { label: "Espace chefs d'U", icon: "⚙️", role: 4, groups: [
    { label: "Suivi", items: [["Points d'attention", 4], ["Journal", 4]] },
    { label: "Membres et année", items: [["Import Desk", 4], ["Membres", 4], ["Année scoute", 4], ["Encadrement", 4], ["Attestations", 4]] },
    { label: "Communication", items: [["Édition du site", 4], ["Listes de diffusion", 4], ["Courrier reçu", 4]] },
    { label: "Effectifs", items: [["Réinscriptions", 4], ["Passages de branche", 4], ["Formulaire d'inscription", 4], ["Cotisations", 4]] },
    { label: "Services de l'unité", items: [["Biens à louer", 4], ["Gérer le téléphone d'urgence", 4]] }] },
  { label: "Configuration", icon: "🎛️", role: 5, groups: [
    { label: "L'unité", items: [["Correspondances Desk", 5], ["Badges", 5], ["RGPD", 5], ["Synchronisation des contacts", 5]] },
    { label: "Le site", items: [["Installation & serveur", 5], ["Modules", 5], ["Pages de texte", 5], ["Paramètres", 5], ["Comptes superadmin", 5]] },
    { label: "Communication", items: [["Courrier entrant", 5], ["Courrier sortant", 5], ["Notifications", 5], ["Modèles d'e-mails", 5]] },
    { label: "Données et sauvegardes", items: [["Stockage", 5], ["Maintenance", 4], ["Actions planifiées", 5]] },
    { label: "État du site", items: [["Fréquentation", 5], ["Diagnostic", 5]] },
    { label: "Réglages des modules", items: [["Calendrier", 5], ["Camps", 5], ["Finances", 5], ["Photos", 5], ["Intelligence artificielle", 5], ["Téléphone d'urgence", 5]] }] },
];

const NOTES = {
  public: [
    "« Inscriptions » est la dernière entrée, après une page légale. Pas par choix : MenuBuilder range toujours les pages du cœur avant celles des modules, quel que soit leur ordre.",
    "« Inscriptions » en troisième position, là où un parent la cherche. La page légale reste en pied de page, où elle est déjà.",
  ],
  parent: [
    "Le groupe « Pages » ne veut rien dire. « Trombinoscope » est un mot d'école, « Groupes » évoque plutôt les sections.",
    "Ce qui me concerne, ce qui concerne l'unité. Chaque entrée dit ce qu'elle contient.",
  ],
  intendant: [
    "Quatre colonnes, dont une seule contient plus d'une entrée visible pour lui.",
    "Trois colonnes — Ma section, Activités, Argent — et plus aucune colonne vide.",
  ],
  anim: [
    "« Staffs » et « Membres par section » côte à côte, sans qu'on devine ce qui les sépare. « Galerie » apparaît dans trois menus qu'il fréquente.",
    "« Effectifs » réunit l'état, la projection et le résultat. Une page de gestion porte son verbe.",
  ],
  cu: [
    "« Points d'attention » est deuxième d'un groupe. « Services » et « Suivi » sont deux bacs de débordement ; le cycle d'inscription est éclaté entre eux.",
    "Le menu s'ouvre sur ce qui ne va pas. Le cycle d'inscription est réuni sous « Effectifs », dans l'ordre où il se vit.",
  ],
  sa: [
    "« Exploitation » compte dix entrées et ne dit rien à un non-informaticien. « E-mails » à côté de « Courrier sortant » ressemble à un doublon.",
    "Six colonnes de deux à sept entrées. Les quatre pages de communication sont ensemble ; « E-mails » s'appelle enfin « Modèles d'e-mails ».",
  ],
};

export default function MaquetteMenus() {
  const [after, setAfter] = useState(true);
  const [persona, setPersona] = useState("anim");
  const [open, setOpen] = useState("Espace animateurs");

  const p = PERSONAS.find((x) => x.key === persona);
  const tree = (after ? AFTER : BEFORE).filter((m) => m.role <= p.role);
  const count = tree.reduce((n, m) => n + m.groups.reduce((k, g) => k + g.items.filter(([, r]) => r <= p.role).length, 0), 0);

  function switchPersona(k) {
    setPersona(k);
    const pr = PERSONAS.find((x) => x.key === k).role;
    const t = (after ? AFTER : BEFORE).filter((m) => m.role <= pr);
    setOpen(t.length ? t[t.length - 1].label : null);
  }

  return (
    <div className="min-h-screen bg-slate-100 p-4 text-slate-800">
      <div className="mx-auto" style={{ maxWidth: 780 }}>
        <h1 className="mb-1 text-xl font-semibold">Menus — comparaison</h1>
        <p className="mb-4 text-xs text-slate-500">Aucun changement de permission : chaque page garde son rôle minimum.</p>

        <div className="mb-3 flex flex-wrap gap-2">
          {PERSONAS.map((x) => (
            <button key={x.key} onClick={() => switchPersona(x.key)}
              className={`rounded border px-3 py-2 text-xs ${persona === x.key ? "border-blue-600 bg-blue-50 text-blue-700" : "border-slate-300 bg-white text-slate-600"}`}>
              {x.label}<span className="block text-slate-400">{x.sub}</span>
            </button>
          ))}
        </div>

        <div className="mb-4 flex items-center gap-2">
          <button onClick={() => setAfter(false)} className={`rounded border px-3 py-2 text-sm ${!after ? "border-slate-800 bg-slate-800 text-white" : "border-slate-300 bg-white text-slate-600"}`}>Aujourd'hui</button>
          <button onClick={() => setAfter(true)} className={`rounded border px-3 py-2 text-sm ${after ? "border-blue-600 bg-blue-600 text-white" : "border-slate-300 bg-white text-slate-600"}`}>Proposé</button>
          <span className="text-xs text-slate-500">{count} entrées visibles</span>
        </div>

        <div className="flex flex-wrap gap-5">
          <div className="overflow-hidden rounded-3xl border-4 border-slate-800 bg-white shadow-xl" style={{ width: 330, height: 620 }}>
            <div className="flex items-center justify-between bg-blue-600 px-3 py-3 text-white">
              <span className="text-lg">✕</span><span className="text-sm font-medium">25e SV</span>
            </div>
            <div className="border-b bg-slate-50 px-3 py-3 text-sm">
              Xavier <span className="block text-xs text-slate-500">{p.label}</span>
            </div>
            <div className="overflow-y-auto" style={{ height: 620 - 56 - 62 }}>
              {tree.map((m) => {
                const isOpen = open === m.label;
                return (
                  <div key={m.label} className="border-b">
                    <button onClick={() => setOpen(isOpen ? null : m.label)} className="flex w-full items-center gap-2 px-3 py-3 text-left text-sm">
                      <span>{m.icon}</span><span className="flex-1 font-medium">{m.label}</span>
                      <span className="text-slate-400">{isOpen ? "▾" : "▸"}</span>
                    </button>
                    {isOpen && (
                      <div className="pb-2">
                        {m.groups.map((g, gi) => {
                          const items = g.items.filter(([, r]) => r <= p.role);
                          if (items.length === 0) return null;
                          return (
                            <div key={gi}>
                              {g.label && <div className="px-3 pb-1 pt-2 text-xs uppercase tracking-wide text-slate-400">{g.label}</div>}
                              {items.map(([label]) => <div key={label} className="py-2 pr-3 text-sm text-slate-700" style={{ paddingLeft: 26 }}>{label}</div>)}
                            </div>
                          );
                        })}
                        {after && m.note && <div className="mx-3 mt-2 rounded bg-slate-50 px-2 py-1 text-xs text-slate-500">{m.note}</div>}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          </div>

          <div style={{ minWidth: 260, flex: 1 }}>
            <div className="rounded-lg border bg-white p-4 text-sm leading-relaxed">
              <div className="mb-2 font-semibold">Ce que ce persona voit</div>
              <p className={after ? "" : "text-amber-800"}>{NOTES[persona][after ? 1 : 0]}</p>
            </div>
            <div className="mt-3 rounded-lg border bg-white p-4 text-xs leading-relaxed text-slate-600">
              <b className="text-slate-800">Sur grand écran</b>
              <div className="mt-1">Chaque groupe est une colonne titrée du méga-menu. Il n'existe pas d'entrée « hors groupe » : c'est pourquoi Finances a sa propre colonne, « Argent ».</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
