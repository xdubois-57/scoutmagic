---
id: support
title: La page Support
summary: Les statistiques d'utilisation et le paquet de diagnostic.
category: Configuration
role_min: superadmin
question: Comment envoyer un diagnostic à celui qui nous dépanne ?
question: Où voir combien le site est utilisé ?
paths: /config/support
related: support-github, support-mesure, support-sondes-email, mises-a-jour, installation-serveur
---

La page Support réunit le rapport d'utilisation que votre site peut
envoyer à l'équipe qui développe le logiciel, et l'archive de
diagnostic que vous générez pour demander de l'aide.

## Les statistiques d'utilisation

Une fois par jour au plus, le site peut transmettre un rapport
technique agrégé : sa version, ses modules, des comptages de membres
et de sections, des informations d'hébergement. **Ce rapport n'est
pas anonyme** — il contient l'adresse de votre site, ce qui permet de
rattacher un rapport à l'unité qui demande de l'aide — mais il ne
contient aucune donnée de membre : ni nom, ni adresse, ni contenu.

Le bloc « Aperçu de ce qui est envoyé » montre le contenu exact du
rapport, même quand l'envoi est désactivé : vous décidez en sachant ce
qui part. « Envoyer un rapport de test maintenant » en transmet un
immédiatement et affiche la réponse — c'est le moyen de vérifier que la
chaîne fonctionne. « État des envois » garde la date du dernier envoi
réussi et du dernier échec, avec son motif.

## Signaler un problème

**Tout se signale sur GitHub**, bugs comme demandes : le bouton
« Signaler un problème sur GitHub » ouvre la page des signalements. Un
compte y est nécessaire, gratuit ; c'est lui qui vous permet de suivre
votre signalement et ses réponses, devant tout le monde.
N'hésitez jamais : une gêne minuscule chez vous est souvent le même
défaut chez trente autres unités.

## Envoyer des informations techniques

Pour un dysfonctionnement, le bloc « Envoyer des informations
techniques » transmet ce qu'on ne peut pas coller dans un dépôt public :
une catégorie, deux lignes de description, une adresse de contact.
S'y ajoutent l'identifiant de cette installation, la version du site et
la version de PHP. Envoyer **n'active pas** le rapport quotidien : s'il
est refusé, il le reste.

La page affiche ensuite la **référence** de l'envoi : recopiez-la dans
votre signalement GitHub. Les cinq derniers envois restent affichés,
repliés. Si le serveur est injoignable, rien n'est envoyé et **votre
texte reste à l'écran**.

## Le paquet de support

En cas de problème difficile à décrire, « Générer un paquet de
support » produit en arrière-plan une archive de diagnostic :
configuration du serveur, journaux, état du système de fichiers. Elle
est conservée chiffrée, réservée aux administrateurs du site, et
supprimée d'elle-même après sept jours ; en générer une nouvelle
remplace la précédente.

**Rien n'est jamais transmis automatiquement** : ni tâche planifiée, ni
courriel, ni envoi décidé par le site. Vous la transmettez vous-même :
en la téléchargeant, ou depuis « Envoyer des informations techniques »,
qui annonce sa taille et ses rubriques — « Voir le détail » les énumère —
et vous demande de cocher que vous acceptez. Un envoi qui échoue ne fait
pas perdre le reste : il reste marqué « archive non transmise », avec un
bouton pour réessayer.

> Avant de l'envoyer, ouvrez l'archive : les journaux peuvent contenir
> des adresses IP de visiteurs, et la configuration décrit votre
> hébergement. **Ne la déposez jamais sur un signalement GitHub** : un
> dépôt public est public.
