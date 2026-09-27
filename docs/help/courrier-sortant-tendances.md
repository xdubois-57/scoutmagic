---
id: courrier-sortant-tendances
title: Lire les courbes semaine après semaine
summary: Ce que disent les deux graphiques du courrier sortant, pourquoi une courbe est coupée, et pourquoi un fournisseur n'y figure pas.
category: Configuration
role_min: superadmin
question: Est-ce que ma délivrabilité se dégrade ?
question: Pourquoi ma courbe est-elle coupée en deux ?
question: Pourquoi un fournisseur n'apparaît pas sur le graphique ?
question: Depuis quand ce fournisseur met-il mes envois de côté ?
paths: /config/courrier-sortant/dmarc, /config/courrier-sortant/temoins
related: courrier-sortant-dmarc, courrier-sortant-temoins, courrier-sortant, courrier-sortant-pannes
---

## Deux chiffres ne font pas une tendance

Les tableaux de ces deux pages disent où vous en êtes aujourd'hui.
Ils ne répondent pas à la question qui décide s'il faut agir :
**est-ce que c'était déjà comme ça le mois dernier ?**

Un taux d'authentification de 92 % est excellent s'il remonte de 70 %, et
inquiétant s'il descend de 100 %. Un fournisseur qui met un envoi sur
deux de côté vous filtre peut-être depuis toujours — ou depuis la semaine
où vous avez changé quelque chose.

C'est à ça que servent les deux cartes « Est-ce que ça se dégrade ? » sur
la page DMARC et « Depuis quand ? » sur la page des boîtes témoins.

## Une semaine est une semaine du calendrier

Un point, c'est la semaine du 14, du lundi au dimanche — pas les sept
derniers jours, qui ne se nomment pas. Le dernier
point est donc **la semaine en cours, qui n'est pas finie** : il bougera
encore. Le graphique le dit quand vous posez le curseur dessus, et
c'est la seule raison pour laquelle un dernier point plus bas que les
autres ne veut rien dire du tout.

## Une courbe coupée ne dit pas « zéro »

Là où la courbe s'interrompt, **nous n'avons pas assez de matière pour
avancer un chiffre** — et le trou le dit franchement plutôt que de
dessiner un point à zéro, qui se lirait **rien n'est passé cette
semaine**. Ce sont deux affirmations très différentes, et une seule des
deux serait honnête.

Il faut, par semaine :

- vingt messages rapportés pour tracer un taux d'authentification ;
- cinq publipostages mesurés pour tracer un fournisseur.

Une semaine creuse, des vacances, une installation récente : autant de
trous normaux. La courbe ne les relie pas, parce qu'une pente au-dessus
d'un trou serait une mesure que personne n'a prise.

## Un fournisseur absent du graphique

Un fournisseur qui n'a jamais atteint cinq publipostages sur une même
semaine **n'est pas dessiné du tout**, plutôt que dessiné en ligne
vide — une légende sans ligne se lit **il n'a rien délivré**, l'inverse
de **nous ne l'avons pas assez mesuré**. Il reste dans le tableau
au-dessus, qui lui ne prétend pas faire une tendance.

## Les chiffres en texte

Sous chaque graphique, « Les mêmes semaines en chiffres » déplie le
tableau des mêmes points : les semaines sans chiffre n'y sont pas non
plus.

Les courbes remontent aussi loin que les données sont gardées, quatre-
vingt-dix jours, même quand le tableau du dessus n'en montre que trente.
