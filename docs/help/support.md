---
id: support
title: La page Support
summary: Les statistiques d'utilisation et le paquet de diagnostic.
category: Configuration
role_min: superadmin
paths: /config/support
related: mises-a-jour, installation-serveur
---

La page Support regroupe deux choses distinctes : le rapport
d'utilisation que votre site peut envoyer à l'équipe qui développe le
logiciel, et l'archive de diagnostic que vous pouvez générer pour
demander de l'aide.

## Les statistiques d'utilisation

Une fois par jour au plus, le site peut transmettre un rapport
technique agrégé : sa version, ses modules, des comptages de membres
et de sections, des informations d'hébergement. **Ce rapport n'est
pas anonyme** — il contient l'adresse de votre site, ce qui permet de
rattacher un rapport à l'unité qui demande de l'aide — mais il ne
contient aucune donnée de membre : ni nom, ni adresse, ni contenu.

Le bloc « Aperçu de ce qui est envoyé » montre le contenu exact du
rapport, même quand l'envoi est désactivé : vous décidez en sachant
précisément ce qui part. L'interrupteur coupe l'envoi à tout moment,
sans toucher au reste de la page. « Envoyer un rapport de test
maintenant » transmet un rapport immédiatement et affiche la réponse
reçue — c'est le moyen de vérifier que la chaîne fonctionne. Le bloc
« État des envois » garde la date du dernier envoi réussi et du
dernier échec, avec son motif.

## Le paquet de support

En cas de problème difficile à décrire, « Générer un paquet de
support » produit en arrière-plan une archive de diagnostic :
configuration du serveur, journaux, état du système de fichiers. Elle
est conservée chiffrée, réservée aux administrateurs du site, et
supprimée d'elle-même après sept jours ; en générer une nouvelle
remplace la précédente.

**Rien n'est jamais transmis automatiquement** : c'est vous qui
téléchargez l'archive et l'envoyez, ou non, à l'adresse de support
affichée.

> Avant d'envoyer l'archive, ouvrez-la et vérifiez son contenu : les
> journaux du serveur peuvent contenir des adresses IP de visiteurs,
> et la configuration détaillée décrit votre hébergement.
