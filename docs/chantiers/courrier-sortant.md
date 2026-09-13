# Chantier — Courrier sortant

Journal d'implémentation du document de chantier « Courrier sortant »
(itérations IT-01 à IT-07, issue #305). Une section par itération : ce qui
a été livré, les décisions prises en autonomie, les divergences constatées
entre le document de chantier et le dépôt réel, et ce qui a été reporté.
Même format que `docs/chantiers/aide-contextuelle.md`.

La maquette qui accompagne le chantier est déposée sous
`docs/chantiers/maquettes/maquette-courrier-sortant.jsx` et inscrite au
tableau du `README.md` de ce dossier.

---

## Écarté, explicitement — recopié du document de chantier

L'issue #305 demandait six choses. Trois sont écartées, avec leurs
raisons, et elles sont recopiées ici pour qu'elles ne reviennent pas par
accident.

**La réputation IP et les listes noires.** Sur hébergement mutualisé l'IP
d'envoi appartient à l'hébergeur et est partagée ; avec un relais, elle
appartient au relais — dans les deux cas on n'a aucune prise dessus.
Spamhaus et la plupart des DNSBL refusent les requêtes venant de
résolveurs publics ou d'hébergeurs et exigent un flux payant au-delà d'un
volume trivial : une interrogation naïve renvoie souvent un faux
« listé » dû à la politique de requête. Ce serait une alerte qui crie au
loup. **Ce qu'on garde à la place** : afficher sur la page quel relais et
quelle IP sont réellement utilisés, avec quelques liens de vérification
publique pour l'admin qui a un soupçon un jour précis.

**Le VERP** (adresse d'enveloppe unique par destinataire). Il rendrait
l'identification du rebond exacte sans analyser le DSN, mais il suppose un
attrape-tout ou du sous-adressage chez l'hébergeur, ce qui n'est pas
universel, et il faut savoir ce que c'est pour le configurer. À
reconsidérer si l'analyse des DSN se révèle trop peu fiable en pratique.

**Une adresse d'enveloppe distincte de l'adresse d'expédition** est
également reportée : c'est une option pour une très grosse unité, pas un
axe de conception. Le modèle retenu est une seule adresse jouant les
quatre rôles.

---

## IT-01 — Le transport à plusieurs fournisseurs, et son écran

**Livré.**

- `Core\Mail\MailPurpose` passe à trois cas (D3) : `Bulk` rejoint
  `Ordinary` et `MagicLink`. `modules/mass_mail` le déclare sur chaque
  copie envoyée ; rien d'autre ne le nomme.
- `Core\Mail\Transport` : `MailLane` (l'unique correspondance voie ←
  `MailPurpose`), `MailProvider`, `MailProviderRepository`,
  `MailProviderDirectory`, `ProviderConnections`, `LaneEntry`,
  `LaneChainRepository`, `SendCounterRepository`,
  `TransportConfigurator`, `MailTransportChain`, `BulkCadence`,
  `TransportService`, `TransportSeeder`, `TransportException`, et
  `Task\PurgeSendCountersHandler`.
- Trois tables dans `schema/core.sql` : `mail_providers`,
  `mail_lane_entries`, `mail_send_counters`.
- L'écran : `/config/courrier-sortant` (Fournisseurs) et
  `/config/courrier-sortant/acheminement`, plus le formulaire d'un
  fournisseur, sur le rail `partials/page_picker.html.twig`. Les trois
  chaînes sont éditées par `partials/list_editor.html.twig`.
- Le câblage : la chaîne s'insère **devant** le transport de livraison
  dans les deux racines de composition, le semis initial, le
  `TaskContext` qui porte la cadence, la purge des compteurs.
- Les deux réglages de `mass_mail` (`batch_size`,
  `batch_interval_minutes`) sortent de son manifeste, version montée de
  1.16.0 à 1.17.0 ; `mail_local_batch_size` et
  `mail_local_batch_interval_minutes` sont enregistrés par
  `SettingService::register()` et exclus de la page Paramètres générique.
- Le paquet de support gagne `OutboundMailCollector` et sa ligne dans
  `ArchiveContents`.
- Le journal d'événements gagne `mail_provider_added` / `_updated` /
  `_deleted`, `mail_lane_reordered`, `mail_lane_entry_enabled` /
  `_disabled` (tous en `security`) et `mail_provider_attempt_failed` (en
  `warning`).
- Documentation : `ARCHITECTURE.md` §8.106, `specifications.md` §4.5,
  `design.md` §4.4, le sujet d'aide `docs/help/courrier-sortant.md`.
- Tests : `MailTransportChainTest`, `TransportServiceTest`,
  `BulkCadenceTest`, `TransportSeederTest`, `MailLaneTest`,
  `OutboundMailControllerTest` (RBAC sur les neuf routes), plus le cas
  ajouté à `CaptureTransportTest` pour la revue de `Modules\TestTools`.

**Décisions autonomes.**

1. **Le quota et la cadence d'un relais sont des colonnes, pas des
   réglages.** Voir « Divergences » ci-dessous : c'est la plus
   structurante.
2. **L'envoi local n'a pas de ligne en base.** Il est synthétisé à l'id
   0. « Jamais supprimable » (D5) devient une propriété du code plutôt
   qu'un contrôle qu'on peut oublier d'écrire : il n'y a rien à
   supprimer. L'id 0 plutôt qu'une colonne nullable pour la raison que
   `inbound_message_links.attachment_id` documente déjà (§8.58) — MySQL
   considère deux `NULL` comme distincts dans un index unique.
3. **Le premier fournisseur porte le préfixe de secret `smtp`**, c'est-à-
   dire les clés historiques que l'assistant d'installation écrit depuis
   toujours. Sans cela, « Installation & serveur » et la page
   Fournisseurs auraient chacune leur copie du même relais, et changer le
   mot de passe sur l'une casserait l'autre en silence. Un seul stockage,
   deux écrans.
4. **La chaîne s'insère devant le transport de livraison, et
   `MailService::send()` n'est pas modifié d'une ligne.** Les transports
   s'exécutent en dernier, donc le dernier mot sur le serveur à contacter
   est celui qui compte. Conséquence heureuse : le bac à sable du module
   `test_tools` continue de fonctionner sans rien savoir de tout ceci, et
   un message capturé est routé et compté exactement comme un vrai.
5. **Une chaîne illisible n'est pas une chaîne vide.** Sur une
   installation dont les tables n'existent pas encore, la chaîne rend la
   main au transport plutôt que de refuser ; une chaîne vide, elle, est
   une vraie erreur de configuration et le dit. Confondre les deux
   donnerait soit un site incapable d'envoyer pendant sa propre
   installation, soit une mauvaise configuration silencieuse.
6. **Le compteur avance après le retour du transport, jamais avant.** Un
   compteur incrémenté sur une *tentative* ferait sauter une voie par-
   dessus un fournisseur qui marche parfaitement, dès que quoi que ce soit
   d'autre échoue.
7. **`TransportConfigurator::apply()` appelle `smtpClose()` en premier.**
   PHPMailer réutilise la connexion qu'il tient, quel que soit le `Host`
   courant : sans cela, le message de repli repartirait par le relais qui
   venait de le refuser.
8. **Un relais sans hôte est sauté plutôt que tenté.** PHPMailer avec un
   `Host` vide échoue lentement, et la voie derrière lui paierait ce délai
   sur chaque message.
9. **Un fournisseur ajouté arrive désactivé dans les trois voies.** Un
   relais qui se mettrait à porter les liens de connexion à l'instant où
   on l'enregistre serait une décision d'acheminement que personne n'a
   prise. La page le dit sous le bouton.
10. **La sous-page « Tableau de bord » n'existe pas encore**, et
    `/config/courrier-sortant` rend donc « Fournisseurs ». IT-03 la livre
    et déplace Fournisseurs sous
    `/config/courrier-sortant/fournisseurs`. Un onglet vers une page qui
    n'existe pas est un 404 à un clic ; une page de tableau de bord
    livrée à moitié serait une anticipation de l'itération suivante.
11. **Le mot de passe d'un relais utilise `partials/form_field.html.twig`
    en `type: password`, pas `partials/password_field.html.twig`.** Ce
    dernier décrit un mot de passe que quelqu'un *choisit* — liste de
    contrôle de complexité, champ de confirmation, module JS. Celui-ci est
    donné par le fournisseur.
12. **`MailProvider` ne porte aucune propriété de mot de passe.** Une
    seule classe en lit un, `TransportConfigurator`, ce qui rend
    structurellement impossible qu'un fournisseur en laisse fuiter un dans
    un gabarit, un contexte de journal ou l'archive de support.

**Divergences avec le document de chantier.**

- **« le transport les réenregistre par fournisseur » (IT-01, les deux
  réglages de `mass_mail`).** Lu à la lettre, cela veut dire des clés de
  `settings` dynamiques du genre `mail_provider_7_batch_size`, créées à
  l'ajout d'un fournisseur. Ce n'est pas ce qui a été fait, pour trois
  raisons qui vont toutes dans le même sens. `SettingService::register()`
  déclare une valeur **par défaut** par clé, et le quota par défaut d'un
  relais n'existe pas : Brevo en accepte 300, OVH 500, et le prochain
  autre chose. `SettingRepository::resetAllToDefaults()` remettrait donc
  le quota de chaque relais à un nombre qui n'a jamais rien voulu dire.
  Et la liste d'exclusion de `SettingsController::index()` est un tableau
  de clés littérales, qu'un préfixe dynamique obligerait à transformer en
  correspondance de motif. Surtout, D6 dit que le quota et la cadence
  « appartiennent au fournisseur » : ce sont donc des attributs de la
  ligne, et c'est ce qui a été écrit. **Ce que le document demandait est
  respecté là où il porte** : la cadence de l'envoi local — qui, lui, n'a
  pas de ligne — est bien enregistrée par `SettingService::register()` et
  bien exclue de la page Paramètres générique, avec le quota qui, lui,
  n'apparaît nulle part puisqu'il n'est jamais un réglage.
- **« Page `/config/courrier-sortant` … Sept sous-pages ».** Les sept
  sous-pages sont l'état final, pas l'état d'IT-01 : le rail n'en porte
  que deux ici et grandit avec les itérations. Voir la décision 10.

**RGPD.** La section « Sous-traitants essentiels » de
`core/View/rgpd_default.html` est mise à jour : le relais SMTP y était au
singulier, il y est désormais au pluriel, avec la phrase qui dit qu'une
unité peut en déclarer plusieurs, décider par type de message lequel sert
et lequel prend le relais, et où lire la liste en vigueur. Le prompt de
`RgpdContentService::buildSystemPrompt()` n'est **pas** touché, et c'est
un constat plutôt qu'un report : les faits propres à une installation
qu'il porte (fournisseur IA, téléphonie, stockage galerie) lui viennent
des implémentations de `Core\Module\SubProcessorProvider`, un canal qui
n'existe que pour les modules. Le relais du cœur n'en a pas, et lui en
inventer un serait une décision d'architecture, pas une mise à jour de
documentation.

**Reporté.** Rien.

