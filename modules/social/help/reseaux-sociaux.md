---
id: reseaux-sociaux
title: Relier la Page Facebook et le compte Instagram
summary: Créer l'application Meta de l'unité, connecter ses comptes et garder la connexion en vie.
category: Configuration
role_min: superadmin
discovery: 1
question: Comment relier la Page Facebook de l'unité au site ?
question: Comment connecter le compte Instagram de l'unité ?
question: Pourquoi Meta refuse-t-il la connexion du site ?
paths: /config/reseaux-sociaux
related: config-rgpd, connecteur-ia
---

Ce module relie le site à **la Page Facebook** et au **compte Instagram
professionnel** de l'unité, pour pouvoir y publier depuis le site. Il ne
publie rien de lui-même : chaque publication est décidée par un
administrateur.

> Un profil Facebook personnel et un groupe Facebook ne sont pas des
> destinations possibles : Meta ne permet plus à une application de
> publier dans un groupe depuis avril 2024. Un compte Instagram personnel
> non plus — passez-le en **compte professionnel** (gratuit) dans
> l'application Instagram.

## Créer l'application Meta de l'unité

Chaque unité crée **sa propre** application sur
developers.facebook.com, avec un compte qui administre la Page. Tant que
seuls vos propres comptes s'y connectent, **aucune revue par Meta n'est
nécessaire**.

1. Créez l'application, puis ajoutez-y le produit **Facebook Login** (pour
   la Page) et/ou **Instagram**, avec sa connexion par Instagram (pour
   le compte Instagram).
2. Dans chacun, déclarez l'**adresse de redirection** que la page
   Réseaux sociaux affiche sur la carte correspondante, **au caractère
   près**. Elle se compose à partir de l'adresse du site : renseignez
   celle-ci d'abord dans Configuration > Réglages si la page ne l'affiche
   pas.
3. Recopiez l'**identifiant** et la **clé secrète** de l'application sur
   la carte, puis « Enregistrer ». La clé est conservée chiffrée et n'est
   jamais réaffichée ; laisser le champ vide plus tard conserve la clé
   actuelle.

## Connecter

« Connecter » vous emmène chez Meta, qui vous demande d'accorder
l'autorisation, puis vous ramène ici. Pour Facebook, cochez la Page de
l'unité ; si votre compte en gère plusieurs, le site vous demande
ensuite laquelle.

- **Facebook** : l'autorisation de la Page n'expire pas. Elle cesse de
  fonctionner si la personne qui l'a donnée perd ses droits sur la Page
  ou change son mot de passe.
- **Instagram** : l'autorisation vaut soixante jours. Le site la
  **renouvelle tout seul** chaque semaine ; la carte indique la date du
  dernier renouvellement et jusqu'à quand elle vaut.

## Garder la connexion en vie

Chaque nuit, le site vérifie les deux connexions. « **Tester la
connexion** » fait la même vérification tout de suite. Si Meta n'accepte
plus l'autorisation, la carte l'affiche et le journal le note une fois ;
« **Reconnecter** » refait le passage chez Meta et répare la connexion.

« Déconnecter » efface le compte et la clé secrète de l'application. Ce
qui a déjà été publié reste sur Facebook et Instagram.

Meta reçoit ce que l'unité publie : c'est un destinataire au sens du
RGPD. Tant qu'un compte est raccordé, la page Protection des données
générée par IA le mentionne ; vérifiez-la après la première connexion.
