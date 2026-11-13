---
id: sos-staff-default-number
title: Le numéro par défaut et les GSM du Staff d'U
summary: Quand le numéro par défaut choisi n'est plus utilisé, et pourquoi un GSM Desk peut être refusé.
category: Espace chefs d'U
role_min: admin
question: Pourquoi le numéro par défaut choisi n'est-il plus utilisé ?
question: Pourquoi un membre du Staff d'U n'est-il pas proposé comme numéro par défaut ?
paths: /admin/sos/reglages
related: sos-staff-settings, sos-staff-redirection, points-attention-modules
---

## Quand la personne choisie quitte le Staff d'U

Le numéro par défaut que vous avez choisi n'est utilisé que tant que la
personne fait partie du Staff d'U de l'année, avec un GSM valide. Si elle
change de fonction, quitte l'unité ou si son GSM n'est plus utilisable,
le site revient au choix automatique : le ou la responsable du Staff d'U,
sinon le premier membre du Staff d'U. Il n'attend pas l'échec d'une
bascule pour le faire.

Un avertissement dans l'onglet **Réglages** et un point d'attention
disent qui avait été choisi et vers qui la redirection est désormais
prévue les jours sans garde. Pour les faire disparaître, enregistrez le
membre affiché (le bouton reste actif) ou choisissez-en un autre.

## Le format des GSM dans Desk

Le site lit le GSM tel qu'il est saisi dans Desk : « 0470 12 34 56 »,
« 0470/12.34.56 », « +32 470 12 34 56 » ou « 0032470123456 » désignent le
même numéro, et le site l'envoie toujours sous la même forme à
l'opérateur. Un numéro sans indicatif, sans le 0 initial ou avec un
chiffre en trop ou en moins n'est pas un numéro valide. La personne
reste dans le planning, avec ses gardes, mais le numéro SOS ne peut pas
lui être redirigé : une bascule vers elle est refusée, et elle ne peut
pas être le numéro par défaut. Un avertissement la nomme dans les onglets
**Planning** et **Réglages**, ainsi qu'un point d'attention. Corrigez le GSM dans Desk, puis réimportez.
