---
id: locations-courrier
title: Le courrier des locations
summary: La page Courrier d'une réservation — les e-mails reçus pour cette réservation, et « Détacher ».
category: Espace membres
role_min: identified
discovery: 3
question: Où voir les e-mails d'un locataire sur sa réservation ?
question: Pourquoi un e-mail du locataire n'apparaît-il pas sur sa réservation ?
question: Que devient un message quand je le détache d'une réservation ?
paths: /mes-locations/*/reservations/*, /mes-locations/*/reservations/*/courrier
related: gerer-les-locations, courrier-entrant, courrier-reponse, courrier-unite
---

Chaque réservation a une page « Courrier ». Elle montre les e-mails
rattachés à **cette** réservation, du plus récent au plus ancien, et
rien d'autre. Chaque message est marqué « Reçu » ; « Lire le message »
l'ouvre en entier, avec ses pièces jointes.

Les e-mails arrivent depuis les boîtes de l'unité ouvertes aux Locations.
Si aucune ne l'est, la page le dit : un superadministrateur peut en
ouvrir une depuis *Configuration > Courrier entrant*, sous « Portée ».
Un e-mail écrit depuis une adresse personnelle n'apparaît pas ici.

## Ce que le site reconnaît tout seul

Une réponse à un e-mail du site est reconnue grâce à l'adresse de
réponse signée qu'il porte. Un message du locataire qui cite la
référence de sa réservation y est rattaché. Un locataire qui n'a qu'une
réservation chez l'unité voit tous ses messages y arriver ; s'il en a
plusieurs, le message doit tomber dans la période de l'une d'elles.

Quand le site hésite entre plusieurs réservations, il ne rattache
rien : le message n'apparaît sur aucune, et vous n'avez rien à trier.

## Être prévenu

Un message rattaché à une réservation vous est signalé par la
notification « Nouveau message du locataire ». L'onglet Courrier affiche
alors entre parenthèses le nombre de messages à lire, et la vue d'ensemble
du bien liste la réservation sous « Nouveaux messages ». Ce compte est le vôtre : il retombe quand vous
ouvrez la page Courrier, pas quand un autre gestionnaire l'ouvre.

## Détacher

« Détacher » répond à « Ce message ne concerne pas cette réservation ? ».
Le message quitte la page, avec ses pièces jointes encore « Non classé »,
et le site ne le rattachera plus jamais tout seul à cette réservation.
Il peut encore l'être à une autre. Un document déjà reclassé, un contrat
signé par exemple, reste sur la réservation.
