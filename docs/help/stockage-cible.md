---
id: stockage-cible
title: Changer la cible d'un emplacement
summary: Renommer un emplacement, le rattacher à un stockage qui existe déjà, ou lui créer une nouvelle cible — trois gestes que le site ne confond jamais.
category: Configuration
role_min: superadmin
question: Comment rattacher un emplacement à un stockage qui contient déjà des fichiers ?
question: Mon emplacement Google Drive ne trouve plus son dossier, que faire ?
paths: /config/stockage/emplacements, /config/stockage/emplacements/*/modification, /config/stockage/emplacements/*/google/dossier-existant
related: stockage, stockage-google-drive-dossiers, restaurer
---

Trois gestes différents, que le site ne confond jamais :

- **Renommer** un emplacement change son nom, et rien d'autre : ses
  fichiers restent où ils sont.
- **Changer sa cible** — un autre dossier, une autre adresse de
  partage, un autre bucket — le rattache à un autre stockage. Si
  celui-ci contient déjà des fichiers, le site vous le dit et attend que
  vous cochiez une case de confirmation. Il ne copie, ne déplace et
  n'efface rien : il lira et écrira à côté de ce qui s'y trouve.
- **Créer une nouvelle cible**, c'est choisir un dossier vide, ou un
  nouvel emplacement. Le site ne le fait jamais de lui-même, ni après
  une restauration, ni quand une cible ne répond plus.

## Google Drive : quand le dossier a disparu

Après une restauration, ou si le dossier a été mis à la corbeille, un
emplacement peut viser un dossier qui n'existe plus alors que vos
fichiers sont ailleurs. Le site ne crée **jamais** un nouveau dossier
de lui-même pour le remplacer, même quand vous reconnectez le compte :
il garde l'autorisation, vous prévient, et vous laisse choisir sur la
fiche de l'emplacement.

- **Rattacher un dossier existant** : collez l'adresse du dossier qui
  contient vos fichiers, telle que Google Drive l'affiche dans votre
  navigateur. Le site le désigne par cette adresse, jamais par son
  nom. Il vérifie que c'est bien un dossier, hors de la corbeille, qu'il
  peut voir et qu'aucun autre emplacement n'utilise, ni directement ni
  en le contenant. Le dossier commun **ScoutMagic** lui-même est refusé.
  Sont refusés aussi, avec la raison, un dossier rangé directement
  dans « Mon Drive » et un dossier dont le site ne voit pas où il est
  rangé : dans les deux cas, déplacez-le dans **ScoutMagic** depuis
  Google Drive, puis recommencez. S'il contient déjà des fichiers, il
  vous demande de confirmer. Rien n'y est copié, déplacé ni renommé.
- **Créer un nouveau dossier** : un dossier vide au nom de
  l'emplacement, sous **ScoutMagic**. C'est refusé tant que l'ancien
  dossier existe encore : ce qu'il contient ne serait plus lu par le
  site. Et même quand c'est possible, ce n'est pas une reprise : les
  anciens fichiers restent où ils sont, simplement hors de la vue du
  site.

Le site ne voit que les dossiers qu'il a lui-même créés : un dossier
fait à la main dans Drive ne peut pas être rattaché.
