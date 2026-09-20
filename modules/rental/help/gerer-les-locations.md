---
id: gerer-les-locations
title: Gérer les locations d'un bien
summary: Les réservations, les paiements, les documents et le séjour.
category: Espace membres
role_min: identified
question: Comment traiter une demande de location reçue ?
question: Comment envoyer le contrat de location au locataire ?
question: Comment savoir si un locataire a payé son acompte ?
paths: /mes-locations, /mes-locations/*, /mes-locations/*/calendrier, /mes-locations/*/gabarits, /mes-locations/*/reservations, /mes-locations/*/reservations/*, /mes-locations/*/reservations/*/sejour, /mes-locations/*/reservations/*/document/*
related: locations, locations-courrier, config-locations, courrier-entrant, locations-reglages, locations-conformite
---

« Mes locations » est l'espace des gestionnaires : les personnes
désignées sur chaque bien, plus le Staff d'Unité qui voit tout. La
page d'accueil rassemble ce qui attend une action, tous biens
confondus.

## Traiter une demande

Chaque réservation a sa fiche : le parcours du dossier, l'état, le prix,
les paiements, les documents et l'historique. Les jalons se cochent
d'après les faits — envoyer le contrat coche « Contrat envoyé » — et une
ligne grisée signale ce qui est sans objet ici, comme une caution non
demandée. Les boutons agissent sans recharger, et ceux qui écrivent au
locataire vous laissent ajouter un mot. Poser une **option** bloque les
dates jusqu'à une échéance : dépassée, la réservation expire et libère
les dates toute seule.

Le **lien de suivi** du locataire n'est affiché nulle part, pas même
pour vous : le posséder suffit pour ouvrir le dossier. Il ne se retrouve
donc pas, il se remplace — « Régénérer le lien de suivi » en envoie un
nouveau et désactive l'ancien sur-le-champ, qu'il ait été perdu ou
transmis à la mauvaise personne.

## Le prix et les paiements

L'estimation du visiteur reste figée ; le prix **convenu** se construit
sur la fiche, ligne par ligne — une ligne retouchée à la main n'est plus
recalculée. Avec Finances, l'acompte et le solde se pointent grâce à la
communication structurée ; la caution a la sienne et sa restitution se
note à la main. Le décompte final ajoute ses lignes sans réécrire le
prix convenu.

## Contrat et facture

Le texte se rédige dans les **gabarits du bien** ; la boîte Documents y
renvoie. Générer en produit un PDF, chaque génération créant une version
de plus sans écraser la précédente — une version signée reste intacte.

**« Envoyer » verrouille** : ensuite le texte passe en lecture seule, le
locataire en ayant une copie qui ne doit pas changer dans son dos. La
confirmation vous le dit avant. Il ne télécharge jamais rien du site.

Les actions sont des icônes, leur infobulle nomme le document.
« Ouvrir » s'ouvre dans un nouvel onglet — sans quoi le PDF remplace
l'écran de l'application installée sans moyen d'en revenir. Hors ligne,
un document ne se consulte pas : le site le dit.

## Le courrier reçu

Les e-mails que le site rattache à la réservation, les propositions à
confirmer et ce que « Détacher » fait exactement sont décrits dans
« Le courrier d'une réservation ».

## Le séjour

La page Séjour consigne les relevés de compteurs (photo à l'appui),
l'état des lieux figé à la confirmation, et les incidents — rien ne se
facture sans qu'un gestionnaire tranche : ajouter au décompte, retenir
sur la caution, ou ne pas facturer. Cette page ne fonctionne qu'en
ligne : sur place sans réseau, photographiez, saisissez au retour.

Les réglages du bien — règles de réservation, tarifs, acompte,
gabarits, échéances de conformité — vivent dans les onglets du bien,
et ne changent jamais une réservation déjà existante.
