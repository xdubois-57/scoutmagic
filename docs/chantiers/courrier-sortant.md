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

**Auto-revue, le relecteur automatique s'étant arrêté à mi-course deux
fois de suite sur cette PR** (9 agents lancés / 5 rendus, puis 3 / 1 —
la forme du défaut #208 décrite dans la compétence `steward`, pas un
verdict sur le diff : 4 min 18 s contre un plafond de 60, et aucun
commentaire posté). Deux constats en sont sortis.

Le premier est corrigé : le contrat de `MailTransportChain::candidates()`
a **trois** états et son commentaire n'en décrivait que deux. Une chaîne
illisible et une voie sans aucune ligne rendent toutes deux la main au
transport — la seconde parce que le seul moyen de l'atteindre est une base
migrée avant que le semis ait pu poser les chaînes, et refuser là voudrait
dire un site incapable d'envoyer pendant sa propre installation. Une voie
qui a des lignes dont aucune n'est utilisable, elle, est une vraie erreur
de configuration et le dit. Un test épingle désormais ce troisième état.

Le second est une exposition de séquencement, énoncée plutôt que corrigée :
**entre IT-01 et IT-02, une voie dont le quota est atteint échoue au lieu
d'être différée** — le report, c'est D9, et c'est IT-02. La portée réelle
est étroite : aucun quota n'existe par défaut (le semis comme l'ajout
enregistrent `null`, « aucun plafond connu »), donc il faut qu'un
administrateur en saisisse un ; et pour le publipostage,
`POST /mass-mail/recipients/{id}/resend` existe déjà pour rattraper les
destinataires marqués en échec. IT-02 referme la fenêtre.

**Trois trouvailles de relecture, toutes réelles, toutes corrigées.** Le
relecteur automatique s'était arrêté à mi-course deux fois (voir plus bas),
mais il a tout de même posé trois commentaires avant de s'arrêter, et
aucun n'était un détail.

1. **Le bloc de commentaire ajouté à `public/cron.php` était en
   français.** `AGENTS.md` § Language est explicite : le code et ses
   commentaires sont en anglais, seul ce qui est écrit *à propos* d'un
   changement est en français. Je l'avais calqué sur le bloc voisin, qui
   est en français et antérieur à cette PR — celui-là reste tel quel,
   le corriger élargirait le changement.

2. **`reorder()` et `toggle()` lisaient `$request->getBody()`, alors que
   l'écran envoie du JSON.** C'était une panne de fonctionnalité, pas une
   imprécision : `ScoutMagicApi.postJson()` envoie
   `Content-Type: application/json`, PHP ne remplit jamais `$_POST` pour
   un corps JSON, donc `Request::fromGlobals()` construit un `body` vide.
   Conséquences exactes : un réordonnancement appliquait une liste vide,
   journalisait un ordre vide en `security` et répondait `success` ; et
   une activation cherchait l'entrée `-1`, donc **aucun fournisseur
   nouvellement ajouté ne pouvait jamais être activé dans une voie depuis
   l'écran**. Le corps brut est désormais décodé explicitement, comme le
   font déjà `ConfigModulesController` et `SectionDocumentController`.
   Mes tests ne l'avaient pas vu parce que leur aide `jsonRequest()`
   construisait un `Request` avec le `body` prérempli, ce qui court-
   circuitait toute la distinction : elle envoie maintenant un vrai corps
   brut, et les trois tests concernés échouent bien contre l'ancien code.

3. **Le semis n'était pas reprenable.** Poser les chaînes, c'est plusieurs
   écritures : la ligne du relais, puis une entrée par voie. Une panne
   passagère entre les deux laissait la ligne écrite et le drapeau non
   posé — donc une nouvelle tentative au prochain démarrage, sauf que le
   garde lisait « y a-t-il des fournisseurs ? », concluait qu'il n'y avait
   rien à créer, et n'atteignait jamais les voies manquées. Le relais
   restait absent de ces voies pour la vie de l'installation, en silence,
   puisque le courrier continue de partir par l'entrée locale. La reprise
   se fait désormais sur le préfixe de secret (`findBySecretPrefix()`), et
   un test rejoue précisément la tentative interrompue.

**Une fabrique partagée pour la chaîne** (`MailTransportFactory`), née
d'un constat de couverture mais justifiée ailleurs : je construisais la
chaîne à la main dans les *deux* racines de composition, ce qui est
exactement la dérive que §8.17 raconte deux fois (`create_backup`
enregistré dans `index.php` et absent de `cron.php` ; `NotificationService`
construit avec la résolution de rôle d'un côté et sans de l'autre). Une
chaîne construite différemment sur les deux chemins donnerait un
publipostage qui respecte les quotas sous un déclencheur et les ignore
sous l'autre. La fabrique supprime le risque et, accessoirement, déplace
une quarantaine de lignes de `public/cron.php` — que rien ne peut couvrir,
ni PHPUnit ni le bout-en-bout — vers une classe qui a ses tests.

**Couverture.** Le portail qualité de SonarCloud a refusé le premier
passage à **77,5 % de couverture sur le code neuf** (seuil : 80 %). Trois
trous, tous réels et tous comblés plutôt que contournés :
`OutboundMailCollector` et `Task\PurgeSendCountersHandler` n'avaient aucun
test, `SendCounterRepository::dailyNonBulkTotals()` — la requête sur
laquelle IT-02 calculera la réserve — non plus, et le contrôleur n'était
testé qu'en lecture. Les quatre fichiers de tests ajoutés valent au-delà du
seuil : celui du collecteur épingle surtout une assertion **négative**,
qu'aucun identifiant ni destinataire n'entre dans une archive qui part
chez un tiers, contre un jeu d'essai dont le mot de passe est une chaîne
reconnaissable.

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


**Le semis relisait une photographie, pas un état.** Deux constats d'une
passe de relecture complète, sur le même point de couture, et qui sont les
deux moitiés d'une même erreur : `TransportSeeder` décidait une fois pour
toutes, au premier démarrage, à partir de `smtp_host` seul.

Or `SetupController::handleConfigUpdate()` réécrit ce `smtp_host` dans
`secrets.enc` **à chaque enregistrement, quel que soit le mode** — le
gabarit ne masque les champs SMTP que côté navigateur quand « Local » est
choisi, ils partent quand même. Une installation passée de SMTP à Local
garde donc un hôte parfaitement lisible dont elle ne veut plus. Le semis en
faisait le premier fournisseur, **actif, en tête des trois voies**, et
`TransportConfigurator::apply()` appelant `isSMTP()` sans consulter le mode,
tout le courrier — liens magiques compris — repartait par un tiers que
l'unité avait délibérément quitté. Le mode gouverne maintenant la reprise,
et un `mail_mode` absent se lit `local`, comme `MailServiceFactory` le fait
déjà.

L'autre moitié est le drapeau. Une installation semée en local pur n'avait
rien à reprendre, le drapeau se posait quand même, et le relais que
quelqu'un configurerait un mois plus tard par l'assistant n'était jamais
repris : le courrier continuait de partir localement pendant
qu'« Installation & serveur » affichait un relais — en silence, le bouton
« Envoyer un email de test » de l'assistant appelant
`MailServiceFactory::create()` sans passer par la chaîne. D'où deux
drapeaux, parce qu'ils enregistrent deux décisions distinctes : les voies
sont posées, et le relais est repris. Un seul ne pouvait pas dire les
deux — effacé, il ressusciterait à la requête suivante un relais qu'un
administrateur a supprimé de la page Fournisseurs, dont
`ProviderConnections::forget()` conserve délibérément les quatre clés.

**Et une troisième panne, trouvée par le test écrit pour la deuxième.**
Reprendre le relais tardivement l'ajoutait *derrière* l'envoi local, qui
occupe déjà la position 0 : essayé en second, atteint seulement si l'envoi
depuis le serveur avait échoué. Le courrier serait resté local — exactement
ce que la reprise tardive existe pour faire cesser. `putFirst()` le remet
en tête, et seulement sur une entrée que la passe vient de créer, pour ne
jamais contredire un ordre choisi par un administrateur.

Les quatre morceaux du correctif sont tenus chacun par un test vérifié en
échec sur le code fautif.

**Supprimer un fournisseur effaçait sa ligne avant ses identifiants.**
Troisième constat d'une relecture complète, et le seul de cette PR qui
touche à la confidentialité plutôt qu'à l'acheminement.
`TransportService::deleteProvider()` supprimait la ligne, puis appelait
`ProviderConnections::forget()`. Or `forget()` est une écriture de fichier
sur `secrets.enc` et peut échouer. Dans ce cas l'hôte, l'identifiant et le
mot de passe du relais restaient dans le fichier **pour toujours** : la
tentative suivante ne trouve plus de ligne, sort au garde `findById()`
sans rien faire, et aucun autre chemin du site ne connaît ce préfixe. Le
mot de passe d'un tiers aurait survécu au fournisseur qui justifiait de le
garder, l'écran répondant « Fournisseur supprimé. » à chaque essai.

L'ordre est inversé : les identifiants d'abord, la ligne ensuite. L'échec
devient propre — rien d'autre n'a bougé, donc réessayer est un essai
ordinaire — et la fenêtre que cela ouvre est la bonne : une ligne dont les
secrets ont disparu n'a plus d'hôte, donc `MailProvider::isUsable()` est
faux et la chaîne l'enjambe comme elle enjambe un fournisseur épuisé.

Second effet du même constat : `ProviderConnections` lève un
`RuntimeException` nu et `TransportException` est `final`, donc le
`catch (TransportException)` du contrôleur ne l'attrapait pas et la panne
serait arrivée au visiteur en 500. Elle est désormais convertie, avec un
message écrit sur place plutôt que repris de l'exception — celle-ci nomme
un chemin sur le serveur (SECURITY.md §11).

**Et les commentaires Twig.** `main` a gagné entre-temps le cliquet
`TwigCommentsAreEnglishTest` (#327 / #329). Deux de mes gabarits portaient
un commentaire français ; ils sont traduits, et non ajoutés à la liste
d'exemption, qui ne fait que rétrécir. À noter pour la suite : la CI
construit `refs/pull/328/merge`, donc elle voyait ce test avant que mon
arbre de travail ne l'ait — la suite complète passait ici et échouait
là-bas.

**Dix-sept constats de CodeRabbit, dont trois qui comptaient.** Le reste
est réel mais mineur ; ceux-ci méritent d'être nommés.

`SecretManager::writeSecrets()` ignorait le retour de `file_put_contents()`.
Une écriture échouée était donc silencieuse — et cela vidait de sa
substance le correctif précédent, qui fait reposer la suppression d'un
fournisseur sur le fait que `forget()` lève quand l'effacement rate. Par
ce chemin-là, la ligne repartait et le mot de passe restait. Le retour est
vérifié. C'est du cœur, utilisé par l'assistant d'installation comme par
le reste, et c'est précisément pour cela qu'il fallait le corriger ici
plutôt que le noter.

`MailTransportChain` comptait l'envoi après la livraison — bien — mais
laissait l'échec du compteur remonter. Le destinataire a le message,
`MailService` annonçait un échec, et `mass_mail` réessayait : une erreur
de comptabilité mettait une seconde copie dans une boîte. Le compte vaut
moins que ça ; il est désormais journalisé et avalé.

`ProviderConnections::mutate()` mettait à jour sa copie en mémoire dans la
boucle, avant l'écriture. Un échec laissait l'objet — celui que lit le
reste de la requête — décrivant un fichier que rien n'avait changé.

**Et un constat juste dont le correctif proposé était faux.**
`nameFor('mail.infomaniak.ch')` rendait « Ch » : la liste de suffixes ne
nommait que `.com/.net/.org/.be/.fr/.eu/.io`. Le correctif suggéré —
retirer tout dernier label de trois lettres ou moins — a été essayé et
**cassé un test existant** : `ssl0.ovh.net` devenait « Ssl0 », parce que
`ovh` EST le fournisseur. Les suffixes sont donc nommés, pas mesurés, et
un test à données couvre les deux cas.

Refusés, avec la raison : la cadence « collante » après une bascule
(`BulkCadence`) demande de savoir qu'un fournisseur est écarté, ce qui est
le coupe-circuit de D15 en IT-02 — l'implémenter ici serait anticiper
l'itération suivante, qu'`AGENTS.md` et le document de chantier
interdisent tous deux ; la transactionnalité complète d'`addProvider()` et
d'`updateProvider()` à travers une base ET un fichier (la moitié qui
comptait — l'écriture silencieuse — est corrigée ci-dessus) ; et des tests
de routage passant par `FrontController`, que le job `Authorization
matrix` exerce déjà de bout en bout sur ces routes.

Le formulaire disait « les identifiants … ne sont jamais réaffichés »
alors que l'identifiant l'est. Le correctif proposé — le vider — aurait
effacé l'identifiant à chaque enregistrement, `store()` l'écrivant sans
condition. C'est donc la phrase qui est corrigée, ici et dans le sujet
d'aide : le mot de passe n'est jamais réaffiché, l'identifiant l'est, et
on dit pourquoi.

Enfin, `@group database` en doc-comment est inerte sous PHPUnit 13, que
`composer.lock` fige. Sept fichiers étaient concernés — `--group=database`
n'en exécutait aucun, ce qui s'est vu en direct : la commande répondait
« No tests executed ». L'attribut, que le reste du dépôt utilise déjà,
les sélectionne.

**Le correctif précédent en a créé un, et la relecture l'a vu.** Rendre
`SecretManager::writeSecrets()` bruyant était juste, mais cela a donné à
`addProvider()` et `updateProvider()` un nouveau chemin d'échec :
`store()` peut désormais lever, `TransportException` est `final`, donc le
`catch (TransportException)` du contrôleur ne l'attrapait pas et un
super-admin recevait un 500. Pire, l'ordre aggravait : `addProvider()`
avait déjà créé la ligne, laissant un fournisseur sans voie et sans hôte
qu'aucun écran ne pouvait réparer et que chaque réessai dupliquait ;
`updateProvider()` avait déjà enregistré le nom, le quota et la cadence à
côté de l'ANCIEN hôte et de l'ANCIEN mot de passe.

Le même principe que pour la suppression, appliqué aux deux, avec la
nuance que l'un des deux ne peut pas choisir son ordre. `updateProvider()`
le peut : identifiants d'abord, métadonnées ensuite, et un échec ne change
plus rien du tout. `addProvider()` ne le peut pas — `prefixFor($id)` a
besoin de l'id, donc la ligne doit exister avant le secret. Il compense :
`rollBack()` reprend la ligne, et le réessai redevient un essai ordinaire.

C'est la troisième fois sur cette PR qu'un correctif ouvre la porte
suivante, et les trois fois la relecture complète l'a trouvée. Vaut d'être
noté pour la suite du chantier : les corrections d'ordonnancement se
propagent aux appelants, et il faut les relire ensemble.

---

## IT-02 — La réserve, le report et les alertes

**Livré.**

- `Core\Mail\Transport\MailReserve` et `Reserve` (D14) : pointe
  quotidienne hors publipostage sur trente jours, marge de vingt,
  plancher de trente, plafond à la moitié du quota, et application au
  seul fournisseur partagé entre la voie « Masse » et une autre.
  `Reserve::provenance()` rend la phrase que l'écran imprime.
- `MailFailure`, `ProviderHealth`, `ProviderHealthRepository` et la table
  `mail_provider_health` (D15) : classification de l'erreur, trois échecs
  consécutifs, verrou de cinq minutes doublant jusqu'à quatre heures,
  refermeture au premier succès. `MailTransportChain::withoutOpenCircuits()`
  garantit qu'une voie n'est jamais vidée.
- `DeferredMessage`, `DeferredMailRepository`, `DeferredMailQueue`,
  `LaneExhaustedException`, la table `mail_deferred_messages` et
  `Task\DrainDeferredMailHandler` (D9, D16, D17, D18).
- `Core\Alert\Check\AuthenticationLaneCheck` et
  `DeferredMailBacklogCheck`, tous deux dans
  `OperationalAlertService::MAIL_KEYS`.
- Deux réglages scalaires dans la page Paramètres générique : durée de
  vie d'un message en file, rétention des abandonnés.
- L'écran : la réserve avec sa provenance sous la voie
  « Authentification », son rappel sur la fiche du fournisseur, l'état du
  coupe-circuit, le compteur de la file et le formulaire de relance.
- `OutboundMailCollector` gagne trois sections ; `mail_lane_exhausted`
  rejoint le journal, en `security` sur l'authentification.

### Décisions prises seul

**Le coupe-circuit n'est pas consulté par l'alerte de la voie
d'authentification.** La règle impérative de D15 — la chaîne essaie sa
dernière entrée même circuit ouvert — rend les deux lectures
incompatibles : compter un circuit ouvert comme un fournisseur manquant
donnerait l'alarme sur une voie qui fonctionne encore, et le ferait
pendant exactement la panne que le coupe-circuit traverse. PHPStan a
d'ailleurs signalé la dépendance comme jamais lue, ce qui était la même
observation par un autre chemin.

**`open_count` n'est jamais remis à zéro.** D15 demande la remise à zéro
« au premier succès » ; c'est le compteur d'échecs consécutifs qui l'est.
Le nombre d'ouvertures, lui, est la mémoire de la fréquence des rechutes,
et c'est lui qui allonge le verrou suivant : le remettre à zéro donnerait
à un relais qui tombe toutes les dix minutes le même verrou de cinq
minutes pour toujours.

**Une erreur inconnue est celle du fournisseur.** Le sens de ce défaut a
été choisi par le coût de l'erreur : une panne réelle prise pour un refus
de destinataire laisse un relais mort réessayé quatre cents fois par un
publipostage ; l'inverse coûte à un fournisseur quelques minutes hors
d'une chaîne qui, par construction, ne se vide jamais.

**Le compte par âge ne déchiffre plus rien.** `abandonedByAge()` lisait
les messages entiers pour regarder une date. La date de mise en file est
en clair une colonne à côté : `abandonedCreatedAt()` et `abandonedIds()`
la lisent seules, et le corps ne quitte plus la base pour compter ou
relancer (D18). La relance est un `UPDATE` sur une clé primaire ; rien
n'obligeait des centaines d'e-mails à passer par la mémoire pour cela.

**Le formulaire de relance est une carte, pas une boîte de dialogue.** Le
document dit « boîte de dialogue » ; ce qu'elle devait contenir tient en
trois lignes — la répartition par âge et deux choix — et un dialogue
qu'il faut ouvrir pour savoir s'il valait la peine d'être ouvert n'est
ouvert par personne. La fenêtre par défaut la plus courte, elle, est
respectée à la lettre : c'est la sûreté du bouton.

**La voie d'authentification est refusée côté serveur à la relance**,
bien que l'écran n'en propose pas la case : elle n'a jamais rien mis en
file, donc un formulaire qui la nomme ne vient pas de l'écran.

### Écarts entre le document et le dépôt

**`MailService` ne recevait la file de personne.** La file, sa tâche de
purge et ses réglages étaient en place et testés, mais
`MailServiceFactory::create()` ne prenait pas de file : en production
`MailService::$deferred` restait nul et rien n'aurait jamais été différé.
Corrigé dans les deux racines de composition — `public/index.php` et
`public/cron.php` — parce qu'un message différé d'un côté et perdu de
l'autre serait pire que pas de file du tout. À retenir pour la suite du
chantier : une fonctionnalité entièrement testée peut n'être branchée
nulle part, et aucun test unitaire ne le dit.

**`{% for x in y if z %}` n'existe plus en Twig 3.** Remplacé par
`|filter`. Trouvé par les tests de contrôleur, pas par PHPStan.

**Le nom de la table du journal est `event_log`**, pas
`journal_entries` — le service s'appelle `JournalService`, la table non.

**Le sujet d'aide a dû être coupé en deux.** Les ajouts d'IT-02 portaient
`courrier-sortant.md` à 962 mots et sept questions ; la charte en autorise
400 et quatre, et `HelpInvariantsTest` les compte. « Quand le courrier ne
part plus » (`courrier-sortant-pannes`) prend la réserve, le
coupe-circuit, les messages différés et leur relance ; l'original garde
les fournisseurs, les voies et la cadence. La coupure suit la charte, mais
elle suit aussi l'usage : on vient sur l'une pour configurer, sur l'autre
parce que quelque chose ne marche pas.

**Sonar a trouvé le trou que la suite verte cachait.** 79,5 % de
couverture sur le code neuf pour 80 % exigés, et les deux manques étaient
les deux endroits qui comptent le plus : `Task\DrainDeferredMailHandler`
n'avait aucun test — la passe qui envoie réellement ce qui a été mis de
côté — et le `catch (LaneExhaustedException)` de `MailService`, l'entrée
même de la fonctionnalité, non plus. Seize tests ajoutés, dont ceux qui
vérifient qu'une pièce jointe remise sur le disque n'y reste pas, échec
compris.

En les écrivant, deux méthodes se sont révélées mortes :
`DeferredMessage::ageHours()` et `DeferredMailRepository::abandoned()`,
toutes deux remplacées par les lectures qui ne déchiffrent rien. La
seconde méritait de partir pour elle-même : rien sur le chemin de la
relance ne lit le contenu d'un message abandonné, et laisser une méthode
qui le ferait est une invitation.

### La relecture, et la leçon qui revient

**Les deux relecteurs ont trouvé indépendamment la même chose, et c'était
la bonne.** `MailTransportFactory::build()` — le seul endroit où la chaîne
est construite en production — n'avait pas été mis à jour : `$health` et
`$reserve` sont optionnels sur le constructeur pour qu'un test puisse s'en
passer, et c'est exactement par là qu'ils étaient absents partout où cela
comptait. L'écran affichait une réserve que rien ne retranchait et un état
de coupe-circuit que rien n'écrivait ; `MailTransportChainTest` restait
vert parce qu'il construit la chaîne lui-même.

**C'est la troisième fois dans cette itération.** La file non branchée
dans `MailServiceFactory`, puis celle-ci. La leçon est la même et mérite
d'être écrite une fois pour toutes : *une dépendance optionnelle ajoutée à
une classe est une dépendance absente de la racine de composition tant
qu'un test ne dit pas le contraire*. `MailTransportFactoryTest` affirme
désormais les deux propriétés — sur le câblage, pas sur le comportement,
parce que c'est le câblage qui a cassé.

**Le rejeu pouvait rendre un message immortel.** `DrainDeferredMailHandler`
appelait `MailService::send()` sur l'instance du `TaskContext`, laquelle
porte une file. Une voie toujours épuisée au moment du réessai attrapait
donc sa propre `LaneExhaustedException`, écrivait une NOUVELLE ligne avec
`attempts` à zéro et une échéance fraîche, et rendait la main sans
exception — la passe supprimait l'originale et comptait un envoi qui
n'avait pas eu lieu. Le palier de réessai, l'échéance fixe et l'état
« abandonné » devenaient tous inatteignables. `withoutDeferral()` rend un
clone sans file : la passe EST la file, elle ne peut pas différer.

**Une pièce jointe réelle n'est pas de l'UTF-8 valide.** `json_encode()`
refuse ce qui ne l'est pas et rend `false` ; casté en chaîne, cela donnait
`''`, chiffré et stocké sans un mot — une ligne qui affirmait qu'un
message attendait, et dont le contenu avait disparu, alors que
l'expéditeur avait vu « envoyé ». Le test qui prétendait couvrir le cas
utilisait `"%PDF-1.4\x00binary"`, qui est de l'UTF-8 valide par accident.
Les octets passent désormais en base64, `JSON_THROW_ON_ERROR` rend le
reste bruyant, et le test utilise de vrais octets de JPEG.

Reste, du même lot : `554 5.7.1` n'est plus classé comme un refus de
destinataire (RFC 3463 en fait un refus de politique, et « Relay access
denied » porte le même code) ; `mail_provider_circuit_opened` n'est plus
écrit à chaque échec d'un circuit déjà ouvert ; `settleFailure()` garde la
raison réelle au lieu de « nouvel échec » ; le palier de réessai lit le
compte d'après l'échec et non d'avant ; la fenêtre de relance par défaut
est bien la plus courte ; la route de relance rejoint le fournisseur RBAC ;
`LIMIT` est lié plutôt que concaténé ; les deux réglages passent en
`number` avec une expression régulière, `SettingService` n'ayant pas de
cas `integer` ; et une pièce jointe illisible fait échouer le report au
lieu de mettre en file un message amputé.

**Deux trouvailles que seule une relecture du schéma pouvait donner.** La
colonne était un `BLOB` — 65 535 octets — alors que la file accepte 2 Mio
de pièces jointes, que le base64 porte à environ 2,7 Mio. En mode SQL
strict l'insertion aurait échoué et le message aurait été perdu par le
mécanisme censé le garder ; en mode permissif la ligne aurait été
tronquée, son sceau d'authentification n'aurait plus jamais vérifié, et la
vidange aurait buté dessus. `MEDIUMBLOB`. Le harnais SQLite déclare cette
colonne en `TEXT`, donc aucune exécution locale ne pouvait le montrer.

Et `RgpdContentService` n'avait pas été mis à jour, ce qu'`AGENTS.md`
qualifie de PR incomplète : la file garde une adresse, un objet, un corps
et des pièces jointes, avec deux durées de conservation réglables. Un
paragraphe 4octies le dit, en insistant sur ce que cette file n'est pas —
un archivage des e-mails envoyés.

Enfin, une ligne indéchiffrable ne bloque plus la file : `due()` l'abandonne
au lieu de la laisser en tête de tri à chaque passe, ce qui aurait arrêté
la vidange et la purge pour de bon.

**Le deuxième tour a trouvé une fenêtre qui ne pouvait rien attraper.** La
relance filtrait sur `created_at`, la date de mise en file. Or un message
n'est abandonné qu'une fois son échéance passée : avec la durée de vie par
défaut, vingt heures après sa mise en file au plus tôt. La fenêtre de six
heures — celle que la boîte propose par défaut — ne pouvait donc
structurellement rien contenir, et le bouton aurait relancé zéro message à
chaque fois. Les deux tranches d'âge récentes auraient été vides en
permanence pour la même raison. Tout passe sur `settled_at` : l'âge qui
veut dire quelque chose est celui de l'échec, pas celui du message.

Mon test le cachait — il réécrivait `created_at` après coup, ce que la
production ne fait jamais. Le fabricant de messages abandonnés place
désormais les deux horodatages à un jour d'écart, comme une vraie ligne :
une tranche ou une fenêtre qui lirait la mauvaise colonne échoue.

**Un « 550 » n'est pas une route coupée.** Une voie s'épuise quand sa
dernière entrée échoue, et sur l'installation ordinaire — un fournisseur
actif par voie, ce que pose le seeder — cette dernière entrée est aussi la
première. Un seul refus de destinataire vidait donc la voie exactement
comme une panne, et le message partait pour vingt-quatre heures de
réessais contre un relais qui répondra 550 à chaque fois, pendant que
l'expéditeur, qui aurait pu corriger l'adresse, avait vu « envoyé ». La
distinction que le coupe-circuit tirait déjà (`MailFailure`) sert
maintenant aussi à la file.

Et `hydrate()` ne fabrique plus un message vide quand la charge utile
déchiffrée n'est pas un appel rejouable : elle lève, et `due()` en fait
une ligne abandonnée comme n'importe quelle autre — la correction
précédente du `?? []` supprimé pour satisfaire PHPStan avait laissé un
`foreach(null)` à la place.

**Et `ProviderHealthRepository::forget()` n'était appelé nulle part.**
`TransportService::deleteProvider()` nettoie soigneusement les trois
autres réserves par fournisseur — secrets, entrées de voies, compteurs —
mais la table de santé n'a pas de clé étrangère (volontairement : l'envoi
local est le fournisseur 0 et n'a pas de ligne dans `mail_providers`),
donc rien ne l'effaçait. La ligne survivait au relais qu'elle décrit,
gardait sa dernière raison SMTP en base et dans toutes les archives de
support suivantes, et aurait transmis son `open_count` — voire un verrou
non expiré — à qui aurait repris cet identifiant.

### Reporté

Rien de fonctionnel. La cadence « collante » après bascule, refusée en
IT-01 faute de coupe-circuit, est désormais possible : `ProviderHealth`
existe. Elle n'est pas faite ici parce qu'elle appartient à `BulkCadence`
et qu'aucune décision de ce document ne la demande — elle sera proposée
quand une itération touchera la cadence.
