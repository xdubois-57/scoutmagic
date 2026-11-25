---
id: justesse-des-tarifs
title: Corriger les tarifs de cotisation dans Desk
summary: Les foyers dont la catégorie encodée ne correspond pas au nombre de personnes qui y vivent.
category: Espace chefs d'U
role_min: admin
discovery: 1
question: Quelles familles paient un tarif qui ne correspond pas ?
question: Comment corriger une catégorie de cotisation mal encodée ?
question: Où trouver les montants de cotisation de la fédération ?
paths: /admin/fees/tarifs
related: cotisations, import-desk, membres-admin
---

La fédération facture ce que Desk contient. Cette page compare la catégorie
encodée au nombre de personnes à la même adresse. Corrigez dans Desk : le
site n’y écrit jamais.

## Comprendre les résultats

Chaque foyer explique son verdict, puis montre pour chacun le code encodé,
le code attendu s’il est connu et l’état : Conforme, À corriger ou Non déterminable.
Le nombre de membres dans Desk et le nombre retenu pour le foyer sont séparés.

- **À corriger dans Desk** : un écart aujourd’hui, chiffré si les montants sont connus.
- **À prévoir** : le tarif changera après un départ ou une arrivée annoncés.
  Ne corrigez pas encore : Desk contient l’ancienne composition.
- **Non déterminables** : une date, l’année précédente, la période Invités ou
  le tarif Desk ne permet pas de conclure. Ces cas ne sont pas déclarés conformes.
- **Ignorés** : foyers mis de côté avec votre motif, par exemple une garde
  alternée. Une nouvelle composition les fait réapparaître.
- **Sans adresse** : membres non vérifiables, ni conformes ni en écart.

Un foyer peut nécessiter une correction maintenant et une autre à la bascule.
Un écart positif signifie « à déclarer en plus », donc une régularisation
à prévoir ; négatif signifie « déclaré en trop ».

## Le barème

**Barème des cotisations**, en haut, regroupe cinq montants par personne :
Normale, Couple, Familiale, IAmA liés à l’unité et Invités. Vide signifie
inconnu ; zéro est conservé. Ces valeurs ne créent aucune demande de paiement.

Pour l’année affichée, seules les lignes jamais enregistrées reçoivent les
valeurs fédérales livrées avec le site. Les lignes déjà enregistrées, même
vides, restent les vôtres. Le bandeau précise l’année, la source et la date
du relevé. Vérifiez, puis **Enregistrer le barème**.

Les sélecteurs corrigent les correspondances devinées avec vos tarifs Desk.
Sans tarif Invités importé, son sélecteur est absent : aucun code n’est inventé.

## La période Invités

Début et fin inclus se règlent au format **jour/mois**, par défaut
**01/06–31/08** ; montant fédéral Invités : **18,25 €**. Les dates se situent
dans l’année civile de fin de saison : 2027 pour 2026-2027. Le texte sous les
champs reflète votre saisie.

Une nouvelle inscription concerne une personne absente de l’année précédente,
dont la première de **toutes** les fonctions de l’année commence dans cette période.
Une présence inactive l’année précédente exclut aussi le tarif Invités.
Sans import de l’année précédente, l’absence n’est pas prouvée. Une
réinscription ou un changement de section n’est pas une nouvelle inscription.

Les **Invités prouvés ne comptent pas** pour le seuil Couple/Familial, même si
leur tarif Desk est ordinaire ; aucun code Invités n’est demandé pour cette
application par les dates. Les **IAmA comptent** et gardent leur tarif choisi
dans Desk. Le site ne prétend pas décider qui devrait être IAmA.

Si certains membres pourraient être Invités sans preuve suffisante, le décompte
affiche une plage. Trois membres certainement retenus suffisent à établir le
tarif Familial, même si un quatrième reste non déterminable. Les inscriptions
acceptées n’ont pas encore toutes les informations nécessaires : elles
élargissent la plage projetée, sans inventer leur tarif.

Le barème enregistré confirme ses montants et ses dates pour **l’année affichée**.
Une ancienne année ne prend pas les prix actuels. Un montant inconnu reste
inconnu ; le total n’affiche pas la somme partielle des seuls écarts chiffrables.

## Chercher les valeurs fédérales

Avec l’IA configurée, **Chercher les montants et la période** conserve vos
champs et propose séparément les valeurs trouvées pour l’année affichée.
La proposition indique source, année et champs manquants. Un échec conserve
votre saisie ; une année absente ou différente ne produit aucune proposition.

**Reprendre les montants** garde vos dates ; **Reprendre les montants et la
période** reprend aussi les bornes trouvées. Seules les valeurs trouvées
remplacent les champs ; complétez les autres. Rien n’est enregistré avant
**Enregistrer le barème**. Une proposition remplacée dans un autre onglet
ou liée à une autre année est refusée. L’adresse de la source se règle dans
Configuration > Réglages.
