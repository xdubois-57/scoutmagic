---
id: sauvegardes-distantes
title: Ce que le site envoie sur Drive, et la phrase qui l'ouvre
summary: Le rythme des envois, ce qui part, ce qui est conservé, et la phrase de passe à recopier ailleurs.
category: Configuration
role_min: admin
discovery: off
question: Comment ouvrir une sauvegarde récupérée sur Google Drive ?
question: Où est la phrase de passe de mes sauvegardes distantes ?
paths: /config/maintenance
related: sauvegarde-hors-site, restaurer-ailleurs, sauvegardes
---

Une fois la destination raccordée, vous n'avez plus rien à faire — sauf
une chose, et c'est la plus importante de cette page.

## Le rythme, et ce qui part

Le site envoie une sauvegarde **toutes les vingt-quatre heures**, tout
seul, par tranches : sur un hébergement mutualisé une requête dure trente
secondes et une sauvegarde pèse des gigaoctets, donc chaque passage en
envoie un morceau. Un envoi interrompu — serveur redémarré, connexion
coupée — reprend où il s'était arrêté plutôt que de tout recommencer.

La **galerie photo n'est pas envoyée**. Elle se compte en gigaoctets, et
un envoi quotidien remplirait un Drive gratuit en quelques semaines ;
après quoi plus rien ne partirait du tout. Si vous avez la place, un
réglage permet de l'inclure.

Le site conserve chez vous **30 archives au maximum et 10 Go au plus** :
la plus contraignante des deux s'applique, et les plus anciennes sont
supprimées. Ces deux nombres se règlent dans Configuration > Réglages.

## La phrase de passe : recopiez-la ailleurs

Ce qui part est chiffré. La phrase qui l'ouvre est **générée par le
site** — trente caractères, une fois pour votre unité — et s'affiche sur
la page Maintenance, bouton **Afficher la phrase de passe**.

**Recopiez-la hors du site.** Un carnet, un gestionnaire de mots de
passe, une enveloppe chez le trésorier : n'importe où sauf sur ce
serveur. Le jour où vous en aurez besoin, ce site ne sera probablement
plus accessible — c'est exactement la situation pour laquelle ces
sauvegardes existent. Sans la phrase, les archives de votre Drive ne
s'ouvrent pas, et personne ne peut rien y faire.

Elle reste consultable ici tant que le site fonctionne, et c'est
volontaire : elle doit rester sur le serveur pour que l'envoi de quatre
heures du matin chiffre sans que personne tape quoi que ce soit. La
cacher ne protégerait donc rien.

## Régénérer : ce que cela coûte

**La nouvelle phrase n'ouvre pas ce qui est déjà parti.** Rien ne
rechiffre les archives déjà chez Google : seule l'ancienne phrase les
ouvre encore, et ce site ne la conserve plus une fois régénérée.

C'est pourquoi le numéro de génération figure dans le nom de chaque
fichier envoyé : devant `scoutmagic-2026-09-13-020400-g2.zip`, vous savez
qu'il s'ouvre avec la deuxième phrase et non la première. Ne régénérez
que si vous avez une raison — une phrase qui a traîné dans un e-mail, par
exemple — et notez l'ancienne avant, ou supprimez les archives qu'elle
seule ouvrait.

## Si les envois s'arrêtent

Deux alertes le disent sans qu'on ait à y penser : l'une quand le dernier
envoi réussi date de plus de dix jours, l'autre quand le compte distant
approche de la saturation. Elles apparaissent comme les autres points
d'attention.
