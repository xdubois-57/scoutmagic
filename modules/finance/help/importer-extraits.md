---
id: importer-extraits
title: Importer un extrait bancaire
summary: Charger les extraits CODA ou le CSV BNP — chaque ligne rejoint le compte de son IBAN — et garder les soldes justes.
category: Espace animateurs
role_min: intendant
discovery: 1
question: Comment charger le relevé bancaire du mois ?
question: Qu'est-ce qu'un fichier CODA ?
question: Pourquoi mon solde ne correspond-il pas à celui de la banque ?
paths: /finance/import
related: finances, recus, import-bancaire-refus
---

Les mouvements n'arrivent jamais à la main : ils viennent du fichier
d'extraits exporté depuis la banque. Importer régulièrement garde les
soldes et les paiements attendus à jour.

## Préparer le fichier

Exportez les extraits depuis l'espace en ligne de la banque, de préférence au
format **CODA** : toutes les banques belges le proposent, et un seul fichier
peut couvrir plusieurs comptes. Le CSV de BNP Paribas Fortis est lu aussi.

## Importer

1. Déposez le fichier. Il n'y a **ni compte ni format à choisir** : le format
   est reconnu tout seul, et chaque mouvement rejoint le compte du site qui
   porte son IBAN, celui que la banque écrit dans le fichier.
2. **Le solde** : un fichier CODA le donne lui-même pour chaque compte —
   laissez le champ vide. Seul le CSV BNP ne le donne pas : au **premier
   import** d'un compte, indiquez alors le solde après ce relevé, qui sert de
   point de départ ; ensuite, il se recalcule depuis les mouvements.
3. Touchez « Importer ».

Si le format n'est pas reconnu, la page le dit et propose alors, seulement
alors, la liste des formats : choisissez-le et déposez le fichier à nouveau.

La page de résultat détaille, compte par compte, les lignes lues, les
nouvelles et celles déjà présentes : réimporter un fichier qui recouvre une
période déjà chargée ne crée **aucun doublon**. Si le solde du fichier ne
correspond pas à celui que le site calcule, la page le signale : vérifiez
qu'aucune période ne manque.

## Si quelque chose est écarté ou refusé

La page de résultat nomme chaque IBAN mis de côté et chaque refus ; le sujet
d'aide consacré aux lignes écartées et aux refus explique chacun.

## Après l'import

Les règles de catégorisation passent automatiquement sur les nouvelles
lignes, et les justificatifs en attente sont confrontés aux nouveaux
mouvements. Il ne reste qu'à traiter ce que le tableau de bord signale
encore « À catégoriser ».
