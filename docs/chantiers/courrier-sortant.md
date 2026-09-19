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

Et la documentation RGPD n'avait pas été mise à jour, ce qu'`AGENTS.md`
qualifie de PR incomplète : la file garde une adresse, un objet, un corps
et des pièces jointes, avec deux durées de conservation réglables. Un
paragraphe 4octies le dit, en insistant sur ce que cette file n'est pas —
un archivage des e-mails envoyés.

**Le premier correctif ne touchait que la moitié du sujet**, et la
relecture suivante l'a vu. `RgpdContentService.php` contient deux choses :
les règles données au générateur d'IA, et `getDefaultContent()`, qui lit
`core/View/rgpd_default.html` — la page réellement servie. Avoir instruit
le générateur ne disait rien aux lecteurs de la page par défaut. Une
section 2.6 et une ligne de conservation y sont ajoutées, et les sections
suivantes renumérotées de part et d'autre.

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

**Un commentaire qui affirmait ce que le code ne faisait pas.** Le
docblock de `MailFailure::classify()` expliquait longuement pourquoi
`5.7.1` était tenu hors de la liste des refus de destinataire — c'est un
refus de politique, et « Relay access denied » porte le même code. Sauf
que `'550'` était dans la liste et que la recherche est un `str_contains`
dans l'ordre de déclaration : « 550 5.7.1 Relay access denied » trouvait
`550` au premier tour et repartait en `Recipient`. L'exclusion réfléchie
n'avait jamais eu l'occasion de s'appliquer, et depuis que
`MailService` refuse de différer un refus de destinataire, un relais
répondant cela à tout aurait vu ses messages jetés au lieu d'être mis en
file, sans que son circuit ne s'ouvre jamais. Les codes de politique sont
maintenant lus en premier.

À retenir : un commentaire qui explique une décision n'est pas une preuve
qu'elle est appliquée — ici les deux se contredisaient depuis le début, et
seule une relecture ligne à ligne pouvait le voir.

**Et les tranches d'âge étaient disjointes sous un libellé cumulatif.**
`abandonedByAge()` compte « moins de 6 h », « de 6 à 24 h », etc., mais
l'écran écrivait « de moins de 24 h » pour la deuxième. Trente messages
abandonnés il y a deux heures donnaient donc « 30 de moins de 6 h, 0 de
moins de 24 h » — à côté d'une fenêtre de relance « Les 24 dernières
heures » qui, elle, les prend bien tous. Les libellés nomment désormais
les bandes.

**La même distinction manquait au rejeu.** Le garde-fou qui refuse de
différer un refus de destinataire s'exécute à la mise en file ; or les
deux échecs ne sont pas nécessairement le même. Un message mis de côté
pendant une panne est rejoué quand le relais revient — et si l'adresse
était fausse aussi, c'est là que le 550 se fait entendre pour la première
fois. `settleFailure()` reprenait alors l'échelle depuis le début et
dépensait huit tentatives de plus, sur une journée, à réapprendre ce que
le relais avait déjà dit clairement. Il classe désormais la raison lui
aussi.

**Et la même règle manquait à la remise sur disque.** `materialise()`
passait silencieusement une pièce jointe qu'il ne pouvait pas écrire :
le message partait sans elle, était compté comme envoyé, et la ligne —
seule copie restante du reçu — était supprimée. C'est exactement ce que
`payloadFor()` refuse à la mise en file, réintroduit à la sortie. Un
disque plein est une raison de réessayer plus tard, pas de livrer un
message amputé : la méthode lève désormais.

Cette branche n'a pas de test, et pas de faux test non plus :
`sys_get_temp_dir()` est résolu une fois par processus, donc aucun test ne
peut le pointer vers un endroit non inscriptible après qu'un autre y a
touché. La lacune est écrite à côté du test voisin plutôt que masquée par
une simulation qui n'en serait pas une.

**Le même défaut, une couche plus bas, et cette fois le commentaire se
défaussait.** `hydrate()` passait une pièce jointe dont le base64 ne se
décode pas, en écrivant que « la vidange décidera ». Or la vidange
n'inspecte rien : elle écrit sur disque ce qu'on lui donne. L'entrée
gardait donc son texte base64, livré au destinataire sous le nom d'origine
— un « recu.pdf » plein d'ASCII —, et la ligne était supprimée comme un
succès propre. Elle lève désormais, et `due()` l'abandonne.

À retenir, deuxième fois aujourd'hui : **un commentaire qui délègue une
décision à un autre code doit être vérifié contre ce code**. Ici comme
pour `5.7.1`, les deux se contredisaient et seule une relecture ligne à
ligne pouvait le voir.

### Reporté

Rien de fonctionnel. La cadence « collante » après bascule, refusée en
IT-01 faute de coupe-circuit, est désormais possible : `ProviderHealth`
existe. Elle n'est pas faite ici parce qu'elle appartient à `BulkCadence`
et qu'aucune décision de ce document ne la demande — elle sera proposée
quand une itération touchera la cadence.

---

## IT-03 — Adresses, DNS, tableau de bord

**Livré.**

- `Core\Mail\MailIdentity` : l'autorité unique sur quelle adresse joue
  quel rôle, et sur le domaine que le SPF regarde.
- Un réglage de plus, `mail_reply_address` (facultatif), porté jusqu'à
  `MailService` : un message qui ne nomme pas sa propre adresse de
  réponse prend celle du site.
- La sous-page **Authentification** (`/config/courrier-sortant/
  authentification`) : les quatre adresses éditables, le tableau des
  quatre rôles, la vérification DNS et l'aller-retour.
- Le **tableau de bord** à la racine de la section ; les fournisseurs
  passent sous `/fournisseurs`. Trois lignes essentielles, les options
  avancées listées dessous avec leur état, et la phrase sur les
  indésirables en bloc à part.
- `DnsVerifier::checkSpfForHosts()` : le SPF doit autoriser *tous* les
  relais actifs, pas seulement le premier.
- `Core\Mail\Feedback` — `ReturnPathVerifier`, `ReturnProbe`,
  `ReturnProbeRepository`, `ReturnState`, `ReturnPathConsumer` — et la
  table `mail_return_probes` : l'aller-retour réel, avec ses trois états
  et sa dépendance nullable au module « Courrier entrant » (D2).
- L'assistant d'installation cesse d'éditer l'identité de courrier après
  la première passe et pointe vers la sous-page ; le panneau DNS n'y est
  plus rendu.
- Un sujet d'aide de plus, `courrier-sortant-authentification`.

### Décisions prises seul

**La vérification DNS est un POST suivi d'une redirection, pas un
chargement de page.** L'assistant la faisait en AJAX ; ici elle est une
action explicite, sur le même patron que `/config/maintenance/update/
check-now` — elle sort sur le réseau *et* écrit ce qui revient, ce qui
n'a sa place ni l'un ni l'autre sur un GET. Et elle n'est pas lancée à
l'ouverture : un `dns_get_record()` sur un résolveur qui ne répond pas
prend le temps qu'il prend, et cette page est précisément celle qu'on
ouvre quand le courrier ne part déjà plus.

Un premier jet l'avait faite en `GET ?dns=1`, en se disant qu'un GET qui
ne fait que mettre en cache une lecture est inoffensif. Il l'est ; mais le
dépôt avait déjà tranché la question ailleurs, et une deuxième réponse à
la même question est une divergence, pas une nuance.

**Ce que la vérification a vu est retenu en entier**, valeurs suggérées
comprises, et pas seulement en trois booléens. Deux raisons, dont la
seconde n'est apparue qu'en écrivant la redirection. La première : sans
mémoire, le tableau de bord n'a que deux options, mentir (« tout va
bien ») ou interroger le DNS à chaque affichage ; l'état **daté** est la
seule forme honnête, « au 12/09, le SPF ne figurait pas » se vérifiant là
où « le SPF ne figure pas » ne se vérifie pas. La seconde : les
enregistrements proposés sont ce que la personne est *en train de
recopier* chez son registraire, et les perdre au rechargement suivant
serait les perdre au milieu de la copie.

**Le SPF est vérifié contre toute la chaîne.** Le roadmap ne le demande
pas, mais déménager la vérification à côté des fournisseurs sans le faire
aurait produit un écran faux dès le premier jour : `DnsVerifier` ne
connaissait que le `smtp_host` historique, et IT-01 a donné au site une
liste ordonnée de relais. Un SPF qui ne nomme que le premier fait échouer
exactement les messages que le repli devait sauver.

**L'aller-retour porte sur l'expédition et la réponse, pas sur les
rapports DMARC.** Personne n'écrit jamais à l'adresse des rapports : ce
qui y arrive est un rapport machine, et IT-06 répondra « les rapports
arrivent-ils » en regardant les rapports eux-mêmes plutôt qu'en écrivant
à la boîte.

**Le consommateur du cœur ne revendique rien.** Un message qu'il
reconnaît est consigné puis rendu avec `AnalysisResult::nothing()` :
aucune liste de tri ne gagne une ligne pour un message que le site s'est
envoyé à lui-même, et la rétention ordinaire du courrier non rattaché
l'emporte. L'autre voie — le rattacher pour que
`Api\MessageRetentionPreference` puisse en jeter le corps — n'achetait
rien : ce corps est une phrase française que ce site a écrite, sans
aucune donnée personnelle à protéger.

**Le sélecteur DKIM suit les adresses ; la clé DKIM reste dans
l'assistant.** La sous-page affiche la clé publique et ne la régénère
pas : une seule page reste capable de le faire, et c'est la règle qu'on
voulait.

### Écarts entre le document et le dépôt

**Le piège SPF était déjà évité, et par accident.** Le roadmap demande de
vérifier que `checkSpf()` regarde le bon domaine. Il le regarde :
l'assistant dérive le domaine de `mail_from_address`, et `MailService`
force `$mail->Sender = $this->fromAddress` — donc l'enveloppe et le
`From:` portent toujours le même domaine. Mais **rien ne le disait et
rien ne le testait**, et la sous-page ajoutait justement deux adresses
(réponse, rapports DMARC) qu'il aurait été naturel de prendre pour le
domaine SPF. `MailIdentity::spfDomain()` nomme la règle et
`MailIdentityTest` l'épingle contre le vrai `MailService`, override de
`From` compris.

**Un vrai défaut voisin, trouvé en chemin, et non traité ici.** Avec un
`fromAddressOverride` sur un autre domaine — ce que le sectionnement
d'expéditeur du publipostage permet — le `From:` et l'enveloppe divergent,
et la signature DKIM porte toujours le domaine du site : DMARC échoue
alors des deux côtés, alignement SPF comme alignement DKIM. Ce n'est pas
le piège que ce document nomme, et le corriger suppose de décider ce
qu'un envoi « au nom de » doit faire — ce qui appartient à l'itération qui
touchera le publipostage. Noté ici pour ne pas être redécouvert.

**`mode=smtp` avec un hôte vide donnait un feu vert.** L'ancien
`checkSpf()` cherchait la chaîne `a:` dans l'enregistrement dès que
`$smtpHost` n'était pas `null` — or `SetupController` lui passe `''`
quand le champ est vide, jamais `null`. N'importe quel enregistrement
portant un seul mécanisme `a:` satisfaisait donc la recherche, sur un
hôte que personne n'avait nommé. Corrigé par la même passe qui introduit
la liste.

### La relecture, et ce qu'elle a coûté

**Un paramètre inséré au milieu d'un constructeur n'est pas un ajout.**
`replyAddress` se lit le mieux à côté des autres adresses de
`MailService` ; il y a été mis, et plusieurs appelants positionnels ont
aussitôt donné un entier à `$smtpHost`. Il est maintenant le dernier
paramètre, et le docblock dit pourquoi cette place-là — sinon la
prochaine relecture le déplacera pour la même raison de lisibilité.

**Un réglage que la page enregistre doit être déclaré au démarrage.**
`SettingService::set()` refuse une clé que rien n'a enregistrée, et le
test de la page construit ses propres enregistrements — il ne pouvait
donc pas voir l'oubli. `testEveryAddressThePageSavesIsDeclaredAtBoot()`
lit `public/index.php` plutôt que sa propre liste. C'est la même leçon
qu'en IT-02 sous un autre angle : **ce qu'un test construit lui-même, il
ne le vérifie pas**.

**Le consommateur du cœur devait être inscrit dans deux registres, pas
un.** Celui du planificateur est celui qui appelle `analyze()` ; celui de
`public/index.php` est celui que l'écran de configuration des boîtes lit
pour savoir à qui une boîte peut être ouverte. Inscrit dans le seul
premier, la vérification aurait été impossible à activer — et l'écran
n'aurait proposé aucune case à cocher pour dire pourquoi.

**L'ordre de construction a déplacé l'enregistrement du contrôleur.**
`ReturnPathVerifier` a besoin de la passerelle `inbound_mail`, qui
n'existe qu'après le bloc du module ; `OutboundMailController` était
construit bien avant. Il est maintenant enregistré après ce bloc, avec le
commentaire qui dit que c'est un fait d'ordonnancement et non une
dépendance du cœur au module — l'autre solution, une fermeture paresseuse,
aurait caché ce fait derrière de l'indirection.

**Une date lue avec le constructeur nu répond *maintenant*.** Le cliquet
`StoredDateReadingRatchetTest` a attrapé quatre lectures, dont celle de
la mémoire DNS : une valeur tronquée aurait daté d'un coup une
vérification qui n'a jamais eu lieu.

**Une sonde ne passe jamais par la file de report.** `MailService` sait
différer un message quand toute une voie est épuisée (D9) — et une sonde
différée n'a rien dit à personne : elle lirait « en attente », puis
« jamais arrivé » quelques heures plus tard, et enverrait le lecteur
chercher du côté du chemin de retour quand le problème était le transport.
Elle part par le clone sans file qu'IT-02 avait introduit pour la vidange
(`withoutDeferral()`). Refusé maintenant est une réponse utilisable ; mis
en file maintenant n'en est pas une.

### Les deux exigences transverses

**Le paquet de support** gagne deux sections : « Authentification du
domaine » — les deux domaines, leur alignement, le sélecteur, et les
verdicts DNS **retenus avec leur date** — et « Vérification des retours »,
par rôle. Rien n'y porte d'adresse : un nom de domaine est un serveur, une
adresse est une personne, et ce fichier part chez un tiers. Le collecteur
reçoit le *dépôt* des sondes et non le vérificateur, délibérément : un
paquet de support ne doit jamais pouvoir *envoyer* une sonde pendant qu'on
l'assemble, et un collecteur qui ne peut pas atteindre `launch()` ne se
laissera pas convaincre de le faire par une modification ultérieure.

**Le journal** gagne `mail_identity_changed`, en `security` : changer d'où
part le courrier du site est une décision de sécurité même prise de bonne
foi — une adresse d'expédition qui pointe ailleurs, ce sont tous les liens
de connexion qui pointent ailleurs. L'entrée nomme **les rôles modifiés,
jamais la valeur**, comme `member_email_added` ne porte que `member_id` ;
et une page enregistrée deux fois n'écrit rien la seconde fois, parce
qu'une décision n'a eu lieu qu'une fois. `mail_return_probe_sent` et
`mail_return_probe_received` complètent la ligne « Vérification des
retours : résultat » du tableau.

**Et la leçon d'IT-02 a été appliquée avant de se répéter.** Trois
dépendances de cette itération ont exactement la forme qui avait produit
deux mécanismes morts en production : une passerelle nullable, une clé de
réglage fusionnée à la main dans `$secrets` à chaque point d'entrée, deux
arguments de collecteur. Toutes les trois échouent en silence — la plus
discrète étant la passerelle : avec `null`, chaque état lit « vérification
impossible », **ce qui est aussi la réponse honnête** sur une installation
sans le module, donc l'écran aurait eu l'air juste partout et aurait été
faux là où le module est actif. `OutboundMailWiringTest` lit les racines
de composition et épingle les trois ; sa première assertion a été vérifiée
en cassant délibérément le câblage, parce qu'un test de câblage qui ne
tombe pas est un test qui ne sert à rien.

Un troisième nettoyage est venu de là : la mémoire DNS était analysée dans
le contrôleur **et** dans le collecteur, deux fois le même `json_decode`
et les mêmes trois booléens à trois états. `Core\Mail\DnsCheckMemory` la
porte maintenant seule — et au passage le collecteur cesse d'aller
chercher une constante sur un contrôleur pour lire un réglage, ce qui
était deux couches de travers pour un seul blob JSON.

### Ce que la relecture Claude a trouvé, et que rien d'autre n'aurait vu

**La vérification s'autorisait des boîtes qui ne lui étaient pas
ouvertes.** `isPossible()` comptait les boîtes *activées*
(`listMailboxSummaries()`), là où ce qui compte est les boîtes ouvertes à
*ce consommateur* (`probeAddressesFor()`). Une portée est `inert` tant que
le super-admin n'a rien ouvert : sur une installation neuve avec le module
actif, la sonde partait, n'était jamais proposée à `ReturnPathConsumer`,
et lisait « jamais arrivé » six heures plus tard — **la fausse alerte
exacte que l'aller-retour existe pour éviter, produite par
l'aller-retour**. Le docblock de `probeAddressesFor()` donne d'ailleurs
cette raison mot pour mot ; je ne l'avais pas lue.

Et la branche qui aurait dit quoi faire, `isCollectingWithoutScope()`,
était **démontrablement inatteignable** : elle posait la même question que
`isCollecting()`. La seule phrase actionnable de l'écran ne s'affichait
jamais.

**Mes doubles de test cachaient le défaut, et pas par hasard.** Ils
répondaient « je relève, et je n'ai aucune boîte » — une combinaison que
le vrai service ne peut pas produire, `isCollecting()` comptant
précisément les boîtes que les résumés listent. Leçon du jour, à ranger à
côté des deux autres : **un double qui ne peut pas exister en production
est un double qui cache le défaut qu'il était censé couvrir.**

**L'adresse de réponse détournait le courrier des sections.** Le
publipostage envoie avec `replyTo = null` et un `fromAddressOverride` —
l'adresse de la section. Avant, pas de `Reply-To`, donc « Répondre »
arrivait à la section. Le jour où quelqu'un remplit le nouveau champ sur
une page qui ne parle pas du publipostage, toutes les réponses des
sections partaient vers l'adresse du site. Une régression dans un module
que cette PR ne touche pas, déclenchée par un réglage d'ailleurs. La
réponse du site répond pour le `From` du site, et pour lui seul.

**Et cacher les champs a cassé deux boutons de l'assistant.** « Envoyer un
test » lisait `.value` sur des `<input>` que je venais de retirer : un
`TypeError` avant `fetch()`, le spinner qui tourne indéfiniment. La
génération de clé DKIM avait le même défaut, avalé par son propre
`.catch()` et affiché en « Erreur réseau » sur une requête qui avait
pourtant réussi. `npm run typecheck` ne peut rien y voir —
`strictNullChecks` est désactivé et le `@type` en JSDoc affirme que
l'élément existe. Trois specs Vitest le couvrent, vérifiées en cassant le
correctif.

Une subtilité au passage : un champ **absent** et un champ **vide** sont
deux réponses différentes côté serveur. `mailSecretsUnderTest()` reprend
la valeur stockée pour une clé que la requête ne porte pas, et prend un
`''` au pied de la lettre. Envoyer une chaîne vide aurait testé l'envoi
sans adresse d'expédition — ce que PHPMailer refuse net.

**Et un rapport *sur* la sonde comptait comme la sonde.** `claim()`
reconnaissait un retour à la seule présence de la clé dans le sujet. Or
une notification de non-remise cite le sujet du message qu'elle n'a pas pu
livrer — « Undeliverable: Vérification des retours RET-… » — et elle part
vers l'expéditeur d'enveloppe, qui sur ce site est toujours l'adresse
d'expédition, donc très souvent une boîte relevée. Adresse de réponse
inexistante ⇒ le rebond revient dans la boîte surveillée ⇒ l'adresse morte
était marquée **« vérifié »**. Le cas d'échec que tout l'aller-retour
existe pour détecter, annoncé comme un succès.

Deux gardes. Le message doit **nommer l'adresse sondée parmi ses
destinataires** — un alias réécrit le destinataire d'enveloppe, jamais
l'en-tête `To:`, donc une sonde délivrée dans une boîte portant un autre
nom porte toujours l'adresse à laquelle elle a été envoyée, ce qui est
précisément le cas que l'aller-retour sert. Et un message venant de
`mailer-daemon` ou `postmaster` est refusé, pour le relais qui met le
destinataire en échec dans le `To:` de sa propre notification.

Volontairement étroit : reconnaître un rebond pour de bon suppose de lire
un `multipart/report` et ses codes d'état, ce qui est le sujet d'IT-05. Ce
qu'il faut ici est seulement « ceci n'est pas mon message qui revient ».

**Une deuxième relecture, et deux fois le même genre de défaut : un état
manquant traité comme un état sain.**

Le tableau de bord appelait « Authentification du domaine » verte sans
aucune clé DKIM. La ligne ne retenait que les lectures explicitement
fausses (`array_filter(..., fn ($v) => $v === false)`), or
`DnsCheckMemory::state()` répond `null` — et non `false` — pour « aucune
clé n'a été générée », parce que ne rien publier n'est pas publier quelque
chose de faux. SPF publié plus aucune clé tombait donc dans le cas « ok »,
avec « 1 enregistrement en place », pendant que la sous-page
Authentification affichait « Clé DKIM requise » **pour la même lecture
stockée**. Deux écrans, une source, deux verdicts opposés : celui qu'on
lit en premier est celui qui rassure. Sans clé, le site ne signe rien du
tout et une bonne part des destinataires classera ses messages en
indésirables — la ligne dit maintenant `missing`.

Et un envoi qui échoue effaçait ce qu'on savait déjà.
`ReturnProbeRepository::issue()` supprime la ligne existante avant
d'insérer la nouvelle — une ligne par adresse, c'est voulu. Elle était
appelée avant `send()` : un relais qui hoquette, et une adresse
« vérifiée » il y a une heure repartait à zéro, sans rien pour la
restaurer. L'ordre est désormais l'inverse, et il est porteur **dans les
deux sens** : écrire après l'envoi ouvre en théorie la course inverse (un
retour réclamé avant que sa ligne existe), mais cette course-là passe par
une remise SMTP et un relevé de boîte, quand la fenêtre ouverte ici se
compte en microsecondes entre le retour de `send()` et l'instruction
suivante. Une perte certaine échangée contre une perte impossible.

Les deux correctifs sont épinglés par un test vérifié en le cassant.

**Et une troisième passe, qui a trouvé le défaut le plus coûteux des
trois : un relevé DNS qui survit à ce qu'il décrit.**

Un relevé porte sur **un domaine et un sélecteur**, pas sur « le site ».
Vérifiez `ancien.be`, obtenez un vert, changez l'adresse d'expédition pour
`nouveau.be` — et le tableau de bord continuait d'afficher la coche verte
et ses « N enregistrements en place » pour une zone que personne n'a
jamais interrogée. La sous-page proposait en prime les enregistrements de
l'ancien domaine, ceux-là mêmes qu'on recopie dans le formulaire d'un
registrar.

Le correctif ne vide pas la mémoire au moment d'enregistrer, alors que
c'était la forme la plus courte. Un relevé jeté à l'enregistrement est un
relevé perdu pour de bon — y compris les valeurs que quelqu'un était en
train de recopier — et l'adresse peut bouger sans passer par ce
formulaire, l'assistant d'installation l'écrit aussi. `DnsCheckMemory`
sait désormais dire s'il **décrit encore** l'identité configurée
(`describes()`), et les trois lecteurs — tableau de bord, sous-page
Authentification, paquet de support — passent par là. Une règle de
péremption qui vit dans un seul appelant est une règle que les deux autres
n'ont pas.

Le tableau de bord distingue « jamais vérifié » de « ce relevé ne dit plus
rien » : ce sont deux consignes différentes pour celui qui lit.

**Le SPF comparait des octets là où la RFC compare des mécanismes.** Les
noms de mécanismes et les domain-specs sont insensibles à la casse
(RFC 7208 §4.6.1), et `+a:hôte` **est** `a:hôte` — le `+` est le
qualificateur par défaut, et c'est la forme qu'écrivent les générateurs
cPanel/WHM, précisément l'hébergement mutualisé que visent les fixtures de
ce fichier. Un domaine correctement configuré était donc annoncé
« Manquant ou incomplet », et la valeur proposée ajoutait un `a:` en
double — un enregistrement qui marchait poussé d'une résolution DNS vers
la limite de dix de la §4.6.4. Les trois autres qualificateurs restent
distincts : `-a:hôte` dit le contraire de `a:hôte`, et les confondre
annoncerait comme autorisé un relais explicitement refusé.

**Du français dans une charge utile stockée.** `journalAddressChange()`
écrivait `'expédition'`, `'réponse'`, `'rapports DMARC'` dans le `context`
du journal. Le `context` est imprimé tel quel dans un bloc JSON : c'est de
la donnée, pas de l'interface, et AGENTS.md la veut en anglais pour la
même raison qu'il veut les noms de colonnes en anglais. `MailIdentity`
nommait déjà les quatre rôles ; un second vocabulaire, français, pour
trois d'entre eux était une seconde chose à tenir en phase.

**Et le DELETE + INSERT de `issue()` n'était pas une transaction**, sur une
colonne `UNIQUE`. Rien n'empêche de cliquer deux fois sur « Lancer la
vérification », et `public/index.php` relâche le verrou de session avant
le dispatch justement pour que deux requêtes d'un même navigateur ne se
mettent pas en file. Entrelacées : DELETE, DELETE, INSERT, INSERT, et le
second insert heurte la clé. Prises ensemble, elles ne peuvent plus
s'entrelacer. `verifyReturns()` attrape désormais ce qui remonte, comme
les autres écritures de ce contrôleur — une page de diagnostic qui répond
par l'écran d'erreur générique est une page de diagnostic qui a cessé de
diagnostiquer.

Un test par correctif, chacun vérifié en cassant le correctif. Au passage,
la fixture du collecteur n'enregistrait pas `dkim_selector` : elle
décrivait une installation qui ne peut pas exister — la leçon du double
impossible, une troisième fois.

**Une alarme qui ne pouvait pas sonner.** `isAligned()` promettait de dire
si le domaine du SPF et celui de la signature DKIM divergeaient. Or
`spfDomain()` vaut `domainOf(envelopeSender())`, `envelopeSender()` vaut
`fromAddress`, et `dkimDomain()` vaut `domainOf(fromAddress)` : la même
expression écrite deux fois. La méthode répondait donc « une adresse est
configurée » sous un nom qui promettait autre chose, l'avertissement
« Ces deux domaines diffèrent : DMARC échouera » de la sous-page ne
pouvait jamais s'afficher, et le paquet de support annonçait
`alignés : oui` sur toute installation ayant jamais existé.

Les trois disparaissent. Une alarme qui ne peut pas sonner est pire que
pas d'alarme : elle se lit comme une vérification que quelqu'un fait. Ce
que l'invariant dit vraiment est écrit là où il est vrai — le docblock de
`dkimDomain()` — et épinglé par un test qui compare les deux domaines sur
quatre identités, adresse de réponse et adresse DMARC chez d'autres
opérateurs comprises. Le seul cas qui les ferait diverger pour de bon est
l'envoi « au nom de » d'une section, déjà porté en « Reporté » : c'est
l'itération du publipostage qui donnera à ce contrôle quelque chose à
comparer.

**Et deux messages de commit rédigés en anglais**, alors qu'AGENTS.md
§ Langue est explicite : tout ce qui est écrit *à propos* d'un changement
est en français, y compris ce qui accompagne chaque enregistrement, sans
autre exception que la réponse sur un fil de relecture. Tout le reste de
cette PR est en français ; ces deux-là étaient les seuls écarts. La règle
est facile à énoncer et facile à rater dans le même fichier — le code et
ses commentaires en anglais, ce qui raconte le changement en français — et
la rater deux fois de suite après l'avoir tenue cinq fois est le genre de
dérive qu'aucun test ne rattrape.

**Et la même faute une fois de plus, à l'endroit le plus visible : « je
ne peux pas répondre » compté comme « tout va bien ».**

`checkSpfForHosts()` annonçait un SPF « en place » pour **n'importe quel**
`v=spf1` dès que la liste des relais était vide : la boucle qui aurait pu
l'infirmer ne tourne pas. Or la liste est vide sur toute installation sans
relais configuré — l'envoi local, qui est le réglage par défaut. Une unité
dont les boîtes sont chez un hébergeur et le site chez un autre publie
typiquement `v=spf1 include:spf.protection.outlook.com -all`, qui **fait
échouer durement** les messages partis du serveur web ; le tableau de bord
affichait une coche verte et « N enregistrements en place ».

Le dire pour de bon supposerait d'évaluer l'adresse du serveur contre
l'enregistrement entier, récursion des `include:` comprise — un évaluateur
SPF, pas cette classe. La réponse est donc `unverifiable`, troisième
manière de ne pas savoir, qui rejoint `key_missing` et `not_requested`
dans le `null` de `state()`. Sans aucun enregistrement, en revanche, la
réponse reste « absent » : rien n'autorise rien, et l'établir ne demande
aucune liste de relais.

Le corollaire était plus grave que le cas nominal. `sendingHosts()`
rattrape ses propres échecs et rendait `[]` — donc une table des
fournisseurs illisible produisait exactement la liste vide ci-dessus, et
la ligne SPF passait au vert **sur la foi d'une requête qui avait
échoué**. Un échec qui se lit comme un succès est pire qu'un échec. La
méthode rend maintenant `null` dans ce cas, et l'écran distingue les deux
phrases : « aucun relais n'est actif » et « la liste des relais n'a pas pu
être lue » n'envoient pas au même endroit.

Deux tests portaient le défaut dans leur nom — « an existing record is
enough » — et c'est le signe qui aurait dû alerter : un test qui affirme
qu'une absence de question vaut une réponse. Ils disent maintenant
l'inverse, avec la raison.

Au passage, le `tearDown()` du test du contrôleur ne nettoyait pas
récursivement : le premier test à générer une clé DKIM laissait son
répertoire temporaire derrière lui, et ne le disait que par un
avertissement PHP que personne ne lit.

**Et deux trous dans `describes()` lui-même** — la relecture est allée
regarder le correctif de la veille, ce qui était la bonne idée.

Un relevé porte aussi sur **l'adresse de rapport DMARC** : le verdict de
`checkDmarc()` est une fonction directe d'elle, puisqu'il cherche
littéralement `rua=mailto:{cette adresse}`. Changer l'adresse de A vers B
laissait donc « publié » affiché pour un enregistrement qui nomme A. Le
relevé garde maintenant une **empreinte** de l'adresse — la comparaison ne
demande que « pareil ou pas », et le blob porte déjà l'adresse une fois,
dans la valeur `expected` que l'opérateur recopie chez son registraire ;
en stocker une seconde copie aurait fait un deuxième endroit à tenir à
jour.

Et il porte sur **la clé DKIM**, que `describes()` ne peut pas voir :
le relevé contient la clé contre laquelle il a été pris, et rien en lui ne
peut nommer celle en service aujourd'hui. Seul le code qui change la clé
sait qu'elle a changé. Après une régénération, le `p=` publié contient
l'ancienne clé, donc **toutes** les signatures échouent — pendant que le
relevé annonçait encore `dkim.exists: true`, sur les trois écrans à la
fois puisqu'ils passent tous par la même porte.

C'est exactement la forme contre laquelle ce chantier s'était prémuni dans
`ReturnProbeRepository` : « une remise à zéro écrite comme une méthode est
une remise à zéro qu'on oublie d'appeler depuis le deuxième endroit ». Là
c'était évitable en indexant l'état sur l'adresse. Ici ça ne l'est pas — il
faut donc l'appeler, aux quatre endroits qui touchent la paire de clés, et
`DkimKeyChangeForgetsDnsTest` échoue si un cinquième apparaît sans
l'appel. La règle est **absolue**, sans liste d'exemptions à tenir en
phase : `forgetDnsReading()` avale son propre échec, donc les deux chemins
qui tournent pendant qu'on installe ou qu'on démonte peuvent l'appeler
aussi sûrement que les deux qui tournent sur un site vivant. Un `forget()`
et non un drapeau de péremption, parce qu'après un changement de clé
l'enregistrement publié n'est pas seulement invérifié, il est **faux**, et
la valeur à recopier est la nouvelle.

Un test à moi a d'ailleurs pris en défaut un commentaire à moi : j'avais
justifié l'empreinte par « le blob ne doit pas porter d'adresse », ce qui
est faux — il en porte une, nécessairement, dans `expected`. Le
commentaire dit maintenant la vraie raison.

**À l'œil du mainteneur, et volontairement pas ouvert en ticket public.**
`ConfigurationParametersCollector` verse dans le paquet de support la
valeur courante de **tous** les réglages, ne masquant que ceux de type
`secret` — dont il note lui-même qu'aucun n'existe encore. Les adresses de
configuration du site (`mail_from_address`, `dmarc_report_email`, et
désormais le relevé DNS qui reprend la seconde dans `expected`) y
figurent donc en clair, alors que `OutboundMailCollector` se donne
justement pour règle de n'en porter aucune. Ce n'est pas une régression de
cette itération — les deux réglages y étaient déjà — et ce sont des
adresses de service, pas de membres ; mais les deux collecteurs appliquent
deux règles opposées au même fichier, et cela mérite un arbitrage.

### Reporté

- L'alignement DMARC d'un envoi « au nom de » (ci-dessus), à l'itération
  qui touchera le publipostage.
- La régénération de la clé DKIM depuis la sous-page. Elle reste dans
  l'assistant : tant qu'un seul endroit peut le faire, la règle « pas de
  champ éditable aux deux endroits » tient, et rien dans ce document ne
  demande de la déplacer.

## IT-04 — La sonde manuelle

La seule page de ce chantier dont l'instrument est une personne. Aucun
site ne peut voir l'intérieur de la boîte de quelqu'un d'autre : la seule
façon de savoir où arrivent les messages est d'en envoyer un et d'aller
regarder. Tout le reste de l'écran découle de ça.

### Livré

`/config/courrier-sortant/sonde` envoie un message **bâti comme un vrai
envoi de l'unité** — cadre `email/base.html.twig`, expéditeur affiché,
signature DKIM, les deux moitiés du multipart — vers une destination
libre, **par un fournisseur choisi et par lui seul**, sur une voie
choisie, « Masse » par défaut. Un code court dans le sujet, à chercher
dans une boîte encombrée. Le verdict — réception, indésirables, jamais
reçu — est saisi à la main et consigné avec sa date, sa destination, son
fournisseur et sa voie.

Le paquet de support gagne sa section « Sondes de délivrabilité », et le
journal deux entrées (`mail_probe_sent`, `mail_probe_verdict`). Nouveau
sujet d'aide, nouvel onglet dans le rail, trois routes en `superadmin`,
une table `mail_probes` (58 tables).

### Décisions prises seul

**Pas de repli, et c'est la fonctionnalité.** `MailTransportChain`
parcourt une voie jusqu'à ce que quelqu'un prenne le message, ce qui est
exactement juste pour du vrai courrier et exactement faux ici : à la
question « est-ce que mes messages arrivent quand ils partent par
celui-ci », un message parti discrètement par un autre relais répond à
côté. Toute la valeur de l'historique tient à ce que chaque ligne nomme
le relais par lequel le message est *réellement* parti. Un relais qui
refuse fait donc échouer la sonde, bruyamment — et le quota, le
coupe-circuit et la réserve ne sont pas consultés non plus : ils
protègent le vrai courrier d'un relais en difficulté, et un diagnostic
est le seul message qui veut rencontrer la difficulté.

Le compteur d'envoi est incrémenté quand même : une sonde est un vrai
message sur un vrai relais, et l'allocation du jour en a vraiment une de
moins.

**Le fournisseur est conservé par son nom autant que par son id.** Un
relais supprimé six mois plus tard emporterait sinon la preuve de ce
qu'il a fait, et la ligne se lirait « indésirables via … » avec un blanc
là où était la réponse. Pas de clé étrangère non plus, pour la même
raison : supprimer un fournisseur ne doit ni effacer cette histoire ni
être refusé à cause d'elle.

**La destination est chiffrée et n'a pas d'index aveugle.** Rien ne
cherche une sonde par adresse — l'écran liste les derniers essais et le
verdict s'enregistre par id. Un index aveugle ajouté « au cas où » est
une empreinte déterministe d'une adresse conservée pour une question que
personne ne pose.

**Et elle est montrée à l'écran, contrairement à partout ailleurs.** Ce
n'est pas une contradiction : c'est l'adresse que l'opérateur a tapée une
minute plus tôt, et la comparaison pour laquelle la table existe — même
destinataire, deux relais, deux verdicts — ne se fait pas sans elle. Elle
reste hors du journal et hors du paquet de support, qui sont lus
ailleurs et conservés bien plus longtemps.

**« Ne le sortez pas des indésirables » est à l'écran, pas dans l'aide.**
C'est la seule consigne capable d'invalider toutes les mesures suivantes,
et la seconde où quelqu'un s'apprête à la transgresser est celle où il
lit les boutons de verdict. Une règle qui ne vit que dans la
documentation est une règle lue après la faute.

**Aucune cadence périodique, et l'absence est écrite sur l'écran.** Le
document prévoit une cadence hebdomadaire en option, désactivée par
défaut. Je ne l'ai pas livrée : un réglage désactivé par défaut est un
réglage qu'on active, et la garantie qui compte — que l'instrument ne
devienne pas la cause de ce qu'il mesure — vaut mieux comme impossibilité
que comme défaut. Un test (`testNothingButARequestCanSendAProbe`) échoue
si un gestionnaire de tâche apprend à en envoyer une. La carte « Pourquoi
pas d'envoi automatique » dit pourquoi, parce qu'une fonctionnalité
absente sans explication se lit comme un oubli que quelqu'un comblera
obligeamment.

### Écarts entre le document et le dépôt

**Le bump de version pour un changement de `schema.sql` n'existe plus.**
Le document de chantier le demande ; AGENTS.md (§ Architecture, dernier
point) dit l'inverse depuis que `ModuleManager` a cessé de conditionner
l'application du schéma à une comparaison de versions — c'était une règle
que rien n'appliquait, et qui produisait de vraies erreurs `Unknown
column` en production. Le schéma déclaré entier est migré comme un tout.
J'ai suivi AGENTS.md, qui est la règle non négociable du dépôt.

**Le contrat exact : « bâti comme un vrai envoi », pas « identique ».**
La différence tient en un en-tête, et elle est nommée plutôt que
sous-entendue — un mot absolu dans une documentation est un mot que
quelqu'un cite six mois plus tard contre le code. Un vrai
publipostage porte l'en-tête sous sa forme URL à un clic, construite avec
le jeton du destinataire — qu'une sonde n'a pas, et un lien fabriqué qui
répondrait 404 serait pire que rien. L'omettre n'est pas neutre non plus :
les destinataires le pèsent, donc une sonde sans en-tête serait plus
légère que le publipostage qu'elle représente, sur exactement le signal
qu'on mesure. La forme `mailto:` est un canal de désinscription réel — il
arrive à l'unité — et c'est l'approximation honnête la plus proche. C'est
donc « presque identique », et c'est écrit ici plutôt que passé sous
silence.

### Le défaut que le test a trouvé en se bloquant

`MailProbeSender` construisait `new PhpMailerTransport()` pour le
transport sous la couche épinglée. La sonde contournant la chaîne, elle
contournait du même coup **le transport que l'installation utilise
vraiment** : sur un site faisant tourner le transport de capture de
`test_tools` — dont tout le rôle est d'assembler les messages sans les
envoyer — la sonde aurait mis du vrai courrier sur le fil, depuis le seul
écran dont le métier est d'être honnête sur ce qui part.

Le symptôme a été un test qui ne rendait pas la main : il composait vers
un vrai serveur SMTP. `MailTransportFactory::build()` rend désormais le
transport qu'il a résolu, `MailProbeSender` le prend en dépendance
**obligatoire** — un défaut ici serait la décision de la racine de
composition prise silencieusement au mauvais endroit — et trois
assertions de câblage l'épinglent, vérifiées en les cassant.

C'est la même leçon que celle d'IT-02, une quatrième fois, sous un
nouveau déguisement : ce n'était pas une dépendance oubliée dans la
racine, mais une dépendance que la classe s'était fabriquée elle-même
pour ne pas avoir à la demander.

### La couverture qui manquait, et ce qu'elle cachait

La porte qualité de SonarCloud a refusé la PR à **66,0 % de couverture
sur le code neuf** (seuil : 80 %), et la relecture Claude a nommé la même
chose d'un autre côté : AGENTS.md demande, pour chaque route de
contrôleur, **et** un test d'intégration sur la réponse **et** la
frontière RBAC. Les trois routes de la sonde n'avaient que la seconde
moitié.

Ce n'était pas une exigence bureaucratique. Le bloc sonde du contrôleur
— les trois actions, le rendu, les deux projections — n'était couvert par
**aucune** ligne : ni le message de succès qui porte le code, ni la
distinction `warning`/`error` qui est toute la raison d'être de
`MailProbeNotRecordedException`, ni la page d'une installation sans
sonde. Quinze tests d'intégration plus tard, le bloc est couvert à 100 %,
et le code neuf de la PR à **98,8 %**.

Trois de ces quinze ont trouvé quelque chose :

**Un décor impossible, une cinquième fois.** Mon test du jeton périmé
passait un jeton mort dans le corps de la requête tout en laissant le
jeton valide dans `$_POST` — or `guardCsrf()` regarde aussi les
superglobales, donc la requête était acceptée. Ce n'est pas une session
expirée, c'est un état qu'aucune requête ne peut produire ; un refus
prouvé contre lui n'aurait rien prouvé. Le test vide `$_POST` d'abord, et
tombe alors correctement quand on retire la garde.

**Une garde qui ne pouvait pas être atteinte.** `headersFor()` refusait
de bâtir `List-Unsubscribe` quand l'adresse d'expédition était vide —
sauf que cette adresse *est* le `From`, donc PHPMailer refuse le message
bien avant l'assemblage des en-têtes. Une branche où aucune requête ne
peut entrer. Elle est retirée, et le test dit ce qui se passe vraiment :
sans adresse d'expédition, la sonde est refusée plutôt qu'envoyée de la
part de personne — et le refus ne cite aucune adresse.

**Une méthode que personne n'appelait.** `MailProbe::isPending()` n'avait
aucun appelant : la page filtre en SQL, par `MailProbeRepository::
pending()`. L'écrire avait l'air prudent ; la couvrir d'un test aurait
été fabriquer de la couverture au lieu de la gagner. Elle est retirée.

Le reste — journal indisponible, compteur d'envoi indisponible, table des
sondes illisible, fournisseur inconnu, verdict inconnu, sonde disparue,
second verdict — sont les branches écrites exprès pour avaler leur propre
échec. Chacune est maintenant tenue par un test **vérifié en le cassant**,
ce qui est la seule manière de distinguer « la branche fait ce qu'il
faut » de « rien n'entre jamais dedans ».

### Reporté

- **`@group database` en docblock ne fait plus rien.** PHPUnit 13 ne lit
  plus les métadonnées des commentaires, et 509 fichiers de tests portent
  encore cette étiquette : elle est décorative. Sans conséquence de
  correction — ces tests tournent bien, dans la suite par défaut — mais
  leur classe affirme une appartenance au job `database-mariadb` qu'elle
  n'a pas. Découvert en vérifiant que mes propres tests tournaient bien
  sur MariaDB. Hors périmètre d'IT-04 : c'est une reprise de 509
  fichiers, à faire d'un bloc et sur sa propre PR.
- La cadence hebdomadaire optionnelle (ci-dessus), tant que personne ne
  la demande : l'absence est plus sûre que le défaut.
- Le rattachement d'un rebond à une sonde précise. Le code est déjà
  reconnaissable (`MailProbeSender::codeIn()`, préfixe `SM-` distinct du
  `RET-` d'IT-03) ; ce qui manque est le lecteur de `delivery-status`,
  qui est le sujet d'IT-05.

---

## IT-05 — Les rebonds

### Livré

Un rebond (`message/delivery-status`, RFC 3464) arrivant dans une boîte du
courrier entrant est lu, attribué à l'adresse qui a échoué, compté, et au
deuxième échec définitif l'adresse cesse d'être écrite. La personne
concernée est prévenue, voit la raison en français ordinaire sur sa page
d'adresses, et peut remettre son adresse en service elle-même. Le
super-admin dispose d'une page « Rebonds » pour la même levée, parce que
beaucoup de parents ne se connectent jamais.

### Décisions structurantes

**Une ligne par adresse, pas par fiche.** L'adresse d'un parent figure sur
la fiche de chacun de ses enfants ; sans cela « bloquée » serait vrai sur
un écran et faux sur un autre, pour une seule boîte aux lettres. C'est le
raisonnement que `MemberEmailService::unsubscribe()` applique déjà.

**À côté de `status`, jamais dedans** (D19). Les trois valeurs de cette
colonne enregistrent des décisions du **membre** ; le blocage est une
décision du **site**. De là découle la règle d'accès : un super-admin peut
lever un blocage qu'il n'a pas posé lui-même, et ne peut toujours pas
réactiver une adresse qu'un parent a éteinte.

**L'index aveugle partagé, et pourquoi.** `EncryptionService::blindIndex()`
pose la règle : les index comparés entre tables partagent un usage. Celui
des rebonds est comparé à `member_emails`. En face,
`mass_mail_list_addresses` garde son usage séparé — donc le module ne
compare jamais d'index à travers la frontière, il interroge le cœur avec
l'adresse en clair qu'il tient déjà.

### Ce que les tests ont trouvé

**Un « envoi réussi » qui aurait désarmé tous les blocages du site.**
`recordSuccess()` effaçait l'état quand le relais acceptait le message. Or
un relais qui accepte ne prouve rien, et le rebond de cet envoi-là arrive
trente secondes plus tard : le compteur aurait été vidé avant chaque
rebond, jamais deux échecs, jamais un blocage — et silencieusement, puisque
aucun test de la règle de blocage n'envoie quoi que ce soit. « Réussi » ne
peut vouloir dire qu'une chose : n'a produit aucun rebond. Ce n'est
connaissable qu'après coup, donc chaque envoi juge le précédent
(`last_send_at`). Le décalage d'un envoi est inhérent, pas un raccourci.

**Et ce même `recordSend()` n'avait aucun appelant en production**,
découvert en construisant les statistiques par domaine. Toute la logique de
règlement était morte. Câblée dans `SendBatchHandler`, le seul endroit du
site où un message est confirmé remis à un relais pour une adresse nommée.

**Une garde inatteignable, la même qu'en IT-04.** Le service testait « seul
un définitif bloque » ; en le cassant, rien ne tombait, parce que seul le
dépôt incrémente le compteur. Retirée.

**Deux faits d'`inbound_mail` que rien n'écrivait**, vérifiés plutôt que
supposés et désormais épinglés : la partie `message/delivery-status` arrive
dans le corps texte (elle n'a ni nom de fichier ni `Content-Disposition`),
et le drapeau « automatique » de `BulkMailDetector` ne filtre ni le
stockage ni l'analyse. Les casser rendrait le site aveugle aux rebonds sans
qu'aucun test ne rougisse.

### Ce que la relecture a trouvé

**N'importe qui pouvait faire suspendre l'adresse de n'importe quel
membre.** Les deux relecteurs l'ont vu indépendamment. Une boîte surveillée
est, par construction, une boîte où le monde entier peut écrire ; le
consommateur lisait tout corps ayant la forme d'un `multipart/report` et
rien ne rattachait ce rapport à un envoi du site. Deux faux rapports
nommant l'adresse d'un parent la coupaient du site et déclenchaient la
notification qui va avec, sans qu'un seul message soit jamais parti.

La frontière est posée dans le **dépôt** plutôt que dans l'analyseur : un
analyseur reconnaît une forme, il n'a aucun moyen d'établir une provenance,
et l'y mettre aurait donné une garde qui a seulement l'air d'en être une.

La première version de cette garde demandait « avons-nous déjà écrit à
cette adresse ? ». Les relecteurs ont montré, en trois angles distincts,
que c'était trop faible. La règle est donc devenue une phrase :
**un rapport ne compte que si un message est parti depuis le dernier
rapport qui a compté.** Un rebond est une réponse, et une réponse demande
une question plus récente que la réponse précédente. Une table
`mail_send_receipts` retient, par index aveugle et **sans jamais stocker
l'adresse**, la date du dernier envoi vers chaque destinataire ; `record()`
compare cette date au `last_seen_at` de l'état.

Cette phrase remplace quatre gardes :

- une adresse à qui le site n'a jamais écrit n'a aucune preuve, donc rien
  ne peut jamais être enregistré à son sujet ;
- **un seul message nommant deux fois le même destinataire compte une
  fois.** Deux paragraphes séparés par une ligne vide coûtent une ligne à
  écrire, et suffisaient à atteindre le seuil de deux échecs d'un coup :
  la règle « deux événements distincts » était devenue « un message » ;
- **le même message relu compte une fois.** `MailboxSyncService` appelle
  `analyzeAll()` **avant** son contrôle de Message-ID — délibérément, son
  propre commentaire dit qu'une relecture est attendue après une remise à
  zéro d'UIDVALIDITY ou quand un message tombe dans deux dossiers
  surveillés. Un vrai rebond relu bloquait donc en moitié moins d'échecs
  qu'il n'en faut ;
- et **une vieille preuve d'envoi n'autorise qu'un seul rapport**, pas une
  provision sans fin. Sinon, connaître une adresse à qui l'unité a écrit
  un jour — n'importe quel parent, n'importe quel publipostage — suffisait
  à forcer le blocage message après message.

Ce qu'elle ne refuse pas, et c'est tout l'enjeu : envoi, rebond, envoi,
rebond, blocage. Chaque envoi rouvre la porte pour exactement une réponse.

**Et la preuve d'envoi est posée dans `MailService::send()`**, le seul
point par lequel tout message passe. Posée dans la tâche de publipostage
seule, comme au premier jet, une installation sans le module `mass_mail`
n'aurait jamais rien pu bloquer pendant que sa page Rebonds promettait le
contraire — et le rebond le plus probable qui soit, une adresse fraîchement
mal tapée refusée dès son courriel de confirmation, était précisément celui
que le site jetait.

**Un `rowCount()` qui aurait fait tomber un publipostage en plein milieu.**
L'upsert de la preuve lisait `rowCount()` pour savoir si la ligne existait.
Sans `PDO::MYSQL_ATTR_FOUND_ROWS`, que ce site ne pose pas, MySQL compte
les lignes *modifiées* et non les lignes trouvées — le piège que
`SettingRepository::replaceIfUnchanged()` documente déjà pour lui-même.
`last_send_at` est un `DATETIME` : deux messages à la même adresse dans la
même seconde sont la règle, pas l'exception, puisque des frères et sœurs
partagent la boîte d'un parent et qu'un lot les parcourt à la suite. La
seconde écriture est identique, `rowCount()` répond 0, l'INSERT part et
l'index unique lève une `PDOException` que la boucle d'envoi — qui ne
rattrape que `MailException` — aurait emportée hors du lot, à moitié
distribué. L'existence est donc demandée, jamais déduite.

**Et ce défaut-là était invisible à toute la suite.** SQLite, sur lequel
tourne la quasi-totalité des tests, rapporte les lignes *trouvées* qu'un
UPDATE ait changé quelque chose ou non : l'upsert fautif y est correct.
`#[Group('database')]` ne suffit pas non plus — le groupe sert à
sélectionner les tests dans le job CI, il ne bascule aucune connexion. Un
test n'atteint MySQL que s'il ouvre lui-même une connexion depuis
`TEST_DB_*`, comme le font `MigrationRunnerTest` ou `SchemaIntrospectorTest`.
D'où `BounceSendReceiptMysqlTest`, qui se connecte pour de bon, se
*skippe* sans serveur, et commence par vérifier sa propre prémisse : que
ce moteur-ci rapporte bien les lignes modifiées. Cassé-vérifié, il
reproduit exactement l'erreur annoncée (`Duplicate entry … for key
'idx_msr_blind'`).

**Une date d'écran qui vieillissait à l'envers.** La page du membre
affichait « Suspendue depuis le … » à partir de `last_seen_at`, qui avance
à chaque refus — y compris après le blocage, puisque le chemin « adresse
de liste » du module peut encore écrire à une adresse bloquée. `blocked_at`
est écrit une fois. Quelqu'un coupé en mars lisait donc « depuis
aujourd'hui », tous les jours, et contredisait la page du super-admin qui,
elle, retombait déjà sur le bon champ. `MemberPageServiceTest` n'avait
aucune couverture des rebonds : c'est pour cela que rien ne l'avait dit.

**Un envoi qui se jugeait lui-même propre avant d'en avoir eu le temps.**
Le commentaire disait déjà « un envoi doit avoir eu le temps de ne pas
rebondir » ; le code ne vérifiait que l'ordre des dates, jamais le délai.
Or un rebond n'est pas refusé à la porte : le serveur d'en face répond, et
cette réponse attend ensuite le relevé de la boîte, jusqu'à une journée
entière (`MAX_INTERVAL_MINUTES` = 1440). Deux messages vers la même boîte
avant le relevé suivant — deux frères et sœurs partageant l'adresse d'un
parent, résolus dans un même lot, c'est-à-dire précisément le cas pour
lequel cette fonctionnalité existe — et le second déclarait le premier
propre, effaçant la ligne et son compteur. À chaque lot. Le second échec
n'arrivait donc jamais et rien n'était jamais bloqué.
`BounceState::SETTLING_PERIOD` est la fenêtre de grâce ; se tromper en
long ne fait que retarder un oubli, se tromper en court perd le compte sur
lequel tout le blocage repose. Le test existant ne pouvait pas le voir :
son montage bloquait l'adresse d'abord, donc `recordSend()` sortait au
garde `isBlocked()` avant même d'examiner le règlement.

**Et la preuve d'envoi pouvait être fabriquée par la victime d'à côté.**
`addEmail()` accepte n'importe quelle adresse comme ligne `pending` —
c'est ce qu'est revendiquer une adresse — et la confirmation qui suit
partait par le même `send()` qui pose les preuves. Le verrou tombait donc
de « aucun compte nécessaire » à « n'importe quel compte » : revendiquer
l'adresse d'un tiers, déposer un faux rapport, supprimer et revendiquer,
déposer un second, et l'adresse était coupée pour tout le site. `send()`
prend désormais `countsAsProofOfSend`, faux pour cette confirmation et
pour elle seule. Ce n'est **pas** un cas de `MailPurpose` : cette
énumération est une catégorie d'acheminement et le dit, un quatrième cas y
signifierait une quatrième voie. Rien de réel n'est perdu : une adresse
`pending` n'est jamais résolue pour un envoi groupé, donc cette
confirmation est le seul message qu'elle puisse recevoir, et la confirmer
prouve déjà que la boîte se lit.

**Et la branche « adresse suspendue » du publipostage n'avait aucun test.**
C'est le seul trou que le filtre côté membre ne peut pas couvrir, puisque
`resolveValidAddressesForMassMail()` ne voit jamais une adresse de liste.
Deux tests dans `ListAddressFlowTest`, cassés-vérifiés.

**Le consommateur n'était inscrit que sur un registre sur deux**, et pas
celui qui travaille. Celui de `public/index.php` dit à l'écran de
configuration quelles portées existent ; celui de
`public/scheduler-bootstrap.php` est celui contre lequel la passe de
synchronisation appelle `analyze()`. Un super-admin pouvait donc cocher
« Rebonds » sur une boîte et aucun rebond n'aurait jamais été enregistré —
toute l'itération inerte, sans autre symptôme que le silence. C'est la
cinquième fois de ce chantier que la même leçon revient sous un autre
visage : **une dépendance optionnelle ajoutée à une classe est une
dépendance absente de la racine de composition tant qu'un test ne dit pas
le contraire.** Le test qui l'épingle désormais est celui qui construit
réellement le registre de l'ordonnanceur et compte ses consommateurs, pas
celui qui lit le texte source.

**Posséder une ligne n'est pas prouver qu'on lit la boîte.** `addEmail()`
accepte n'importe quelle adresse syntaxiquement valide comme ligne
`pending` — c'est précisément ce qu'est *revendiquer* une adresse — et
l'index unique est par membre, donc nommer l'adresse d'un autre réussit. Or
l'état de rebond est indexé sur l'adresse, pas sur la ligne : cette
revendication non prouvée affichait la catégorie et les dates de rebond du
voisin, et surtout **levait** le blocage posé sur sa boîte défaillante.
`bounceFor()` et `unblockBounce()` exigent maintenant une preuve de
contrôle — ligne importée de Desk, ou lien de confirmation suivi. Le refus
emprunte le mot d'une adresse inconnue (« Adresse introuvable. ») : nommer
la vraie raison confirmerait à qui demande que l'adresse est connue ici et
suspendue.

### Écarts et limites, assumés

**Seul un échec définitif bloque**, comme le demande la roadmap.
Conséquence : une boîte pleine depuis six mois rebondit en temporaire
indéfiniment et continue d'être écrite — exactement la réputation que ce
chantier protège. Implémenté tel que spécifié ; `FAILURES_BEFORE_BLOCK` et
la condition de sévérité sont les deux points à toucher si l'on veut
changer cela.

**Le notifieur ne joint pas tout le monde.** Un membre dont le compte est
son adresse Desk et dont une adresse *secondaire* rebondit n'est atteint
par aucun des deux chemins de résolution : l'adresse Desk vit dans
`member_years` et est toujours fournie par l'appelant. Cette personne n'est
pas laissée sans rien — la raison et le bouton l'attendent sur sa page — mais
elle n'est pas alertée.

**Les statistiques par domaine ne comptent que les refus.** La roadmap
demande « envoyés et refusés » ; compter les envois par domaine
destinataire demanderait une table de compteurs que rien d'autre ne
justifie aujourd'hui. Le refus par domaine répond déjà à la question qui
compte — une adresse qui échoue est une famille, dix chez le même
fournisseur est ce fournisseur qui refuse l'unité.

### Reporté

- Le rattachement d'un rebond à une sonde précise reste possible
  (`MailProbeSender::codeIn()`), et reste sans intérêt tant que personne ne
  le demande.
