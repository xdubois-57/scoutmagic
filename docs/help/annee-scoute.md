---
id: annee-scoute
title: Passer à la nouvelle année scoute
summary: Les quatorze étapes du changement d'année, ce qui bloque vraiment, et la revue avant la bascule.
category: Espace chefs d'U
role_min: admin
question: Comment faire passer le site à l'année scoute suivante ?
question: Que faut-il finir avant de basculer d'année ?
question: Peut-on revenir en arrière après la bascule d'année ?
paths: /admin/scout-year
related: import-desk, import-desk-annee, annee-vue-pendant-la-transition, membres-par-section
---

La page « Année scoute » accompagne tout le changement d'année vers
l'année cible, celle qui suit l'année publique. Le résumé en tête
compte les étapes faites, les points à vérifier et les blocages ; la
ligne « Dernière bascule » rappelle la précédente.

## L'ordre conseillé

Trois phases : « Préparation » (départs, passages, nouvelles
inscriptions), « Encodage et contrôles Desk » (staffs et animés dans
Desk, import, cotisations, prévisualisation), puis « Mise à jour du site
et publication » (activation pour les staffs, éphémérides, badges,
photos, et la bascule). Les étapes d'un module désactivé disparaissent
et la numérotation suit. Les dates des phases sont des repères :
l'ordre est un conseil, vous pouvez agir sur n'importe quelle étape.

## Marquer une étape

Chaque étape porte une étiquette : « Décision humaine » ou « Constaté
par le site ». Pour une décision humaine, le rond numéroté est le seul
contrôle : un clic la marque « Étape faite », un clic sur le rond vert
la remet à faire, sans recharger la page. L'import et les deux
activations, le site les constate lui-même.

## Une action, et « Détails »

Chaque étape montre une seule action, la plus utile selon son état
(« Importer » tant que l'année cible n'a pas d'import réussi, puis
« Activer pour les staffs »). « Détails » ouvre une fenêtre avec les
contrôles, les personnes à corriger, qui a marqué l'étape et quand, et
toutes ses actions.

Les points « à vérifier » sont une aide, jamais un verrou : une étape
peut être marquée faite malgré eux, et ils restent affichés. Avant
l'import de l'année cible, un contrôle qui en dépend dit seulement
qu'il sera disponible après l'import.

## Les trois vrais blocages

Le serveur les vérifie, même pour une requête envoyée sans la page :

1. l'étape des départs ne peut pas être marquée tant que la campagne de
   réinscription de l'année cible n'est pas terminée ;
2. l'activation pour les staffs attend un import Desk réussi de
   l'année cible ;
3. la bascule publique attend que l'année du staff soit l'année cible,
   cet import réussi, et qu'aucune demande d'inscription ne reste
   ouverte — la page indique combien et renvoie vers leur gestion, où
   un traitement en masse (« tout refuser », « tout retirer ») existe.

## La revue avant la bascule

« Revoir avant la bascule… » ouvre une fenêtre qui rappelle en grand
l'année publique et l'année cible, liste les blocages avec le lien qui
résout chacun — le bouton « Basculer sur » reste alors désactivé —,
puis les points à vérifier (qui n'empêchent pas la bascule) et les
étapes non faites. Les boutons qui agissent tout de suite demandent
confirmation ; aucun n'envoie d'e-mail.

> La bascule publique est immédiate et visible par toutes les
> familles : l'année du staff s'efface, tout le monde est réaligné.
> Elle n'est jamais automatique, même si l'année publique est dépassée.

Après la bascule, la page prépare l'année suivante, depuis le début.
