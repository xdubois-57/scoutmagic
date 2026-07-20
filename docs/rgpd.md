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

### Photos des membres (`member_photos`)

| Champ | Finalité |
|---|---|
| Photo, par membre et par année scoute | Affichage du visage d'un membre sur les pages qui l'exposent (ex. trombinoscope) |

Mécanisme central (non spécifique à un module) : une photo est associée à un
membre ET à une année scoute. Les photos ne sont pas chiffrées (comme les
autres images du site) mais l'accès au fichier est soumis au rôle minimum
défini sur le fichier (`identified` par défaut pour une photo de membre).
Aucune photo par défaut n'est stockée : en l'absence de photo, un avatar
générique (initiales) est affiché, sans traitement de données personnelles.

### Journal d'audit (`event_log`)

| Champ | Finalité |
|---|---|
| Adresse IP | Journalisation des actions sensibles à des fins de sécurité (détection d'abus) |
| Compte auteur de l'action | Traçabilité ; l'email est résolu à l'affichage pour les chefs d'unité, jamais stocké en clair |

Le journal ne contient aucune autre donnée personnelle. Base légale : intérêt
légitime (sécurité du site). Conservation selon le paramètre de rétention du journal.

## Base légale

- **Intérêt légitime** : gestion de l'unité scoute (membres, fonctions, sections).
- **Consentement** : cookies non essentiels, envoi d'emails non transactionnels.

## Durée de conservation

- Données des membres : durée de l'année scoute en cours.
- Comptes utilisateurs : jusqu'à suppression manuelle.
- Journal d'audit : configurable (par défaut 90 jours).
- Liens magiques : expirés après 15 minutes, nettoyés périodiquement.
- Assignations de garde SOS Staff d'U : purgées automatiquement au-delà d'un an (à chaque sauvegarde du planning), avec les tâches planifiées et les évènements de calendrier associés.

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
| Fournisseur de téléphonie (OVH Télécom, si le module SOS Staff d'U est activé et configuré) | Redirection automatique du numéro SOS de l'unité vers le membre du Staff d'U de garde |
| Fournisseur IA (si le module Connecteur IA est activé) — Anthropic (États-Unis) ou Mistral AI (France, UE) selon configuration | Analyse automatique de documents (factures, catégorisation). Les données envoyées peuvent contenir des noms, montants et contenus de documents. |

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

### Connecteur IA

Permet aux autres modules d'exploiter un fournisseur d'intelligence artificielle
générative pour des tâches automatisées (analyse de factures, catégorisation de
dépenses, extraction d'informations depuis des documents).

| Champ | Finalité |
|---|---|
| Clé API du fournisseur (`llm_providers.api_key`, chiffrée) | Authentification auprès de l'API du fournisseur IA |
| Contenu des requêtes envoyées au fournisseur | Données transmises pour traitement IA — peuvent inclure des noms, montants, contenus de factures selon le module appelant |

**Transfert de données :** lorsque ce module est actif, les données transmises
au fournisseur IA quittent les serveurs de ScoutMagic. Selon le fournisseur
configuré :

- **Anthropic** : données traitées aux États-Unis (hors UE).
  [Politique de confidentialité](https://www.anthropic.com/legal/privacy).
- **Mistral AI** : société française, données traitées dans l'Union européenne.
  [Politique de confidentialité](https://legal.mistral.ai/terms/privacy-policy).
- **Scaleway** : société française, données traitées dans l'Union européenne (France, Pays-Bas).
  [Politique de confidentialité](https://www.scaleway.com/en/privacy-policy/).

Le contenu des requêtes et réponses n'est **jamais journalisé** dans le journal
d'audit de ScoutMagic — seules les métadonnées (fournisseur, modèle, nombre de
tokens) sont enregistrées. La clé API est chiffrée au repos (AES-256-CBC).

Base légale : intérêt légitime (automatisation de tâches administratives de
l'unité). Le super-administrateur est informé du transfert de données lors de
la configuration du module.

### Trombinoscope

Affiche, par section, la photo (voir `member_photos` ci-dessus), le totem, le
nom et la fonction de tous les membres actifs ayant un rôle de chef ou de chef
d'unité. Ne traite aucune donnée qui ne soit pas déjà collectée ailleurs
(import Desk) ; n'ajoute aucune table de données personnelles propre au
module — seule l'indication de la fonction « responsable » de section
(non personnelle) est stockée dans `trombinoscope_function_flags`.

### Calendrier

Le lien ICS personnel (lien d'abonnement au calendrier propre à chaque
utilisateur identifié) lit — sans les stocker — l'e-mail, le rôle effectif et
les sections liées de l'utilisateur au moment de chaque consultation du lien,
afin de déterminer quels évènements lui montrer. Un jeton d'accès
(`calendar_personal_tokens.token`, une chaîne aléatoire non identifiante liée
à l'identifiant de compte) est conservé pour permettre la révocation via le
bouton « Régénérer » — ce jeton n'est pas une donnée personnelle au sens de
la stratégie de chiffrement ci-dessus (comparable à un jeton de session).
Les évènements eux-mêmes (titre, lieu, description) sont des données
organisationnelles, non personnelles ; `calendar_events.created_by` référence
un compte utilisateur (identifiant uniquement, jamais affiché).

Si l'option « Rappels avant les évènements multi-jours » est activée
(Configuration > Calendrier), un email est envoyé aux responsables de la
section concernée avant un évènement de plusieurs jours, pour leur rappeler
de déclarer l'évènement dans Desk et d'envoyer la liste des intendants au
Staff d'U. L'adresse email utilisée est relue depuis la fiche membre déjà
chiffrée au moment de l'envoi — aucune copie n'est conservée par ce
mécanisme (même principe que le lien ICS personnel ci-dessus et que le
module SOS Staff d'U).

### SOS Staff d'U

Planifie les gardes du numéro SOS de l'unité et automatise la redirection
téléphonique vers le membre du Staff d'U de garde, via l'API du fournisseur
de téléphonie configuré (OVH Télécom).

| Champ | Finalité |
|---|---|
| Assignation de garde (`sos_oncall_assignments` : identifiant du membre, date, état « de garde »/« indisponible ») | Planification des gardes ; détermine vers quel numéro rediriger le numéro SOS chaque jour |
| Numéro par défaut saisi manuellement (`sos_settings.default_number_manual_encrypted`, chiffré) | Numéro de repli utilisé les jours sans garde explicite, quand ce n'est pas le numéro d'un membre lié |
| Identifiants API du fournisseur de téléphonie (`sos_provider_credentials.config_encrypted`, chiffré : Application Key/Secret, Consumer Key OVH, ligne sélectionnée) | Authentification auprès du fournisseur pour lire/modifier la redirection |

Le numéro de mobile utilisé pour rediriger vers un membre du Staff d'U n'est
**jamais dupliqué** : il est relu, au moment de chaque changement de
redirection, depuis les données déjà chiffrées de sa fiche membre
(`member_years.mobile_encrypted`) pour l'année scoute en cours — comme le
lien ICS personnel du module Calendrier, aucune copie n'est conservée par ce
module. Les évènements synchronisés dans le calendrier (« SOS Staff d'U :
{totem/prénom} », un par période de garde consécutive) affichent uniquement
le nom d'affichage du membre, jamais son numéro.

Des emails sont envoyés à chaque changement de garde (si l'option est
activée) : au nouveau membre de garde, à l'ancien, et — en cas d'échec
technique de la redirection — à l'administrateur du site, pour l'alerter.
Base légale : intérêt légitime (sécurité du numéro d'urgence de l'unité).
