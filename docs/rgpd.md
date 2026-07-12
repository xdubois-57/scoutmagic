# Politique de confidentialité — RGPD

Ce document décrit les traitements de données personnelles effectués par le site.
Il sert de source de vérité pour la page RGPD en ligne (contenu éditable `rgpd.text`).

## Responsable du traitement

L'unité scoute propriétaire du site (configurée via la page de configuration).

## Données collectées

### Comptes utilisateurs (`user_accounts`)

| Champ | Finalité |
|---|---|
| Email (chiffré + index aveugle) | Authentification, envoi de liens magiques |
| Nom et prénom (chiffrés) | Affichage du profil |
| Mot de passe (haché) | Authentification par mot de passe |
| Clés WebAuthn | Authentification par passkey |

### Membres importés depuis Desk (`member_years`, `member_addresses`)

| Champ | Finalité |
|---|---|
| Prénom, nom (chiffrés) | Affichage des listes de membres |
| Genre (chiffré) | Statistiques internes |
| Date de naissance (chiffrée) | Gestion des branches d'âge |
| Téléphone, GSM (chiffrés) | Contact des membres |
| Email (chiffré + index aveugle) | Liaison compte ↔ fiche membre |
| Totem, quali, sizaine (chiffrés) | Vie de l'unité |
| Adresse postale (chiffrée) | Envoi de courrier |

Toutes les données personnelles sont chiffrées au repos (AES-256-CBC) et
déchiffrées uniquement dans la couche Repository.

## Base légale

- **Intérêt légitime** : gestion de l'unité scoute (membres, fonctions, sections).
- **Consentement** : cookies non essentiels, envoi d'emails non transactionnels.

## Durée de conservation

- Données des membres : durée de l'année scoute en cours.
- Comptes utilisateurs : jusqu'à suppression manuelle.
- Journal d'audit : configurable (par défaut 90 jours).
- Liens magiques : expirés après 15 minutes, nettoyés périodiquement.

## Cookies

La liste complète des cookies est générée dynamiquement à partir des déclarations
dans `CookieRegistry` (core) et `module.json` (modules). Elle est affichée sur :

- La page RGPD (`/rgpd`)
- La page de préférences cookies (`/cookies`)
- La bannière de consentement

## Sous-traitants

| Service | Finalité |
|---|---|
| Hébergeur web | Stockage des données, exécution du site |
| Relais SMTP (configurable) | Envoi d'emails transactionnels |

## Vos droits

Conformément au RGPD, vous disposez des droits suivants :

- **Accès** : demander une copie de vos données.
- **Rectification** : corriger des données inexactes.
- **Suppression** : demander l'effacement de vos données.
- **Portabilité** : recevoir vos données dans un format structuré.
- **Opposition** : vous opposer au traitement de vos données.

Contactez le responsable de l'unité pour exercer ces droits.

## Modules actifs

Chaque module qui traite des données personnelles doit documenter ses traitements
dans cette section. Consultez `module.json` pour la liste des modules activés.
