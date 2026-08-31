---
id: connecteur-ia
title: Activer l'intelligence artificielle
summary: Choisir un fournisseur d'IA, sa clé, et ce que le site en fait.
category: Configuration
role_min: superadmin
question: Comment brancher une intelligence artificielle sur le site ?
question: Combien coûte l'IA branchée sur le site ?
question: Que fait le site avec l'IA une fois branchée ?
paths: /config/llm
related: config-rgpd, recus, retrospectives
---

Le connecteur IA donne au site un assistant pour des tâches précises :
lire une facture, suggérer une catégorie comptable, résumer une
rétrospective, générer le texte RGPD. **L'IA propose, elle ne décide
jamais** : chaque suggestion attend une validation humaine, et sans ce
module, ces fonctions se taisent simplement — rien ne casse.

## Choisir un fournisseur

La page propose plusieurs fournisseurs, avec pour chacun la
localisation de ses serveurs et ses liens de confidentialité —
certains hébergent en Union européenne, d'autres non. Lisez-les avant
de choisir : les textes envoyés à l'IA quittent le serveur de l'unité.

> Un fournisseur d'IA est un sous-traitant au sens du RGPD. Après
> l'activation, vérifiez la page Protection des données du site — le
> mode « Généré par IA » de sa configuration sait tenir compte du
> fournisseur choisi.

## Activer

Suivez le pas-à-pas du fournisseur pour obtenir une **clé API**,
collez-la, puis « **Enregistrer et activer** » — c'est ce bouton, et
lui seul, qui active : changer le fournisseur dans la liste ne fait
que rafraîchir l'affichage. L'enregistrement teste aussitôt la
connexion et découvre les modèles disponibles ; les rôles économique,
performant et de lecture de documents s'attribuent tout seuls, sans
choix manuel. La clé est conservée chiffrée et jamais réaffichée —
laissez le champ vide lors d'une modification pour garder l'actuelle.

## Les coûts

Le fournisseur facture l'usage à l'unité, selon ses propres tarifs ;
le site n'affiche ni compteur ni budget. Les usages sont modestes —
quelques requêtes par action —, mais deux réglages ailleurs peuvent
en générer davantage : la règle de catégorisation « IA » des finances
et la modération automatique des groupes. Surveillez la consommation
sur le tableau de bord du fournisseur les premières semaines.

Un seul fournisseur est actif à la fois ; en changer se fait par le
même bouton, et l'ancien cesse aussitôt d'être appelé.
