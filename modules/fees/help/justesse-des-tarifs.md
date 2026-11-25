---
id: justesse-des-tarifs
title: Corriger les tarifs de cotisation dans Desk
summary: Comparer les tarifs saisis aux personnes retenues pour le foyer.
category: Espace chefs d'U
role_min: admin
discovery: 1
question: Quelles familles paient un tarif qui ne correspond pas ?
question: Comment corriger une catégorie de cotisation mal encodée ?
question: Où trouver les montants de cotisation de la fédération ?
paths: /admin/fees/tarifs
related: cotisations, import-desk, membres-admin
---

Le site compare les tarifs saisis dans Desk aux personnes retenues au même
foyer. Corrigez dans Desk : le site n’y écrit jamais.

## Lire les résultats

La carte distingue le nombre de membres dans Desk du décompte retenu pour
le tarif. Elle montre les codes réels, le tarif applicable et les limites.

- **À corriger dans Desk** : écarts certains aujourd’hui.
- **À prévoir** : changement certain après un mouvement annoncé. Les départs
  comptent encore aujourd’hui ; n’anticipez pas leur retrait.
- **Non déterminables** : données insuffisantes pour conclure.
- **Ignorés** : foyers écartés avec votre motif ; une nouvelle composition
  les fait réapparaître.
- **Sans adresse** : membres sans foyer vérifiable.

Un foyer peut avoir plusieurs verdicts. Un écart positif signifie « à déclarer
en plus », négatif « déclaré en trop ». Un total incomplet reste inconnu.
L’export reprend ces résultats.

## Invités et IAmA

Un Invité est absent de l’année précédente et sa première de **toutes** les
fonctions commence dans la période inclusive. Une présence précédente,
même inactive, l’exclut ; sans import précédent, l’absence n’est pas prouvée.

Les **Invités prouvés ne comptent pas** pour Couple/Familial, même sans code
Invités dans Desk. Les **IAmA comptent** et gardent le tarif choisi dans Desk ;
le site ne décide pas qui devrait être IAmA.

Un invité potentiel contribue zéro ou un : le décompte affiche une plage.
Trois personnes certaines suffisent pour Familiale. Les inscriptions acceptées
sans informations suffisantes élargissent aussi la plage projetée.

## Confirmer le barème

Les cinq montants sont Normale, Couple, Familiale, IAmA et Invités.
Vide signifie inconnu ; zéro est conservé. Les correspondances utilisent les
vrais codes Desk ; aucun code Invités n’est inventé.

La période se règle en **jour/mois**, début et fin inclus : **01/06–31/08**
par défaut. Elle se situe dans l’année civile de fin de saison : 2027 pour
2026-2027. **Enregistrer le barème** confirme montants et dates pour l’année
affichée ; les prix courants ne chiffrent pas une autre année.

## Chercher les valeurs fédérales

Avec l’IA configurée, **Chercher les montants et la période** conserve vos
champs et propose séparément les valeurs trouvées, leur source et les manques.
Une année absente ou différente ne produit aucune proposition.

**Reprendre les montants** garde vos dates ; l’autre bouton reprend aussi
la période. Seuls les champs trouvés remplacent votre saisie. Rien n’est
sauvé avant **Enregistrer le barème**. Une proposition remplacée dans un autre
onglet est refusée. L’adresse de la source se règle dans Configuration > Réglages.
