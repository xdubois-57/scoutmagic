---
id: echec-envoi-decision
title: Résoudre un échec d'e-mail de décision
summary: Comprendre les réessais automatiques et intervenir après une alerte d'échec.
category: Espace chefs d'U
role_min: admin
question: Que faire si l'e-mail de décision n'est pas envoyé ?
question: Pourquoi ai-je reçu une alerte pour un e-mail d'inscription ?
paths: /config/inscriptions/demandes/*, /config/inscriptions/reglages
related: envoi-automatique-des-e-mails-de-decision, envoyer-l-e-mail-de-decision, rediger-les-e-mails-de-demande
---

## Les réessais automatiques

Pour une erreur temporaire confirmée du serveur d'envoi, le site prévoit
une nouvelle tentative après **une heure**, puis une dernière **six heures
après le deuxième échec** : au plus trois tentatives automatiques au total.
Chaque échec reste dans l'historique et la fiche montre la prochaine date
prévue. « Envoyer maintenant » peut avancer cette tentative ; il ne crée
pas un deuxième envoi en parallèle. Changer la décision annule aussi la
nouvelle tentative prévue.

Si le troisième envoi échoue, ou si l'erreur n'est pas temporaire, les
administrateurs reçoivent une notification **E-mail de décision à vérifier**,
avec un lien vers la fiche. Elle apparaît dans la cloche ; la notification
push suit leurs préférences. Cette alerte ne part pas par e-mail, puisque
le serveur d'envoi peut justement être en panne. Elle ne contient aucune
adresse, aucun nom de famille et aucun texte d'erreur SMTP.

Un texte sans lien de suivi n'est jamais réessayé automatiquement : il
faut d'abord corriger le gabarit. Un résultat d'envoi incertain n'est pas
non plus réessayé automatiquement, pour éviter un doublon. Une tâche
interrompue sans résultat depuis dix minutes déclenche aussi une alerte,
sans renvoyer le message. La fiche reste
le point de départ pour vérifier puis choisir « Réessayer ».

## Intervenir depuis la fiche

Ouvrez la fiche depuis la notification et consultez l'historique. Faites
corriger le problème, puis utilisez **Réessayer**. Après un envoi depuis la
fiche, consultez le résultat qui s'y affiche.
