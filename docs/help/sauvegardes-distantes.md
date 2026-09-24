---
id: sauvegardes-distantes
title: Ce que le site envoie sur Drive
summary: Le rythme des envois, ce qui part, ce qui est conservé, et les alertes qui le surveillent.
category: Configuration
role_min: admin
discovery: off
question: À quel rythme le site envoie-t-il ses sauvegardes sur Drive ?
question: Qu'est-ce qui part, et qu'est-ce qui ne part pas ?
paths: /config/maintenance
related: phrase-de-passe-distante, sauvegarde-hors-site, restaurer-ailleurs, sauvegardes
---

Une fois la destination raccordée, vous n'avez plus rien à faire — sauf
une chose, et c'est la plus importante de cette page.

## Le rythme, et ce qui part

Le site envoie une sauvegarde **toutes les vingt-quatre heures**, tout
seul, par tranches : sur un hébergement mutualisé une requête dure trente
secondes et une sauvegarde pèse des gigaoctets, donc chaque passage en
envoie un morceau. Un envoi interrompu — serveur redémarré, connexion
coupée — reprend où il s'était arrêté plutôt que de tout recommencer.

**Ce rythme n'est pas celui du bloc « Sauvegarde automatique ».** Les
deux ne partagent aucun réglage : celui-là garde une archive non
chiffrée sur votre serveur, celui-ci construit la sienne, chiffrée, et
c'est elle qui part.

**Aucun emplacement de stockage n'est envoyé** — ni la galerie photo,
ni les autres. Ils se comptent en gigaoctets, et un envoi quotidien
remplirait un Drive gratuit en quelques semaines ; après quoi plus rien
ne partirait du tout. Leur contenu se protège autrement, et les sujets
liés ci-dessous expliquent comment.

Le site conserve chez vous **30 archives au maximum et 10 Go au plus** :
la plus contraignante des deux s'applique, et les plus anciennes sont
supprimées. Ces deux nombres se règlent dans Configuration > Réglages.

## La phrase de passe

Ce qui part est chiffré, avec une phrase que le site génère pour votre
unité. **Elle est la seule chose de cette page que vous ayez à faire** :
il faut la recopier hors du site, et le dire au site pour qu'il cesse de
le rappeler. Tout est dans
« La phrase de passe de vos sauvegardes distantes ».

## Si les envois s'arrêtent

Trois alertes le disent sans qu'on ait à y penser : l'une quand le
dernier envoi réussi date de plus de dix jours, l'autre quand le compte
distant approche de la saturation, la troisième tant que la phrase de
passe n'a été notée nulle part. Elles apparaissent comme les autres
points d'attention.
