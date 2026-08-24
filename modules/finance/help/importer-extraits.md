---
id: importer-extraits
title: Importer un extrait bancaire
summary: Charger le relevé CSV de la banque et garder les soldes justes.
category: Espace animateurs
role_min: intendant
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

1. Choisissez le compte concerné, la banque et le fichier.
2. Au **premier import** d'un compte, indiquez le solde après ce
   relevé — il sert de point de départ. Ensuite, le champ devient
   facultatif : rempli, il sert de vérification.
3. Touchez « Importer ».

La page de résultat compte les lignes lues, les nouvelles et celles
déjà présentes : réimporter un fichier qui recouvre une période déjà
chargée ne crée **aucun doublon**. Si un écart de solde est détecté,
la page vous invite à vérifier qu'aucune période ne manque.

## Les deux refus à connaître

- **L'IBAN ne correspond pas** : le fichier appartient à un autre
  compte que celui choisi. Rien n'est importé — c'est une protection,
  pas une panne. Vérifiez le compte sélectionné.
- **Aucun exercice comptable ne couvre la date** : créez d'abord
  l'exercice correspondant (configuration du module), puis relancez.
  Là aussi, rien n'est importé à moitié.

## Après l'import

Les règles de catégorisation passent automatiquement sur les nouvelles
lignes, et les justificatifs en attente sont confrontés aux nouveaux
mouvements. Il ne reste qu'à traiter ce que le tableau de bord signale
encore « à catégoriser ».
