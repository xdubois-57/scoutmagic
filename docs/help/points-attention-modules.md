---
id: points-attention-modules
title: Ce que signalent les modules
summary: Les points d'attention que chaque module ajoute quand il est activé, et ce qui les fait disparaître.
category: Espace chefs d'U
role_min: admin
discovery: off
question: Pourquoi un point sur l'adresse du bailleur d'une location ?
question: Pourquoi le site dit-il qu'une boîte ne se synchronise plus ?
question: Pourquoi un point sur le numéro SOS ?
paths: /admin/points-attention
related: points-attention-liste, points-attention
---

Un module ne signale rien tant qu'il est désactivé : ses points
apparaissent le jour où on l'active, et disparaissent si on le
désactive.

Le module Réseaux sociaux signale **une connexion Facebook ou Instagram
qui ne marche plus** : l'autorisation a expiré, ou Meta a refusé la
dernière vérification. Meta ne renouvelle pas une autorisation expirée :
il faut refaire la connexion dans Configuration › Réseaux sociaux.

Le module Locations signale, pour chaque bien qui n'est pas archivé :

- **l'adresse du bailleur vide** : le contrat et la facture de ce bien
  imprimeraient « — » à sa place. Si ce bien a son propre bailleur,
  c'est son adresse à lui qu'il faut renseigner ; sinon, celle du
  bailleur commun du module, ou à défaut l'adresse postale de l'unité.
  Le point dit lequel des trois manque ;
- **une conformité déjà expirée** dans le registre du bien, avec la date
  d'expiration. Avant cette date, c'est le rappel du registre qui
  prévient, pas cette page.

Le module Discussions signale, groupe par groupe, **les contenus signalés
qui attendent encore une décision** : tant qu'un modérateur ne les a ni
rétablis ni supprimés, ils restent comptés, même déjà masqués.

Le module Inscriptions signale, en une seule ligne, **les demandes en
attente depuis plus de 15 jours**, à partir du 1er juin et jusqu'à la fin
de l'année scoute. Le jour, le mois et la durée se règlent dans
Configuration › Paramètres, section Inscriptions.

Le module Courrier entrant signale **une boîte qui ne se synchronise
plus** depuis plus d'une heure et deux relèves, ou qui n'a jamais été
relevée avec succès, et **l'absence de toute boîte active**. La page de
configuration du module donne l'erreur.

Le module Intelligence artificielle signale **un fournisseur absent** et
**un dernier appel en échec** : le point disparaît au prochain appel
réussi, ou après un test de connexion réussi du fournisseur actif.

Le module Téléphone d'urgence signale **un opérateur non configuré** et
**un dernier changement de redirection en échec** : les appels arrivent
peut-être encore chez la personne de garde précédente.
