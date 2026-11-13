---
id: sos-staff-settings
title: Régler le numéro par défaut et les gardes
summary: Numéro par défaut, heure de changement de garde et notifications.
category: Espace chefs d'U
role_min: admin
question: Comment changer le numéro par défaut du téléphone d'urgence ?
question: Pourquoi le bouton Enregistrer du numéro par défaut est-il grisé ?
question: Pourquoi le numéro par défaut choisi n'est-il plus utilisé ?
question: Pourquoi un membre du Staff d'U n'est-il pas proposé ?
paths: /admin/sos/reglages
related: sos-staff, sos-staff-redirection, config-sos, actions-planifiees
---

L'onglet **Réglages** regroupe trois réglages du téléphone d'urgence.

Trois réglages, qui s'enregistrent d'eux-mêmes sauf le premier :

- le **numéro par défaut**, utilisé les jours sans garde attribuée — seuls
  les membres du Staff d'U dont le GSM est un numéro valide sont proposés.
  Le bouton « Enregistrer » ne s'active que si vous changez réellement la
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

## Quand la personne choisie quitte le Staff d'U

Le numéro par défaut que vous avez choisi n'est utilisé que tant que la
personne fait partie du Staff d'U de l'année, avec un GSM valide. Si elle
change de fonction, quitte l'unité ou si son GSM n'est plus utilisable,
le site revient au choix automatique : le ou la responsable du Staff d'U,
sinon le premier membre du Staff d'U. Il n'attend pas l'échec d'une
bascule pour le faire.

Un avertissement sur cette page et un point d'attention disent qui avait
été choisi et chez qui les appels arrivent désormais. Pour les faire
disparaître, enregistrez le membre affiché (le bouton reste actif) ou
choisissez-en un autre.

## Le format des GSM dans Desk

Le site lit le GSM tel qu'il est saisi dans Desk : « 0470 12 34 56 »,
« 0470/12.34.56 », « +32 470 12 34 56 » ou « 0032470123456 » désignent le
même numéro, et le site l'envoie toujours sous la même forme à
l'opérateur. Un numéro sans indicatif, sans le 0 initial ou avec un
chiffre en trop ou en moins n'est pas un numéro valide. La personne
n'apparaît alors ni dans le planning ni dans le choix du numéro par
défaut. Un avertissement la nomme sur cette page, ainsi qu'un point
d'attention. Corrigez le GSM dans Desk, puis réimportez.

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
