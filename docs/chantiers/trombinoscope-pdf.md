# Chantier — Trombinoscope imprimable

Journal d'implémentation du document `CHANTIER-trombinoscope-pdf.md`
(itérations IT-01 à IT-03). Une section par itération : ce qui a été fait,
les décisions prises en autonomie, les divergences constatées entre le
document de chantier et le dépôt réel, et ce qui a été reporté. Même
format que `docs/chantiers/support-statistics.md`.

---

## Récapitulatif final

**Ce qui a été livré.** Les trois itérations, dans l'ordre. IT-02 et IT-03
sont arrivées dans le même commit : la seconde n'ajoute pas une surface
mais des pages au document que la première produit, et les livrer
séparément aurait voulu dire publier un PDF amputé de sa moitié.

| # | Livré |
|---|---|
| IT-01 | Le réglage unique `trombinoscope_show_contacts`, les coordonnées des animateurs à l'écran, l'adresse de section sous chaque en-tête, RGPD |
| IT-02 | `TrombinoscopePdfService`, `Pdf\TrombinoscopeHtmlBuilder`, `Pdf\StaffPhotoEmbedder`, `Pdf\DirectoryDensity`, la route `/trombinoscope/pdf` et le bouton de téléchargement |
| IT-03 | Les pages de section — `Pdf\SectionDensity`, en-tête coloré, grille de portraits, pied avec l'adresse de la section |

**Les décisions autonomes**, celles qu'un relecteur voudra contester en
premier :

1. **L'adresse d'une section est désormais affichée à l'écran aussi**, sous
   son en-tête, et pas seulement dans le PDF (IT-01, décision 2). Le
   document dit que le réglage ne la gouverne pas et qu'elle « reste donc
   affichée réglage décoché » ; à l'écran elle n'était affichée nulle part.
   Sans elle, la page décochée ne dit plus où écrire, ce qui est exactement
   ce que le document veut éviter.
2. **Le numéro affiché est le mobile, à défaut le téléphone fixe**
   (IT-01, décision 3). `MemberProfile` porte les deux ; la maquette n'en
   dessine qu'un.
3. **Le pied de page courant du PDF numérote la page sans annoncer de
   total** (IT-02, décision 3) — dompdf ne résout pas `counter(pages)`.
4. **Le document ne s'auto-limite pas à une feuille par découpe.** Les
   trois paliers y suffisent largement (quatorze sections tiennent, avec de
   la marge) ; en pousser une hors de la page pour tenir une promesse
   vaudrait moins qu'une seconde feuille (IT-02, décision 4).
5. **Le téléchargement n'est pas journalisé** (IT-02, décision 5) : le
   document ne contient rien que la même personne ne voie déjà à l'écran,
   et le journal ne doit porter aucune donnée personnelle.

**Divergences constatées avec le document de chantier :**

- **`Core\Photo\StaffThumbnailProcessor` n'existe plus.** Le document
  demande de produire du JPEG « à la place de son WebP ». La classe et sa
  route ont été supprimées lorsque `GET /files/{id}/thumb` est apparu
  (`ARCHITECTURE.md` §8.39, `SECURITY.md` §6). L'équivalent actuel est
  `Core\Photo\ImageVariantService`, dont la variante `thumb` est un carré
  de 192 px généré à l'upload — toujours en WebP, donc le besoin est
  intact et seul le point d'entrée change.
- **La maquette annonce « page 4 / 8 » dans l'en-tête d'une page de
  section.** Non transposable : voir la décision 3.
- **Le contenu RGPD par défaut décrivait le trombinoscope comme
  « accessible sans authentification »**, ce que la route
  (`role_min: identified`) n'a jamais été. Corrigé dans la même passe, avec
  le reste du bloc.
- **`specifications.md` §34 affirmait « No contact details are shown ».**
  Réécrit : c'est précisément ce qu'IT-01 change.

---

## IT-01 — Le réglage et les contacts à l'écran

### Fait

- `modules/trombinoscope/module.json` : section `settings` (elle était
  vide), une entrée `trombinoscope_show_contacts`, booléenne, à `1` par
  défaut, avec sa `description` non nulle. Version portée à `1.1.0`.
- `TrombinoscopeController` reçoit `SettingService` et expose
  `SETTING_SHOW_CONTACTS` ; `showsContacts()` est le seul endroit qui lit
  le réglage, pour les trois surfaces.
- `views/partials/contacts.html.twig` : téléphone et adresse sous le nom,
  en `tel:`/`mailto:`, l'adresse en `text-break` (jamais tronquée).
- L'adresse de la section apparaît sous son en-tête, hors réglage.
- Sujet d'aide et contenu RGPD par défaut mis à jour.

### Décisions

1. **Une constante publique pour la clé du réglage**, plutôt qu'une chaîne
   répétée : le test du contrôleur la nomme, et un renommage ne peut plus
   laisser un appelant derrière.
2. **L'adresse de section passe à l'écran** — voir le récapitulatif.
3. **Mobile d'abord, fixe ensuite.**

---

## IT-02 — Le rendu PDF et la page annuaire

### Fait

- `Service\TrombinoscopePdfService` — rassemble les sections et leur staff,
  produit les vues, appelle le constructeur de HTML, rend via dompdf avec
  le cadre de `Core\Pdf\PosterPdfService` (`isRemoteEnabled = false`,
  `DejaVu Sans`, `@page { margin: 15mm }`, A4).
- `Pdf\TrombinoscopeHtmlBuilder` — tout le balisage, séparé du service pour
  que la mise en page s'assertionne comme une chaîne, sans moteur PDF.
- `Pdf\StaffPhotoEmbedder` — lit la variante `thumb` et la ré-encode en
  JPEG 150 px, avec repli sur l'original, cache par membre, et `null` pour
  tout ce qu'il ne sait pas lire.
- `Pdf\DirectoryDensity` — les trois paliers, plus le seuil propre au pied
  de page.
- `Pdf\SectionView` / `Pdf\StaffView` — les vues, construites **avec** le
  réglage appliqué.
- Route `GET /trombinoscope/pdf` (`role_min: identified`, sans libellé de
  menu), action `pdf()`, bouton « Télécharger le PDF » en action primaire
  de l'en-tête de page. Version portée à `1.2.0`.

### Décisions

1. **La bande de couleur est une bordure gauche épaisse sur le tableau de
   la carte**, pas une cellule : une cellule vide ne prend pas la hauteur
   de sa ligne sous dompdf et la bande s'arrêtait au milieu de la carte.
2. **Un disque se centre avec un tableau d'une ligne dont la hauteur est
   déclarée sur `<tr>` ET sur `<td>`.** `line-height` sur un bloc de
   hauteur fixe ne centre pas sous dompdf — les initiales se collaient en
   haut du cercle.
3. **Pied de page courant sans total** — voir le récapitulatif.
4. **Pas de découpe de sécurité** — voir le récapitulatif.
5. **Pas de journalisation du téléchargement** — voir le récapitulatif.
6. **La couleur reçue est revalidée** dans le constructeur de HTML avant
   d'entrer dans un attribut `style`, bien qu'elle vienne déjà de
   `SectionService::colorForSection()`.

### Reporté

Rien.

---

## IT-03 — Les pages de section

### Fait

- `Pdf\SectionDensity` — trois colonnes jusqu'à neuf animateurs, quatre
  jusqu'à seize, cinq au-delà, avec la longueur de nom qui suit.
- En-tête de page : barre de couleur, nom de la section en grand dans sa
  couleur, branche · effectif · année.
- Grille de portraits : photo ou disque d'initiales, totem, nom civil,
  pastille « Responsable », coordonnées si le réglage est actif.
- Pied : l'adresse de la section, en grand et dans sa couleur.
- `page-break-before` sur chaque page de section.

### Décisions

1. **Les totems et les noms civils sont tronqués en PHP**, pas en CSS :
   dompdf n'a pas `text-overflow: ellipsis`. La limite suit le palier.
2. **La pastille « Responsable » est un tableau d'une cellule**, parce
   qu'un bloc prendrait toute la largeur de la carte et deviendrait un
   bandeau.
3. **Une section sans animateur garde sa page**, avec une phrase le disant
   — même raisonnement que la carte sans responsable sur l'annuaire.

### Reporté

Rien.
