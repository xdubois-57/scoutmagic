# Maquettes — chantier « Courrier entrant transversal »

Ce que ces deux fichiers font foi pour : **la hiérarchie de l'écran, les
libellés français et les états d'interaction**. Rien d'autre.

Ils ne font **pas** foi pour les classes CSS ni pour l'iconographie : le
produit est en Bootstrap 5 et suit `design.md` §7. Tailwind n'apparaît ici
que parce qu'une maquette React se dessine plus vite avec.

Rien ici n'est du code de production, et rien n'est exécuté :
`tsconfig.json` n'analyse que `public/assets/js/**/*.js`, et Vitest ne lit
que `tests/js/**/*.test.js`. Ces fichiers sont de la documentation qui se
trouve être exécutable ailleurs.

| Fichier | Écran | Itération |
|---|---|---|
| `maquette-config-courrier-v2.jsx` | `/config/courrier-entrant` — superadmin | IT-05 |
| `maquette-courrier-entrant.jsx` | `/courrier` — Chef d'Unité, et le composant de tri métier | IT-06, IT-07 |

## Deux points de lecture

**La v2 de la configuration remplace la v1.** `maquette-courrier-entrant.jsx`
contient encore un écran `MailboxConfig` de première génération (une case à
cocher plus trois boutons radio). Il est périmé : `maquette-config-courrier-v2.jsx`
le remplace par deux questions distinctes — l'usage de la boîte d'abord,
puis, pour une boîte partagée seulement, un interrupteur « analyse » et un
choix segmenté « qui peut lire ». C'est la v2 qui fait foi.

**Le rôle `rental` de la maquette est le composant de tri d'IT-07**, pas un
second écran : même liste, filtrée au périmètre de l'utilisateur, sans
filtres de boîte ni bascule de courrier automatique.
