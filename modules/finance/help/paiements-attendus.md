---
id: paiements-attendus
title: Les paiements attendus
summary: Ce que les familles et les locataires doivent, comparé à ce qui est arrivé sur le compte.
category: Espace animateurs
role_min: intendant
question: Où voir ce que les familles doivent encore ?
question: Comment savoir qui n'a pas encore payé ?
question: Pourquoi un paiement reste-t-il marqué « Partiel » ?
paths: /finance/receivables
related: finances, rapprochement, importer-extraits
---

La page suit ce qui a été demandé et ce qui est arrivé. Chaque ligne
compare le montant attendu au montant reçu et affiche **Payé**,
**Partiel** ou **Non payé**.

**Rien ne s'y coche à la main.** Le statut se calcule depuis les extraits
bancaires importés, en rapprochant la communication structurée du
virement. Un paiement fait en plusieurs fois est additionné
automatiquement, et une ligne reste « Partiel » tant que le compte n'a
pas tout reçu.

## Comment la liste est organisée

D'abord par **origine** : ce qui a créé l'attente. « Formulaires » pour
un formulaire d'actualité payant, « Locations » pour une réservation.
Chaque origine annonce son total reçu sur son total attendu.

À l'intérieur, un second niveau n'apparaît **que s'il regroupe vraiment
quelque chose** — un formulaire répondu par trente familles, une
réservation et sa caution. Quand chaque attente est seule de son espèce,
la liste s'affiche directement : un sous-groupe par ligne n'apprendrait
rien à personne.

Ces groupes portent le nom que leur donne le module qui les a créés : le
titre de l'article pour un formulaire, la référence et le nom du
locataire pour une location. Un objet supprimé depuis n'a plus de nom, et
le groupe s'affiche alors avec son numéro.

La colonne « Nom/Contact » montre le texte écrit par le module quand il y
en a un, sinon le nom du membre qui doit cette somme — son nom actuel,
même s'il a quitté l'unité depuis. Elle reste vide pour une créance qui
ne vise personne de l'unité, un locataire extérieur par exemple.

## Ce que vous ne voyez pas

Les paiements attendus sur un compte que votre rôle ne vous laisse pas
voir n'apparaissent pas du tout — ni leurs lignes, ni leurs montants
dans les totaux. C'est la même règle que partout ailleurs dans le module
(voyez « Qui voit quels comptes » dans l'aide des finances) : un compte
qui ne vous est pas ouvert ne l'est pas davantage ici.
