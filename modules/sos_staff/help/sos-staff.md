---
id: sos-staff
title: Organiser les gardes SOS
summary: Le planning de garde, l'état réel du renvoi et sa correction en un clic.
category: Espace chefs d'U
role_min: admin
question: Qui est de garde ce week-end ?
question: Comment remplir la grille de garde du mois ?
question: Le renvoi du numéro SOS ne va pas chez la bonne personne, que faire ?
paths: /admin/sos, /admin/sos/planning, /admin/sos/reglages, /admin/sos/transitions
related: config-sos
---

Le numéro SOS de l'unité sonne toujours chez quelqu'un : la page
« Gérer le téléphone d'urgence » décide chez qui, jour par jour. Le renvoi
d'appel se programme tout seul d'après les gardes attribuées. La page a
trois onglets, toujours dans cet ordre : **Tableau de bord**, **Planning**
et **Réglages**.

## Tableau de bord : où vont les appels, là, maintenant

Le premier bloc, « Redirection actuelle », affiche l'état **réel** du
renvoi, lu chez l'opérateur — pas ce que le site croit avoir fait : actif
ou non, et vers quel numéro. En dessous, « Redirections planifiées » liste
les bascules à venir, la prochaine en évidence.

### Remettre le renvoi d'aplomb

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

## Planning : qui est de garde

### Sur ordinateur : la grille

La grille du mois croise les jours et les membres du Staff d'Unité.
Cliquez une case pour la faire tourner entre trois états : vide, **de
garde** (coche verte), **indisponible** (croix rouge). Tout
s'enregistre aussitôt — le statut « Enregistré. » le confirme. Les
colonnes orange, à gauche, montrent les activités des sections : de
quoi éviter de donner la garde à quelqu'un déjà en week-end.

### Sur téléphone : la liste des jours

Le mois se lit comme une liste : une ligne par jour, avec le nom de la
personne qui reçoit réellement les appels. Un jour sans garde affiche
« Par défaut — » suivi du nom concerné. Si plusieurs personnes sont
marquées de garde le même jour, la ligne le signale : seule la première de
la liste reçoit les appels. Le mois en cours commence à aujourd'hui ; le
lien « Aller à aujourd'hui » y ramène.

Touchez une ligne : la journée s'ouvre **en plein écran**. La date est en
haut, avec un bouton **Fermer** pour revenir au planning ; en dessous,
chaque membre du Staff d'Unité a ses trois boutons **Garde**, **Indispo** et
**Rien**, et la liste défile si elle est longue. Les activités des sections
de la journée sont rappelées en tête.

Vous planifiez toujours les gardes de tout le monde depuis cet onglet.
(L'ancien onglet « Ma disponibilité » n'existe plus.)

## Réglages

Trois réglages, qui s'enregistrent d'eux-mêmes sauf le premier :

- le **numéro par défaut**, utilisé les jours sans garde attribuée — seuls
  les membres dont un mobile est connu sont proposés. Le bouton
  « Enregistrer » ne s'active que si vous changez réellement la valeur. Si
  la journée en cours n'a pas de garde, un avertissement vous prévient que
  l'enregistrement redirige les appels immédiatement ;
- l'**heure de changement de garde** ;
- les **notifications de garde** : à chaque changement de destinataire, la
  personne qui prend la garde et celle qui la termine sont prévenues ;
  chacune choisit ses canaux dans ses préférences de notifications. Ce
  réglage est l'interrupteur global.

> Les bascules s'exécutent en arrière-plan : sans tâche planifiée réelle
> chez l'hébergeur, elles peuvent partir en retard. Si l'état affiché ne
> correspond pas aux gardes, c'est le premier point à vérifier — et le
> bouton du Tableau de bord remet le renvoi d'aplomb en attendant.

## Les gardes sur le calendrier

Si le module Calendrier est actif, les périodes de garde y apparaissent,
**calculées en direct** : elles suivent chaque enregistrement et ne se
modifient pas depuis le calendrier. D'anciens évènements
« SOS Staff d'U : … » écrits avant ce calcul peuvent subsister en
double ; supprimez-les une fois, ils ne reviendront pas.

Le raccordement à l'opérateur se fait sur la page de configuration du
module, réservée à l'administration du site.
