---
id: campagnes
title: Lancer une campagne de paiement
summary: Facturer un montant à chaque membre d'une liste, et suivre les paiements reçus.
category: Espace animateurs
role_min: intendant
paths: /finance/campaigns, /finance/campaigns/new, /finance/campaigns/*
related: rapprochement, finances, importer-extraits, outils-finance
---

Une campagne facture un montant à chaque membre d'une liste — les
cotisations de l'année, le solde d'un camp, un week-end — et suit les
paiements au fur et à mesure que les extraits bancaires arrivent.

## Une créance par membre

Chaque membre de la liste reçoit **sa** demande, avec **sa**
communication structurée. Une famille de trois enfants reçoit donc trois
demandes, et pas une seule au nom du foyer.

C'est ce qui rend chaque virement identifiable à son arrivée. Demandez
aux familles **un virement par créance** : c'est la phrase à mettre dans
le rappel, avec sa raison. Un virement groupé se répartit ensuite à la
main, mais c'est du travail que la consigne évite.

## Préparer le fichier

Trois étapes, dans cet ordre :

1. Exportez la liste des membres concernés depuis la page « Membres par
   section ».
2. Ajoutez une colonne **Montant** au fichier, et complétez-la ligne par
   ligne — les montants peuvent différer d'une personne à l'autre.
3. Rechargez le fichier sur la page « Campagnes », sans supprimer les
   autres colonnes.

> Ne reconstruisez jamais la liste à la main dans Excel. La colonne
> « ID interne » produite par l'export est ce qui rattache chaque montant
> au bon membre. Sans elle, le chargement est refusé — et le site ne
> devinera pas un membre à partir de son nom : deux frères portent le
> même nom de famille, et se tromper d'enfant ne se voit nulle part
> ensuite.

Une ligne dont l'identifiant est absent, vide ou inconnu fait échouer le
chargement **entier** : aucune campagne n'est créée, et l'écran nomme
toutes les lignes fautives d'un coup. Les colonnes que vous gardez en
plus sont libres : elles deviennent les variables de fusion des rappels.

## Suivre les paiements

L'écran d'une campagne trie les créances par nom de famille, pour que
celles d'un même foyer se suivent. Le filtre par défaut, « À traiter »,
montre les impayées, les partielles, et les payées qui portent un
trop-perçu non réglé — celles-là appellent encore un geste.

**L'export reprend exactement les créances affichées.** Le bouton porte
leur nombre : si vous regardez les 41 impayées, vous exportez 41 lignes,
pas la campagne entière. Changez de filtre avant d'exporter si ce n'est
pas ce que vous voulez.

Rien ne se coche à la main : un statut vient des relevés bancaires
importés. Abandonner une créance — une dispense, un geste commercial, une
erreur de facturation — la solde sans qu'aucun paiement n'entre en
caisse, et reste donc distinct d'un encaissement.

## Note interne

Chaque créance peut porter une note, visible des seuls trésoriers et
**jamais** de la famille. Elle apparaît dans l'export, jamais dans un
rappel.

## Clôturer

Clôturer une campagne arrête les rappels et la fige. Elle reste
consultable, et peut être rouverte.
