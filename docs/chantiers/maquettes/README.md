# Maquettes des chantiers

Ce que ces fichiers font foi pour : **la hiérarchie de l'écran ou de la
page, les libellés français et les états d'interaction**. Rien d'autre.

Ils ne font **pas** foi pour les classes CSS ni pour l'iconographie : le
produit est en Bootstrap 5 et suit `design.md` §7. Tailwind n'apparaît ici
que parce qu'une maquette React se dessine plus vite avec.

Rien ici n'est du code de production, et rien n'est exécuté :
`tsconfig.json` n'analyse que `public/assets/js/**/*.js`, et Vitest ne lit
que `tests/js/**/*.test.js`. Ces fichiers sont de la documentation qui se
trouve être exécutable ailleurs.

| Fichier | Chantier | Écran | Itération |
|---|---|---|---|
| `maquette-config-courrier-v2.jsx` | Courrier entrant transversal | `/config/courrier-entrant` — superadmin | IT-05 |
| `maquette-courrier-entrant.jsx` | Courrier entrant transversal | `/courrier` — Chef d'Unité, et le composant de tri métier | IT-06, IT-07 |
| `maquette-trombinoscope-pdf.jsx` | Trombinoscope imprimable | Le PDF A4 — page d'annuaire et page de section | IT-02, IT-03 |

## Le cas du trombinoscope imprimable

Sa maquette ne dessine pas un écran mais **un document** : une feuille A4
à l'échelle, marges comprises. Elle fait foi pour la structure des pages,
les libellés et le comportement de densité — et pour rien d'autre, la
règle ci-dessus valant ici doublement : le rendu réel passe par dompdf,
qui ne connaît ni flexbox ni grid, et la composition livrée est en
tableaux (`ARCHITECTURE.md` §8.92). Ses classes Tailwind montrent le
résultat, jamais la technique.

## Deux points de lecture sur le courrier entrant

**La v2 de la configuration remplace la v1.** `maquette-courrier-entrant.jsx`
contient encore un écran `MailboxConfig` de première génération (une case à
cocher plus trois boutons radio). Il est périmé : `maquette-config-courrier-v2.jsx`
le remplace par deux questions distinctes — l'usage de la boîte d'abord,
puis, pour une boîte partagée seulement, un interrupteur « analyse » et un
choix segmenté « qui peut lire ». C'est la v2 qui fait foi.

**Le rôle `rental` de la maquette est le composant de tri d'IT-07**, pas un
second écran : même liste, filtrée au périmètre de l'utilisateur, sans
filtres de boîte ni bascule de courrier automatique.
