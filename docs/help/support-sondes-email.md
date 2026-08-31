---
id: support-sondes-email
title: Tester l'acheminement des e-mails
summary: Vérifier que vos messages arrivent vraiment, et dans quel état.
category: Configuration
role_min: superadmin
paths: /config/support
related: support, config-emails
---

Le bouton « Envoyer un e-mail de test » de la page Courriel prouve une
seule chose : votre serveur a **accepté** le message. C'est déjà utile,
et c'est loin d'être toute l'histoire — un message accepté peut être
rejeté plus loin, mis en quarantaine une heure, ou supprimé sans
avertissement par le fournisseur du destinataire.

La sonde répond à la suite. Elle envoie un message vers une boîte de
l'équipe de support, qui vous dira ce qui est arrivé.

Elle **part avec votre ticket** : il n'y a pas de bouton séparé. En
cochant « j'accepte que le site envoie un e-mail de test », vous
l'autorisez, et elle est envoyée en même temps que le ticket.

## Ce que la sonde contient

Une clé de corrélation, et rien d'autre. Aucun nom, aucune adresse de
membre, aucune donnée personnelle. C'est un message technique que
personne ne lit.

## Ce que le support en tire

- **La réception**, ou son absence : « jamais reçue » est une réponse en
  soi, et souvent la plus parlante.
- **Le délai** entre l'envoi et l'arrivée. Quarante minutes, c'est une
  mise en quarantaine côté destinataire ; deux secondes, c'est une chaîne
  qui fonctionne.
- **SPF, DKIM, DMARC** tels que le serveur destinataire les a inscrits.
  Un « non renseigné » n'est pas un échec : c'est un serveur qui n'a rien
  écrit, et cela ne se corrige pas de la même façon.
- **La chaîne des relais**, quand un message a fait un détour
  inattendu.

## Une sonde par heure

Un envoi qui écrit vers plusieurs boîtes est un amplificateur : une seule
sonde par heure part de votre site. Si vous ouvrez deux tickets dans la
même heure, le second n'en envoie pas — la confirmation vous le dit, et
le ticket part quand même.

## Si l'envoi échoue

Un refus de **votre** serveur de messagerie est déjà un diagnostic : la
configuration Courriel est à revoir avant tout le reste. Un envoi parti
vers deux boîtes sur trois en est un autre — c'est une destination
précise qui pose problème, pas votre serveur.

## Où se lit le résultat

Chez le support, pas ici : c'est lui qui reçoit le message. La clé
s'affiche dans la confirmation et sous le dernier ticket envoyé ; elle
suffit à retrouver ce qui est arrivé.

**« En attente » n'est pas « jamais reçue ».** Tant que la clé n'a pas
expiré — 48 heures — le message peut encore arriver : une mise en
quarantaine de quarante minutes est précisément ce que la sonde sert à
révéler. Ce n'est qu'après l'expiration que l'absence devient un
constat.
