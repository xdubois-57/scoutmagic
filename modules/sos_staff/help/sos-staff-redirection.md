---
id: sos-staff-redirection
title: Vérifier et corriger le renvoi SOS
summary: L'état réel du renvoi chez l'opérateur, et le bouton qui le remet d'aplomb.
category: Espace chefs d'U
role_min: admin
question: Le renvoi du numéro SOS ne va pas chez la bonne personne, que faire ?
question: Où voir vers qui le numéro SOS est renvoyé en ce moment ?
paths: /admin/sos, /admin/sos/transitions
related: sos-staff, sos-staff-settings
---

Le **Tableau de bord** est la page d'entrée de « Gérer le téléphone
d'urgence ». Son premier bloc, « Redirection actuelle », affiche l'état
**réel** du renvoi, lu chez l'opérateur — pas ce que le site croit avoir
fait : actif ou non, et vers quel numéro. En dessous, « Redirections
planifiées » liste les bascules à venir, la prochaine en évidence.

## Remettre le renvoi d'aplomb

Quand le renvoi réel ne correspond pas à ce que le planning prévoit pour
l'instant présent — la personne de garde, sinon le numéro par défaut —, le
bloc l'écrit et propose un bouton :

- **Réactiver la redirection** si le renvoi est inactif ;
- **Resynchroniser la redirection** s'il est actif mais sur un autre
  numéro.

Un seul clic suffit, sans confirmation : le site recalcule lui-même la
destination, change le renvoi, **relit l'état chez l'opérateur** et ne
dit « Redirection réactivée et vérifiée » (ou « resynchronisée ») qu'une
fois la vérification faite. Si cela échoue, un message rouge l'explique,
le bloc garde l'anomalie sous les yeux et vous pouvez réessayer. Les gardes
et les bascules déjà programmées ne bougent pas.

Si le renvoi est déjà conforme, il n'y a pas de bouton. S'il est illisible
chez l'opérateur, ou si aucun numéro valable n'est disponible (ni garde ni
numéro par défaut), la page le dit sans rien proposer : il faut d'abord
régler ce problème-là.

Avant l'heure de changement de garde, le renvoi attendu est encore celui de
la veille : c'est normal, la nouvelle garde commence à cette heure-là.
