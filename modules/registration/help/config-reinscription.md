---
id: config-reinscription
title: Piloter la campagne de réinscription
summary: Le tableau de bord de la campagne, ses réglages, et ce que chaque enregistrement envoie.
category: Espace chefs d'U
role_min: admin
question: Comment ouvrir la campagne de réinscription aux familles ?
question: Comment relancer les familles qui n'ont pas répondu ?
paths: /config/reinscription, /config/reinscription/reglages
related: config-reinscription-emails, reinscription, departs, passage
---

Chaque année, l'unité demande aux familles si leur enfant revient. La page
a deux onglets : **Tableau de bord**, pour voir où en est la campagne et
relancer, et **Réglages**, pour décider quand la question est posée.

## Une seule campagne : celle de l'année visée

La campagne est toujours celle de **l'année scoute qui vient** : en
octobre 2026, c'est la campagne pour 2027-2028, qui se fermera en mai
2027. Elle le reste avant ses dates, pendant et après, jusqu'au
changement d'année de l'unité : ce jour-là, la campagne de l'année
suivante prend la place, et une campagne encore ouverte se termine sans
e-mail de clôture.

C'est la dernière période qui s'ouvre avant le 1er septembre de l'année
visée : une ouverture au printemps (`03-01`) ou à l'automne (`10-01`,
fermeture `12-15`) donne la campagne pour l'année qui suit.

## Le tableau de bord

« État » donne quatre **chiffres, jamais des noms** : réponses reçues,
sans réponse, départs annoncés, année visée. Les décisions individuelles
se lisent sur « Départs » et sur « Passage ».

Entre deux campagnes, une ligne grise rappelle comment la précédente s'est
terminée, à la date où elle s'est réellement fermée. Sans campagne passée,
elle n'apparaît pas.

« Relancer maintenant » nomme la campagne et ses dates, puis dit pour
chaque e-mail ce qui est **parti** (et quand) ou ce qui est **prévu** (et
pour quand) — ou que sa date est passée, qu'il est sauté parce qu'il
tomberait avant l'ouverture, ou que les e-mails sont désactivés.

Le bouton écrit, dans les minutes qui suivent, aux familles qui n'ont pas
répondu **pour tous** leurs enfants, un e-mail par adresse. Il est
indisponible campagne fermée et quand les e-mails sont désactivés.
Si plus aucune famille n'a de réponse à donner, la question dit « Aucune
famille ne recevra de relance. »

## Les réglages

L'ouverture et la fermeture s'écrivent en **mois-jour** (`03-01`,
`05-15`) : la même configuration se rejoue chaque année. **Une date
manquée est manquée** : la campagne ne s'ouvre pas en retard.

Les deux rappels se comptent **en jours avant la fermeture**. Un rappel
qui tomberait avant l'ouverture est sauté.

L'interrupteur « Campagne ouverte » ouvre ou ferme la campagne de l'année
visée. L'ouvrir l'ouvre **tout de suite** ; sa fermeture, ses rappels et
sa clôture restent ceux des réglages, même dans sept mois.

## Chaque enregistrement se confirme

Avant tout enregistrement qui change quelque chose, une confirmation dit
ce qui change et répond à une question : **un e-mail va-t-il partir ?**
Si oui, combien, lesquels et à qui, et le bouton devient « Enregistrer et
envoyer ». Sinon, « Aucun e-mail ne partira », avec la raison. Si la
situation change entre la question et votre réponse, rien n'est
enregistré et la nouvelle confirmation s'affiche.

Après l'enregistrement, un message dit ce qui est parti et ce qui ne
l'est pas. Le détail des e-mails est dans *Les e-mails de la campagne de
réinscription*.
