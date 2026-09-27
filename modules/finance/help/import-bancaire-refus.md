---
id: import-bancaire-refus
title: "Import bancaire : lignes écartées et refus"
summary: Pourquoi des lignes d'un relevé n'ont pas été importées, ou pourquoi tout le fichier a été refusé.
category: Espace animateurs
role_min: intendant
question: Pourquoi certaines lignes de mon relevé n'ont-elles pas été importées ?
question: Pourquoi mon import bancaire a-t-il été refusé ?
paths: /finance/import
related: importer-extraits, finances
---

## Les lignes mises de côté

La page de résultat nomme chaque IBAN du fichier dont les lignes n'ont
pas été importées, et dit pourquoi :

- **aucun compte du site ne porte cet IBAN** : le compte n'existe pas
  encore sur le site. Le superadmin l'ajoute, avec son IBAN, dans
  Configuration › Comptes ; réimportez ensuite le même fichier, les
  lignes déjà entrées seront ignorées. Aucun compte n'est jamais créé
  à partir d'un relevé ;
- **le compte n'est pas actif**, ou **plusieurs comptes actifs portent
  le même IBAN** : c'est à régler dans Configuration › Comptes ;
- **vous n'avez pas accès au compte** : il est réservé à un rôle plus
  élevé ou au trésorier d'une autre section.

## Les refus

- **Aucune année scoute ne couvre une date du fichier** : l'exercice
  comptable est l'année scoute, et elle ne se crée jamais depuis un
  relevé. La page nomme les dates et l'année manquante ; celle-ci se
  prépare depuis la page « Année scoute ». Si les dates vous semblent
  fausses, c'est le fichier qu'il faut vérifier.
- **Le solde de départ manque** au premier import d'un compte, pour un
  fichier qui ne le donne pas (CSV BNP).
- **Un solde saisi alors qu'il n'a pas lieu d'être** : le fichier le donne
  déjà (CODA), il couvre plusieurs comptes, ou le compte a déjà un solde de
  référence. Laissez le champ vide.
- **Un fichier CODA incohérent** : les mouvements d'un relevé ne mènent pas à
  son solde final. Le fichier est probablement tronqué ; exportez-le à
  nouveau.

Dans tous ces cas, **rien n'est importé**, sur aucun compte : un
fichier entre en entier ou pas du tout.
