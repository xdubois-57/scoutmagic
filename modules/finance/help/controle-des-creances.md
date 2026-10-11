---
id: controle-des-creances
title: Le contrôle des créances
summary: Toutes les créances connues de ScoutMagic, quel que soit le module qui les a créées, comparées à ce qui est arrivé sur le compte.
category: Espace animateurs
role_min: intendant
question: Où voir ce que les familles doivent encore ?
question: Comment savoir qui n'a pas encore payé ?
question: Pourquoi un paiement reste-t-il marqué « Partiel » ?
question: Pourquoi n'y a-t-il aucun bouton sur le contrôle des créances ?
paths: /finance/receivables
related: finances, rapprochement, importer-extraits
---

Cette page répond à une seule question : **que croit Finance devoir
recevoir, et qu'a-t-il déjà vu arriver, toutes origines confondues ?**
Chaque ligne compare le montant attendu au montant reçu et affiche
**Payé**, **Partiel** ou **Non payé**.

**Aucune action n'est attendue ici.** Une créance se gère dans le module
qui l'a créée : une campagne dans « Campagnes », un formulaire payant dans
les réponses du formulaire, une location sur la page de la réservation.
Quand ce module sait vous y mener, la page propose un lien « Ouvrir … »
vers le bon écran — et seulement si cet écran vous est ouvert. Le
parcours prévu est donc : constater une incohérence, l'identifier ici,
suivre le lien, agir là-bas.

**Rien ne s'y coche à la main.** Le statut se calcule depuis les extraits
bancaires importés, en rapprochant la communication structurée du
virement. Un paiement fait en plusieurs fois est additionné
automatiquement, et une ligne reste « Partiel » tant que le compte n'a
pas tout reçu.

## Comment la liste est organisée

D'abord par **origine** : ce qui a créé la créance. « Campagnes » pour une
campagne de paiement, « Formulaires » pour un formulaire d'actualité
payant, « Locations » pour une réservation. Chaque origine annonce son
total reçu sur son total attendu.

À l'intérieur, un second niveau n'apparaît **que s'il regroupe vraiment
quelque chose** — un formulaire répondu par trente familles, une
réservation et sa caution. Quand chaque créance est seule de son espèce,
la liste s'affiche directement : un sous-groupe par ligne n'apprendrait
rien à personne.

Ces groupes portent le nom que leur donne le module qui les a créés : le
titre de l'article pour un formulaire, la référence et le nom du
locataire pour une location. Un objet supprimé depuis n'a plus de nom, et
le groupe s'affiche alors avec son numéro, sans lien.

La colonne « Nom/Contact » montre le texte écrit par le module quand il y
en a un, sinon le nom du membre qui doit cette somme — son nom actuel,
même s'il a quitté l'unité depuis. Elle reste vide pour une créance qui
ne vise personne de l'unité, un locataire extérieur par exemple.

## Ce que vous ne voyez pas

Les créances d'un compte que votre rôle ne vous laisse pas voir
n'apparaissent pas du tout — ni leurs lignes, ni leurs montants dans les
totaux. C'est la même règle que partout ailleurs dans le module (voyez
« Qui voit quels comptes » dans l'aide des finances) : un compte qui ne
vous est pas ouvert ne l'est pas davantage ici.
