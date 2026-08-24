---
id: courrier-entrant
title: Raccorder le courrier entrant
summary: Connecter les boîtes e-mail de l'unité, en lecture seule.
category: Configuration
role_min: superadmin
paths: /config/courrier-entrant
related: gerer-les-locations
---

Le courrier entrant relie une ou plusieurs boîtes e-mail de l'unité au
site, pour que les réponses des correspondants — les locataires,
aujourd'hui — se rattachent toutes seules au bon dossier.

## Trois garanties à connaître

- **Rien n'est jamais modifié dans la boîte** : aucun message marqué
  lu, déplacé ou supprimé. Le trésorier qui travaille dans cette boîte
  ne verra aucune différence.
- **Un message que le site ne reconnaît pas est ignoré** : ni
  enregistré, ni listé. Le site n'archive pas votre boîte.
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
