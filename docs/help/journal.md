---
id: journal
title: Consulter le journal du site
summary: Retrouver qui a fait quoi, et quand.
category: Espace chefs d'U
role_min: admin
discovery: 3
question: Qui a changé cela, et quand ?
question: Où voir les erreurs techniques qu'a rencontrées le site ?
paths: /admin/journal
related: membres-admin, annee-scoute
---

Le journal consigne les actions notables faites sur le site : imports,
changements de rôle, bascules d'année, exports, modifications de
sections. C'est la page à ouvrir pour répondre à « qui a changé
cela, et quand ? ».

## Filtrer

La barre du haut combine plusieurs filtres :

- **Catégorie** : le domaine concerné (le cœur du site ou l'une de ses
  fonctions).
- **Type** : la clé de l'évènement, par exemple `auto_backup_failed` ;
  elle isole une seule sorte d'évènement.
- **Niveau**, quatre valeurs :
  - « Info » : le tout-venant, ce qui s'est passé comme prévu ;
  - « Avertissement » : un échec qui se rattrape seul — un envoi qui
    sera repris, une vérification refaite le lendemain ;
  - « Erreur » : un échec qui attend une action de quelqu'un — une
    sauvegarde ou une mise à jour ratée, une tâche planifiée en échec,
    un e-mail parti nulle part, une page qui a planté. C'est le premier
    filtre à essayer quand une chose attendue ne s'est pas faite, ou
    quand quelqu'un signale « ça a affiché une erreur » ;
  - « Sécurité » : les actions touchant aux droits et aux accès, soit
    l'historique des changements sensibles.
- **Recherche** : un mot du descriptif de l'action.
- **Email** : l'adresse exacte du compte qui a agi. Le filtre porte
  sur l'auteur de l'action, pas sur la personne concernée par elle.
- **Adresse IP**, et une période « Du / Au ».

## Lire une entrée

Chaque ligne donne la date, le niveau, la catégorie, le type, l'auteur
(un tiret pour une action automatique du site), l'adresse et le
descriptif. Touchez la ligne pour déplier le détail technique quand il
existe. Les entrées vont de la plus récente à la plus ancienne,
cinquante par page, et les filtres restent appliqués d'une page à
l'autre.

## Ce qu'on y trouve — et ce qu'on n'y trouve pas

On y retrouve par exemple : chaque import Desk, chaque étape cochée du
passage d'année et la bascule publique elle-même (y compris ses refus),
les changements de rôle d'une fonction, les renommages de sections,
les exports de membres, les accès temporaires « agir au nom de ».

S'y ajoutent les erreurs techniques : chaque page qui plante y laisse
une entrée « Erreur » avec le type de l'erreur, le fichier et la ligne,
et la pile d'appels dans le détail dépliable. Une erreur survenue
avant que la base de données ne réponde ne peut, elle, pas y figurer —
il n'y a alors rien pour l'écrire ; c'est le paquet de support
(Configuration > Maintenance) qui la contient.

En revanche, le journal ne contient jamais de données personnelles :
il référence les personnes par leur compte, pas par leurs
coordonnées, et le texte d'une recherche de membres n'y est pas
conservé. La page se consulte uniquement — rien ne s'y modifie ni ne
s'y efface.
