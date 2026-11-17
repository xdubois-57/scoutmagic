---
id: courrier-releve
title: Relever une adresse et lire ses erreurs
summary: Ce que vérifie « Vérifier et enregistrer », ce que dit la liste des adresses, et comment lire une erreur de relève.
category: Configuration
role_min: superadmin
question: Pourquoi l'adresse est-elle refusée à l'enregistrement ?
question: Que veut dire l'erreur affichée sur une adresse ?
paths: /config/courrier-entrant
related: courrier-entrant, courrier-unite
---

## La vérification avant d'enregistrer

« Vérifier et enregistrer » ouvre une connexion et lit la liste des
dossiers. Si elle échoue, **rien n'est enregistré** : l'adresse n'est pas
ajoutée, ou garde ses réglages précédents. Les champs restent remplis,
sauf le mot de passe, à retaper.

La vérification ne lit aucun message et ne touche pas à la date de
dernière relève. Quand elle réussit, la modification est enregistrée et
l'erreur affichée sur l'adresse disparaît : elle portait sur les anciens
réglages. Si la relève suivante échoue encore, la liste le dira.

## La liste des adresses

Chaque adresse dit quand elle a été relevée. En cas d'échec, une phrase
dit ce qui ne va pas : corrigez le réglage avec « Modifier ». Pour
relever tout de suite, sans attendre le prochain passage, utilisez
« Relever le courrier maintenant » dans « Courrier reçu ».

## Lire une erreur de relève

L'erreur dit à quelle étape la relève s'est arrêtée :

- **le serveur n'a pas répondu** : la connexion elle-même n'a pas pu
  s'établir — vérifiez le serveur, le port et que l'hébergement autorise
  les connexions sortantes ;
- **authentification refusée** : le mot de passe a changé, ou ce n'est
  pas un mot de passe d'application ;
- **certificat non vérifié** : vérifiez le nom du serveur et le
  chiffrement ; il n'existe pas d'option pour passer outre ;
- **dossier introuvable** : un des dossiers lus n'existe plus sous ce
  nom exact ;
- **une étape après une connexion réussie** (liste des dossiers,
  ouverture d'un dossier, lecture des messages) : les identifiants sont
  bons, c'est le serveur qui a refusé ou coupé. Le nom technique entre
  parenthèses est celui que le support vous demandera.
