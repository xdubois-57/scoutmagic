---
id: sauvegarde-hors-site
title: Déposer les sauvegardes sur Google Drive
summary: Créer le projet Google de l'unité, raccorder le compte, et l'étape que tout le monde oublie.
category: Configuration
role_min: admin
discovery: off
question: Comment envoyer mes sauvegardes sur Google Drive ?
question: Pourquoi mon raccordement Google Drive s'arrête-t-il au bout d'une semaine ?
paths: /config/stockage/emplacements, /config/maintenance
related: sauvegardes-distantes, sauvegarde-portable, sauvegardes, restaurer-ailleurs
---

Une sauvegarde posée sur le serveur qui héberge le site ne protège de
rien le jour où c'est le serveur qui disparaît. Voici comment lui donner
un endroit où déposer ses sauvegardes, dans **votre** Drive.

Google exige que l'application qui écrit dans un Drive ait ses propres
identifiants, impossibles à partager entre unités : un secret vivrait
dans un dépôt public. Vous créez donc un petit projet Google gratuit,
qui n'appartient qu'à vous.

## Dans la console Google

1. Ouvrez **console.cloud.google.com** et créez un projet — son nom
   importe peu.
2. Dans **API et services > Bibliothèque**, activez **Google Drive API**.
3. Dans **Écran de consentement OAuth**, type **Externe**, un nom
   d'application et votre adresse, puis la portée
   **`.../auth/drive.file`** — celle-là et aucune autre.
4. Dans **Identifiants**, créez un **ID client OAuth** de type
   **Application Web**. Dans **URI de redirection autorisés**, collez
   l'adresse que la fiche de l'emplacement affiche, **au caractère
   près**.
5. Notez l'identifiant et le secret.

## L'étape que tout le monde oublie

Revenez sur l'écran de consentement et **publiez l'application**.

Tant qu'elle reste en statut **Test**, Google fait expirer l'autorisation
au bout de **sept jours**, sans rien dire — et vous le découvririez le
jour où vous en auriez besoin. C'est la première cause de panne
silencieuse de ce genre de raccordement.

Publier ne déclenche **aucun examen de sécurité** tant que l'application
se limite aux fichiers qu'elle a créés, ce que fait ce site. Reste la
vérification d'identité, facultative : sans elle, un avertissement
**application non vérifiée** s'affiche. Vous en êtes l'auteur et le seul
utilisateur : passez outre.

## Sur le site

Un dossier Drive est un **emplacement de stockage** comme un autre, et
se déclare au même endroit que le disque du serveur.

Page **Configuration > Stockage > Emplacements**, **Ajouter un
emplacement**, type **Google Drive** : un nom, l'identifiant, le secret,
enregistrez. Sur sa fiche, **Raccorder un compte Google Drive** ; Google
demande votre accord, et vous revenez raccordé.

Cliquez enfin sur **Tester** : le site y écrit un fichier témoin puis le
supprime. C'est la seule preuve qui vaille — un compte qui répond n'est
pas un compte qui accepte d'être écrit.

Reste à dire au site que c'est **là** que partent les sauvegardes :
page **Configuration > Maintenance**, bloc **Sauvegarde hors site**,
choisissez l'emplacement. Sans cela rien ne quitte le serveur — et la
page le dit.

## Ce que Google voit, et ne voit pas

L'autorisation `drive.file` ne donne accès qu'aux fichiers créés par ce
site : le reste de votre Drive lui est invisible. Ce qui y est déposé est
une **sauvegarde portable** chiffrée — Google détient un fichier dont il
n'a pas la clé.

**Déraccorder** efface le jeton et les identifiants ; les sauvegardes
déjà déposées sont à vous seul de supprimer. La fiche de l'emplacement
reste, et la supprimer est refusé tant que les sauvegardes s'appuient
dessus.

## Et ensuite ?

Le site envoie tout seul. Le rythme, ce qu'il conserve et — surtout —
**la phrase de passe à recopier hors du site** :
voir [Ce que le site envoie sur Drive](/aide/sauvegardes-distantes).
