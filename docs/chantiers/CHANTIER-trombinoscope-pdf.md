# Chantier — Trombinoscope imprimable

Roadmap d'exécution en **3 itérations séquentielles**. Traite-les une par une, dans l'ordre.

La maquette `trombinoscope-pdf-mockup.jsx` fait autorité sur la **structure des pages, les libellés
français et le comportement de densité** — et sur rien d'autre : elle est écrite en React et
Tailwind faute de format d'aperçu, le rendu réel passera par dompdf.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`. Ils priment sur ce fichier sur toute règle générale ; si tu découvres
qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans la même PR**.

- Une itération = une branche, une PR. Rebase sur `main` avant de merger.
- **Merge et push sur `main` dès que tous les tests et la CI complète sont verts.** Un test rouge
  arrête le chantier : tu corriges, tu ne contournes pas, tu ne désactives rien.
- Tests obligatoires : PHPUnit, PHPStan, Vitest et `npm run typecheck` si tu touches du JS.
  Couverture RBAC : autorisé à `identified`, refusé à `public`.
- **Toute édition de `modules/trombinoscope/schema.sql` impose de bumper `version` dans son
  `module.json`.** Le module est en v1.0.0 et n'a aujourd'hui **aucun réglage** : IT-01 lui en
  ajoute un.
- Code, commentaires et identifiants en anglais ; UI en français. Aucune donnée personnelle dans le
  journal.

### L'objectif

Un chef d'unité doit pouvoir produire un document **partageable aux parents**, qu'une famille peut
afficher au mur. C'est ce qui décide de la forme.

### Pourquoi un PDF et pas une feuille de style d'impression — **Décidé**

Une CSS d'impression ne se partage pas : il faudrait envoyer un lien, que le parent se connecte — le
trombinoscope est `role_min: identified` — puis qu'il imprime lui-même. Un PDF est un fichier qu'on
joint à un e-mail.

Et elle ne garantit rien. Les navigateurs **suppriment les fonds colorés par défaut** : les bandes
de couleur de section disparaîtraient à moins que le parent ne coche « graphiques d'arrière-plan »
dans une boîte de dialogue qu'il ne lira pas. S'y ajoutent l'en-tête et le pied imprimés par le
navigateur, le format de papier qu'il choisit, et un rendu différent d'un poste à l'autre.

### La structure du document — **Décidé**

**Page 1, l'annuaire** : un responsable par section, avec sa photo, son totem, son nom et ses
coordonnées. C'est la page qu'un parent affiche sur son frigo.

**Puis une page par section** : tout le staff en portraits, avec les coordonnées de chacun.

Le document couvre **toujours l'unité entière** et l'**année scoute effective**. Personne n'a besoin
de tout imprimer : un parent de Louveteaux imprime la page 1 et celle de sa section. C'est pourquoi
chaque page porte le nom de sa section en tête — c'est ce qui permet de la retrouver dans la boîte
d'impression.

Le **Staff d'unité** est une section comme les autres : il a sa page, et il figure sur l'annuaire.
Aucun cas particulier — s'il n'a pas de responsable désigné, il porte la même mention que n'importe
quelle autre section.

---

## IT-01 — Le réglage et les contacts à l'écran

La plus petite des trois, et elle n'a rien à voir avec le PDF.

### À faire

Un **réglage unique**, activé par défaut, gouvernant l'affichage des coordonnées personnelles —
téléphone et adresse e-mail — des animateurs. `modules/trombinoscope/module.json` n'a aujourd'hui
aucune section `settings` : celle-ci doit en porter une, avec une `description` non nulle comme
l'impose `AGENTS.md`.

Le trombinoscope à l'écran affiche alors ces coordonnées sous chaque animateur.

### Ce que le réglage gouverne, et ce qu'il ne gouverne pas — **Décidé**

Il gouverne **les données personnelles** : téléphone et e-mail d'un animateur, chiffrés au repos,
déchiffrés dans le Repository.

Il ne gouverne **pas** l'adresse d'une section. `design.md` la classe explicitement à part — *« Section
email (organizational) → Clear VARCHAR »* — et elle survit à un changement de responsable. Elle reste
donc affichée réglage décoché.

C'est ce qui rend le document utile même sans les coordonnées : on sait toujours qui est responsable
de quoi et à quelle adresse écrire.

### Pièges

- **Tout ou rien.** Un seul réglage, y compris pour les responsables sur la page annuaire. Ne
  construis pas deux commutateurs.
- Le téléphone d'un membre est un `BLOB` chiffré : déchiffrement dans le Repository uniquement, et
  jamais dans le journal.

---

## IT-02 — Le rendu PDF et la page annuaire

L'itération qui porte le risque technique. Elle valide dompdf, les photos et la densité.

### Le service

**`PosterPdfService` n'est pas réutilisable tel quel** : sa méthode `generate()` prend un titre, un
extrait et une URL de QR pour produire une affiche d'une page. Écris un service dédié, mais reprends
son **motif dompdf** — `isRemoteEnabled = false`, `defaultFont = 'DejaVu Sans'`, `@page { margin:
15mm }`, A4.

### Trois contraintes de dompdf, à connaître avant d'écrire le gabarit

**Ni flexbox ni grid.** La composition se fait en tableaux. La maquette montre la cible, pas la
technique — ne transpose pas ses classes.

**Aucune image chargée par URL.** `isRemoteEnabled = false` est délibéré : chaque photo doit être
embarquée en base64 dans le HTML. Pour trente animateurs, c'est plusieurs mégaoctets de HTML si les
originaux sont inlinés tels quels sur un hébergement mutualisé.

**Réduis les photos avant de les embarquer.** `Core\Photo\StaffThumbnailProcessor` produit déjà une
vignette carrée d'environ 160 px à la demande — mais **en WebP**, que dompdf ne sait pas lire.
Produis du JPEG ou du PNG pour ce cas. Un membre sans photo garde son avatar d'initiales, comme à
l'écran.

### La densité, par paliers — **Décidé**

La page annuaire **tient toujours sur une feuille**, quel que soit le nombre de sections. Pas de
mise à l'échelle continue — trois paliers, plus robustes sous dompdf et testables :

| Sections | Colonnes | Vignette |
|---|---|---|
| jusqu'à 6 | 2 | grande |
| 7 à 10 | 2 | resserrée |
| au-delà | 3 | compacte |

Le pied de page suit le même principe : deux colonnes d'adresses jusqu'à huit sections, trois
au-delà, avec un corps plus petit.

### Ce que porte la page

Les cartes, en deux ou trois colonnes selon le palier : bande de couleur de la branche —
`SectionService::colorForSection()` est la source unique du site, ne recalcule rien —, photo,
nom de section, totem, nom civil, puis téléphone et e-mail si le réglage d'IT-01 est actif.

Une section **sans responsable désigné** garde sa carte, avec un cercle en pointillés et la mention
« Responsable non désigné ». Un trou visible pousse le chef d'unité à corriger ; omettre la section
laisserait croire qu'elle n'existe pas.

En pied, **toutes les adresses de section**, jamais tronquées.

### Pièges

- **Aucune adresse n'est tronquée**, nulle part. Une adresse coupée ne sert à rien. Elles passent à
  la ligne ; réduis le corps si nécessaire.
- La bande de couleur est précisément ce qu'un navigateur supprimerait à l'impression : c'est la
  raison d'être du PDF, ne la remplace pas par une bordure fine.
- Le bouton de téléchargement vit sur la page du trombinoscope, au **même rôle qu'elle** —
  `identified`. Un parent y voit déjà les mêmes données à l'écran, et l'objectif est justement
  qu'il puisse produire le document lui-même.

---

## IT-03 — Les pages de section

Une page par section, réutilisant tout ce qu'IT-02 a mis en place.

### Ce que porte une page

Un en-tête avec la couleur de la section, son nom en grand, sa branche et son effectif — c'est ce
qui permet de retrouver la bonne page dans la boîte d'impression. Puis les portraits en grille :
photo, totem, nom civil, mention « Responsable » sur celui qui l'est, et les coordonnées si le
réglage est actif.

En pied, **l'adresse de la section**, en grand et dans sa couleur : un parent qui n'a détaché que
cette page doit pouvoir écrire sans revenir à la première.

### Pièges

- **Une section ne se coupe jamais entre deux pages.** Si son staff ne tient pas sur une feuille,
  elle en occupe deux — mais elle ne commence pas au milieu de la précédente.
- Le format à trois colonnes tient six à neuf animateurs. Au-delà, resserre plutôt que de déborder,
  selon le même principe de paliers qu'en IT-02.
- Les totems et les noms peuvent être tronqués — perdre trois lettres d'un nom composé n'empêche
  personne d'agir. **Les adresses, jamais.**

---

## Récapitulatif

| # | Itération | Schéma | Bump version | Risque |
|---|---|---|---|---|
| IT-01 | Réglage et contacts à l'écran | `settings` | **trombinoscope** | faible |
| IT-02 | Rendu PDF et page annuaire | — | — | **dompdf, photos, densité** |
| IT-03 | Les pages de section | — | — | faible |

IT-02 porte tout le risque technique : dompdf sans flexbox, images embarquées, densité par paliers.
IT-03 réutilise ce qu'elle aura mis en place et ne devrait plus réserver de surprise.
