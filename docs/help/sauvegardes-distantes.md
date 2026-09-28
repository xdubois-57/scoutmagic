---
id: sauvegardes-distantes
title: Ce que le site envoie sur Drive
summary: Le rythme des envois, ce qui part, ce qui est conservé, et les alertes qui le surveillent.
category: Configuration
role_min: superadmin
discovery: off
question: À quel rythme le site envoie-t-il ses sauvegardes sur Drive ?
question: Qu'est-ce qui part, et qu'est-ce qui ne part pas ?
question: Combien de temps les archives restent-elles sur Drive ?
paths: /config/maintenance/sauvegarde-automatique
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

**Ce rythme n'est pas celui de « Sur ce serveur ».** Le bloc
« Sauvegarde automatique » a deux moitiés qui ne partagent aucun
réglage : « Sur ce serveur » garde une archive sans les clés du site,
pour revenir en arrière après une fausse manœuvre ; « Hors site »
construit la sienne, portable et chiffrée avec la phrase de passe, pour
le jour où le serveur n'existe plus — et c'est elle qui part.

**Aucun emplacement de stockage n'est envoyé** — ni la galerie photo,
ni les autres. Ils se comptent en gigaoctets, et un envoi quotidien
remplirait un Drive gratuit en quelques semaines ; après quoi plus rien
ne partirait du tout. Leur contenu se protège autrement, et les sujets
liés ci-dessous expliquent comment.

## Ce qui est conservé

Après chaque envoi, le site éclaircit : il garde **la dernière archive,
puis une par semaine sur le mois écoulé, puis une par mois au-delà**.
Une année tient ainsi en moins d'une vingtaine d'archives. Par-dessus
s'appliquent **30 archives au maximum et 10 Go au plus** : la plus
contraignante des deux l'emporte, et les plus anciennes partent les
premières. Ces deux nombres se règlent dans Configuration > Réglages.

La page affiche ce qui est réellement là-bas, relevé à la fin de chaque
envoi : le nombre d'archives, leur volume et la date de la plus
ancienne. Une archive d'avant une régénération de la phrase est gardée
selon son âge, comme les autres — elle ne s'ouvre qu'avec l'ancienne
phrase.

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
