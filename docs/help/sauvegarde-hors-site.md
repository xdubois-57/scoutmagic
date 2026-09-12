---
id: sauvegarde-hors-site
title: Déposer les sauvegardes sur Google Drive
summary: Créer le projet Google de l'unité, raccorder le compte, et l'étape que tout le monde oublie.
category: Configuration
role_min: admin
discovery: off
question: Comment envoyer mes sauvegardes sur Google Drive ?
question: Pourquoi mon raccordement Google Drive s'arrête-t-il au bout d'une semaine ?
paths: /config/maintenance
related: sauvegarde-portable, sauvegardes, restaurer-ailleurs
---

Une sauvegarde posée sur le serveur qui héberge le site ne protège de
rien le jour où c'est le serveur qui disparaît. Voici comment donner au
site un endroit où déposer ses sauvegardes, dans **votre** Drive.

## Ce que vous créez, et pourquoi vous

Google exige que l'application qui écrit dans un Drive ait ses propres
identifiants, et ceux-là ne peuvent pas être partagés entre toutes les
unités : un secret vivrait dans un dépôt public. Vous créez donc un petit
projet Google gratuit, qui n'appartient qu'à vous.

## Dans la console Google

1. Ouvrez **console.cloud.google.com** et créez un projet — son nom n'a
   aucune importance.
2. Dans **API et services > Bibliothèque**, activez **Google Drive API**.
3. Dans **Écran de consentement OAuth**, choisissez le type **Externe**,
   renseignez un nom d'application et votre adresse, et ajoutez la portée
   **`.../auth/drive.file`** — celle-là et aucune autre.
4. Dans **Identifiants**, créez un **ID client OAuth** de type
   **Application Web**. Dans **URI de redirection autorisés**, collez
   l'adresse que la page Maintenance affiche, **au caractère près**.
5. Notez l'identifiant et le secret qui s'affichent.

## L'étape que tout le monde oublie

Revenez sur l'écran de consentement et **publiez l'application**.

Tant qu'elle reste en statut **Test**, Google fait expirer l'autorisation
au bout de **sept jours**. Les sauvegardes s'arrêteraient sans rien
dire, et vous le découvririez le jour où vous en auriez besoin. C'est la
première cause de panne silencieuse de ce genre de raccordement.

Publier ne déclenche **aucun examen de sécurité** de Google tant que
l'application se limite aux fichiers qu'elle a créés — ce que fait ce
site. C'est l'examen coûteux, réservé aux accès larges, et vous y
échappez. Reste la vérification d'identité, celle qui fait apparaître
votre nom et votre logo sur l'écran d'accord : vous pouvez très bien
vous en passer. Un avertissement **application non vérifiée**
s'affichera alors au raccordement — vous en êtes l'auteur, et vous êtes
le seul utilisateur, donc passez outre.

## Sur le site

Page **Configuration > Maintenance**, bloc **Sauvegarde hors site** :
collez l'identifiant et le secret, enregistrez, puis **Raccorder un
compte Google Drive**. Google vous demande votre accord ; vous revenez
sur la page, raccordée.

Cliquez enfin sur **Tester** : le site écrit un fichier témoin puis le
supprime. C'est la seule preuve qui vaille — un compte qui répond n'est
pas un compte qui accepte d'être écrit.

## Ce que Google voit, et ne voit pas

L'autorisation `drive.file` ne donne accès qu'aux fichiers créés par ce
site : le reste de votre Drive lui reste invisible. Ce qui y sera déposé
est une **sauvegarde portable**, chiffrée par une phrase de passe que
vous seul connaissez — illisible pour Google.

**Déraccorder** efface le jeton et les identifiants. Les sauvegardes déjà
déposées restent chez vous.
