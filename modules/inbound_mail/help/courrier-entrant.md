---
id: courrier-entrant
title: Raccorder le courrier entrant
summary: Connecter les boîtes e-mail de l'unité, en lecture seule.
category: Configuration
role_min: superadmin
paths: /config/courrier-entrant, /config/courrier-entrant/boites/nouvelle, /config/courrier-entrant/boites/*/modification
related: courrier-portee, courrier-unite, gerer-les-locations
---

Le courrier entrant relie une ou plusieurs boîtes e-mail de l'unité au
site, pour que les réponses des correspondants — les locataires,
aujourd'hui — se rattachent toutes seules au bon dossier.

## Trois garanties à connaître

- **Rien n'est jamais modifié dans la boîte** : aucun message marqué
  lu, déplacé ou supprimé. Le trésorier qui travaille dans cette boîte
  ne verra aucune différence.
- **Tout le courrier relevé est conservé**, y compris ce qu'aucun
  module ne reconnaît. Ce n'était pas le cas avant, et c'est un
  changement voulu : un message ignoré était un message perdu, et
  personne ne s'en apercevait. Ce qu'aucun module ne rattache ni ne
  propose est supprimé automatiquement au bout du délai réglé plus bas,
  et seul le chef d'unité peut consulter ce courrier-là, depuis
  *Espace chefs d'U > Courrier*.
- **Les identifiants sont chiffrés et jamais réaffichés**, même
  partiellement.

## Ajouter une boîte

Renseignez un nom (c'est tout ce que verront les gestionnaires — ni le
serveur, ni le compte), le compte, le serveur IMAP, le port et le
chiffrement, puis le mot de passe. Pour Gmail ou Outlook, créez un
**mot de passe d'application** dans les réglages de sécurité du
compte : c'est la voie prévue, le mot de passe habituel ne
fonctionnera pas.

Après l'ajout, **testez la connexion** : le test annonce le nombre de
dossiers visibles, ou une explication en clair — certificat non
vérifié, authentification refusée, serveur muet. Un certificat
invalide bloque toujours : il n'existe pas d'option pour l'ignorer.

## Rafraîchir maintenant

Le bouton relève les boîtes tout de suite, sans attendre le prochain
passage automatique — pratique juste après avoir saisi un mot de passe.
Un seul rafraîchissement à la fois : un second clic pendant qu'un
premier tourne est refusé, sans quoi les deux se courraient après sur
la même boîte.

## Ce qui se passe ensuite

La relève tourne régulièrement en arrière-plan (un vrai cron chez
l'hébergeur la rend ponctuelle). Chaque message relevé est proposé aux
fonctions du site qui savent le reconnaître — une réponse de locataire
rejoint sa réservation d'après la référence du sujet, la conversation
ou l'expéditeur ; dans le doute, rien n'est rattaché plutôt que mal
rattaché.

La colonne « État » de la liste dit si chaque boîte fonctionne ;
« Désactiver » suspend une boîte sans la supprimer, et supprimer une
boîte conserve les messages déjà rattachés aux dossiers.

Reste à dire ce que chaque module a le droit d'en faire : voir
« Ce que chaque module fait d'une boîte ».
