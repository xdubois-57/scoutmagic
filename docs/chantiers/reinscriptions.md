# Chantier — Réinscriptions

Journal d'implémentation du document de chantier
`docs/chantiers/CHANTIER-reinscriptions.md` (issue #796, itérations IT-01
à IT-05). Une section par itération : ce qui a été fait, les décisions
prises en autonomie, les divergences constatées entre le document de
chantier et le dépôt réel, et ce qui a été reporté. Même format que
`docs/chantiers/aide-contextuelle.md`.

---

## IT-01 — Le plan d'envoi, et la fermeture qui écrivait à tort

**Livré.**

- `Service\ReenrollmentSavePlanner` calcule le plan (D1), et
  `Service\ReenrollmentSavePlan` le porte. Le plan contient :
  - ce qui change ;
  - l'ouverture ou la fermeture que l'enregistrement provoque ;
  - les e-mails qui partiront : type, campagne, nombre de familles,
    immédiat ou différé ;
  - sinon, la raison pour laquelle rien ne part. Six raisons possibles :
    rien ne change, réglages seuls, e-mails désactivés, e-mail déjà parti,
    campagne terminée, aucune campagne.
- `ReenrollmentConfigController` lit ce plan :
  - `save()` met en file exactement les e-mails immédiats du plan, et aucun
    autre ;
  - la garde existante (`confirm_opening`, `/config/reinscription/apercu`)
    interroge le même plan.
- `openingSendsEmail()` disparaît. Ses règles vivent dans le planificateur ;
  `openingOnSave()` reste, et le planificateur s'appuie dessus.
- **Le correctif de l'incident.** Fermer une campagne dont la date de
  clôture est passée n'écrit à personne (raison « campagne terminée »).
- **Les e-mails différés.** Le plan annonce aussi ce que l'enregistrement
  rend dû à la prochaine passe horaire de `ReenrollmentCampaignHandler`, à
  savoir :
  - un rappel dont le délai tombe aujourd'hui sur une campagne ouverte ;
  - une date de fermeture déplacée à aujourd'hui.

  Un e-mail déjà dû avant l'enregistrement n'est pas annoncé : ce n'est pas
  l'enregistrement qui l'envoie.
- `ReenrollmentCampaignService` gagne trois fonctions :
  - `campaignKeyFor()` et `reminderDueOn()`, deux fonctions pures sur des
    réglages explicites (stockés ou soumis), dont `reminderDate()` dérive
    maintenant ;
  - `years()`, la paire « année publique / année visée », partagée par le
    suivi et le décompte des familles.

**Décisions autonomes.**

- **Une horloge injectable dans le contrôleur.** Les tests de la page
  lisaient l'horloge murale ; celui qui fermait la campagne passait en
  avril et reproduisait l'incident en octobre. Ils se placent maintenant au
  20/04/2027, et le test de l'incident au 04/10/2026.
- **Le nombre de familles** vient du même `ReenrollmentRecipientService` que
  l'envoi : toutes les familles pour l'ouverture, les familles sans réponse
  pour les autres e-mails. Le test du planificateur le remplace par un
  compteur fixe (41 / 27).
- **Ce que le plan « applique ».** `save()` met en file les e-mails immédiats
  du plan. Les différés partent à la passe horaire, par la même garde
  `handOver()` : rien ne les met en file deux fois.

**Écarts constatés avec le document (commit `fd08ff3`).**

- **Le libellé d'année.** Confirmé dans `SendReenrollmentEmailsHandler` :
  l'année visée de l'e-mail vient de l'année publique actuelle
  (`getCurrentPublicYear()` puis `nextLabel()`), pas de la campagne. C'est
  traité en IT-02, comme prévu.
- **`ReenrollmentConfigControllerTest`.** `testOpeningTheCampaignByHandOpensIt`
  ouvrait la campagne sans confirmation parce qu'il tournait, selon la date,
  dans une campagne terminée. Au 20/04/2027, l'ouverture écrit aux familles
  et demande donc le « oui ». Le test le donne.

**Reporté (prévu).** La confirmation de la fermeture et de tout autre
enregistrement relève d'IT-03 ; le choix de la campagne courante, d'IT-02.
