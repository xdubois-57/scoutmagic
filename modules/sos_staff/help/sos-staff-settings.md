---
id: sos-staff-settings
title: Régler le numéro par défaut et les gardes
summary: Numéro par défaut, heure de changement de garde et notifications.
category: Espace chefs d'U
role_min: admin
question: Comment changer le numéro par défaut du téléphone d'urgence ?
question: Pourquoi le bouton Enregistrer du numéro par défaut est-il grisé ?
paths: /admin/sos/reglages
related: sos-staff, sos-staff-default-number, sos-staff-redirection, config-sos, actions-planifiees
---

L'onglet **Réglages** regroupe trois réglages du téléphone d'urgence.

Trois réglages, qui s'enregistrent d'eux-mêmes sauf le premier :

- le **numéro par défaut**, utilisé les jours sans garde attribuée — seuls
  les membres du Staff d'U dont le GSM est un numéro valide sont proposés
  (voir « Le numéro par défaut et les GSM du Staff d'U »). Le bouton « Enregistrer » ne s'active que si vous changez réellement la
  valeur. Si
  la journée en cours n'a pas de garde, un avertissement vous prévient que
  l'enregistrement redirige les appels immédiatement ;
- l'**heure de changement de garde** ;
- les **notifications de garde** : à chaque changement de destinataire, la
  personne qui prend la garde et celle qui la termine sont prévenues ;
  chacune choisit ses canaux dans ses préférences de notifications. Ce
  réglage est l'interrupteur global.

Les notifications sont créées après vérification du nouveau renvoi chez
l'opérateur. Chaque compte lié au membre par son adresse Desk ou une
adresse secondaire **validée** peut les recevoir, avec ses propres
préférences. Une adresse secondaire en attente ou désactivée ne suffit
pas. Sans compte lié, aucune notification n'est créée pour cette personne.

Un contrôle ou une reprise qui trouve le renvoi déjà correct ne crée pas
de nouvelle notification. C'est aussi le cas si deux personnes ont le
même numéro : aucun nouveau changement de renvoi n'a eu lieu. Une reprise
de la tâche d'envoi ne réexpédie pas les emails déjà pris en charge.

## Si un email de garde n'arrive pas

Vérifiez d'abord l'interrupteur global ci-dessus. La personne concernée
peut ensuite ouvrir ses **préférences de notifications** et contrôler le
canal email du groupe **SOS Staff d'U**, pour le début et la fin de garde.
Le compte doit être lié au membre et disposer du rôle autorisé.

Une notification visible dans le centre prouve sa création, pas la
réception d'un email. Avec l'administration du site, consultez les
**Actions planifiées** : après la tâche de redirection, l'envoi passe par
`core/send_notification_emails`. Une tâche en attente indique qu'il faut
vérifier l'exécution du planificateur.

Dans le journal, `notification_skipped` avec la raison `no_account`
signale l'absence de compte lié ; `notification_failed` signale un échec
de création. `notification_email_failed` indique un refus du transport
d'email. Ce refus n'annule pas la redirection et ne provoque pas de
réexpédition automatique : la notification reste accessible dans le centre.
Ces diagnostics utilisent des identifiants, sans adresse ni numéro privé.

> Les bascules s'exécutent en arrière-plan : sans tâche planifiée réelle
> chez l'hébergeur, elles peuvent partir en retard. Si l'état affiché ne
> correspond pas aux gardes, c'est le premier point à vérifier — et le
> bouton du Tableau de bord remet le renvoi d'aplomb en attendant.
