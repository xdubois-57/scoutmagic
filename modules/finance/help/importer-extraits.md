---
id: importer-extraits
title: Importer un extrait bancaire
summary: Charger le relevé de la banque — chaque ligne rejoint le compte de son IBAN — et garder les soldes justes.
category: Espace animateurs
role_min: intendant
discovery: 1
question: Comment charger le relevé bancaire du mois ?
question: Pourquoi mon solde ne correspond-il pas à celui de la banque ?
paths: /finance/import
related: finances, recus
---

Les mouvements n'arrivent jamais à la main : ils viennent du fichier
d'extraits exporté depuis la banque. Importer régulièrement garde les
soldes et les paiements attendus à jour.

## Préparer le fichier

Exportez l'historique du compte au format CSV depuis l'espace en ligne
de la banque. Le module lit aujourd'hui le format BNP Paribas Fortis ;
la liste « Banque » de la page montre les formats acceptés sur votre
site.

## Importer

1. Choisissez la banque et le fichier. Il n'y a **pas de compte à
   choisir** : chaque mouvement rejoint le compte du site qui porte son
   IBAN, celui que la banque écrit dans le fichier.
2. Au **premier import** d'un compte, indiquez le solde après ce
   relevé — il sert de point de départ. Ensuite, le champ devient
   facultatif : rempli, il sert de vérification.
3. Touchez « Importer ».

La page de résultat détaille, compte par compte, les lignes lues, les
nouvelles et celles déjà présentes : réimporter un fichier qui recouvre
une période déjà chargée ne crée **aucun doublon**. Si un écart de solde
est détecté, la page vous invite à vérifier qu'aucune période ne manque.

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

## Les refus à connaître

- **Aucune année scoute ne couvre une date du fichier** : l'exercice
  comptable est l'année scoute, et elle ne se crée jamais depuis un
  relevé. La page nomme les dates et l'année manquante ; celle-ci se
  prépare depuis la page « Année scoute ». Si les dates vous semblent
  fausses, c'est le fichier qu'il faut vérifier.
- **Le solde de départ manque** au premier import d'un compte.
- **Un solde saisi pour un fichier qui couvre plusieurs comptes** :
  laissez le champ vide.

Dans tous ces cas, **rien n'est importé**, sur aucun compte : un
fichier entre en entier ou pas du tout.

## Après l'import

Les règles de catégorisation passent automatiquement sur les nouvelles
lignes, et les justificatifs en attente sont confrontés aux nouveaux
mouvements. Il ne reste qu'à traiter ce que le tableau de bord signale
encore « À catégoriser ».
