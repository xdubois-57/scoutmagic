# Chantier — Exigences opérationnelles : disque, alertes, sauvegardes

Journal d'implémentation du document de chantier « Exigences
opérationnelles » (itérations IT-01 à IT-09). Une section par itération :
ce qui a été livré, les décisions prises en autonomie, les divergences
constatées entre le document de chantier et le dépôt réel, et ce qui a été
reporté. Même format que `docs/chantiers/aide-contextuelle.md`.

Le document de chantier lui-même est une pièce jointe et ne se trouve pas
dans le dépôt : ce journal est la seule trace qui y survit.

---

## IT-01 — Écrire les exigences non fonctionnelles

**Livré.** `docs/exigences-non-fonctionnelles.md` — cible de
dimensionnement, budget de rendu, objectifs de reprise, seuils d'alerte,
socle technique (PHP/MySQL, navigateurs, accessibilité). Référencé depuis
`CONTRIBUTING.md` (§ Before you start, point 5) et depuis l'en-tête
d'`ARCHITECTURE.md`, qui dit explicitement la répartition : l'un dit
comment le système est bâti, l'autre à quoi il doit tenir.

Documentation seule, aucun code, conformément au document de chantier.

**Décisions autonomes.**

1. **Le document est en anglais, son nom de fichier en français.** Le nom
   est celui que le document de chantier fixe ; le contenu suit ses deux
   voisins immédiats, `ARCHITECTURE.md` et `docs/quality-pipeline.md`,
   d'où il est référencé et avec lesquels il sera lu. `AGENTS.md`
   § Language ne tranche que le code et l'interface ; la documentation du
   dépôt est mixte (`README.md` et `docs/rental-guide.md` en français,
   destinés aux utilisateurs ; `ARCHITECTURE.md`, `AGENTS.md`,
   `docs/module-development.md`, `docs/quality-pipeline.md` en anglais,
   destinés aux contributeurs). Celui-ci est de la seconde famille.

2. **Deux seuils de plus que les quatre proposés**, pour les alertes que
   IT-09 devra publier : âge de la dernière sauvegarde distante réussie
   (10 jours / 3 jours) et occupation du quota distant (90 % / 80 %). Les
   écrire maintenant évite qu'IT-09 invente ses propres nombres hors de la
   page qui centralise les nombres. Aucun code ne les lit encore.

   Le seuil distant porte **les mêmes nombres que le seuil local**, et
   c'est le plus important des deux : dans le scénario dont parle
   réellement le RPO — le serveur n'existe plus — la vétusté de la copie
   hors site *est* la perte de données réalisée. Un premier jet écrivait
   21 jours, soit trois fois le RPO qu'il est censé garder ; corrigé en
   revue.

3. **Deux contraintes d'hôte chiffrées dans le budget de rendu** —
   `max_execution_time` à 30 s (jamais plus de 120 s) et 128 Mio de
   mémoire. Le document de chantier les invoque en prose dans IT-09 ; sans
   nombre opposable, « ça ne tient pas dans une requête » reste une
   opinion.

4. **Les valeurs de dimensionnement sont déduites, pas inventées** : 500
   membres par année et 5 années viennent du docblock de
   `Core\Member\Service\MemberSearchService` (« quelques centaines de
   membres », « cinq années scoutes, c'est cinq fois le travail AES ») ;
   178 membres est ce que contiennent réellement les exports **commités**
   de `tests/fixtures/reference-dataset/` : 176 / 178 / 178 `Tiers`
   distincts, comptés dans les trois fichiers, ce que confirme le
   `README.md` du jeu de données lui-même (« Membres actifs |
   176 / 178 / 178 »).

   Un premier jet écrivait 180, en lisant dans
   `docs/chantiers/reference-dataset.md` la ligne « Effectifs
   reconstruits » — qui décrit une instance jetable rebâtie par le
   builder, pas les fichiers commités. Ce journal-là n'a pas tort ; c'est
   la ligne qui avait été mal choisie. La citation renvoie désormais au
   `README.md` du jeu de données, qui en est le manuel et qui est tenu à
   jour.

   **Le volume de galerie a dû être refait.** Un premier jet écrivait
   3 Gio, en multipliant un nombre de médias par la taille d'une photo
   *envoyée*. La galerie conserve **trois rendus** par photo
   (`Modules\Gallery\Service\ImageProcessingService` : 3000 px q90,
   1200 px q85, 300 px q80) et `ProcessPhotoHandler` ne supprime que
   l'original — soit environ 2 Mio stockés par photo, et 8 Gio sur cinq
   ans.

   Le total `storage/` passe de 4 à 10 Gio, et **la correction ne portait
   pas que sur la galerie** : la part non-galerie n'avait jamais été
   chiffrée, elle valait 1 Gio implicitement, et elle en vaut 2. Elle est
   désormais une ligne à elle dans le tableau — une dizaine d'archives
   conservées à ~150 Mio pièce (une archive `full_no_gallery` embarque
   `vendor/`, qui la domine) plus cinq ans de pièces jointes — pour que le
   total soit une somme visible plutôt qu'un chiffre à croire. Le
   déséquilibre arithmétique du premier jet (+5 Gio de galerie, +6 Gio de
   total) a été relevé en revue.

   10 Gio dépasse ce que donne l'hébergement mutualisé le moins cher :
   c'est écrit noir sur blanc dans le document, avec ses trois issues
   (payer plus, garder moins d'années en ligne, ou passer la galerie sur
   `S3StorageBackend`). La
   vidéo est exclue du chiffre et signalée à part —
   `gallery_max_video_upload_mb` vaut 2 048 par défaut, donc une douzaine
   de vidéos de camp pèse plus que cinq ans de photos.

**Divergences constatées entre le document de chantier et le dépôt.**

1. **La fréquence par défaut des sauvegardes automatiques contredit les
   seuils d'alerte proposés.** `backup_auto_frequency` vaut `monthly` par
   défaut (`public/index.php`), et le seuil « âge de la dernière
   sauvegarde réussie » déclenche à 10 jours. Sur une installation
   laissée dans sa configuration par défaut, cette alerte serait donc
   **déclenchée en permanence** dès la mise en service d'IT-03 — c'est-à-
   dire inutile, et exactement le mécanisme d'extinction que la décision
   D1 cherche à éviter.

   L'objectif de reprise écrit en IT-01 (RPO 7 jours) tranche dans
   l'autre sens : une sauvegarde planifiée doit être **hebdomadaire**.
   Changer le défaut est du code, donc hors du périmètre d'IT-01. **C'est
   IT-03 qui portera ce changement**, dans la PR qui allume l'alerte —
   c'est la seule qui puisse le faire sans livrer une alerte fausse.

2. **`ARCHITECTURE.md` ne comportait aucun renvoi vers un document
   d'exigences non fonctionnelles** parce qu'il n'en existait aucun : le
   renvoi ajouté est une création, pas une mise à jour.

**Reporté.** Rien pour cette itération. `axe-core` dans la suite
Playwright reste explicitement hors chantier (section « Reporté » du
document de chantier), désormais adossé à un niveau d'accessibilité
énoncé.
