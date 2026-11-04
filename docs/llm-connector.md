# Connecteur d’intelligence artificielle

Le contrat public reste `Modules\LlmConnector\Api\LlmConnectorInterface` :
les consommateurs demandent un tier, sans connaître le fournisseur ni ses
modèles ([ARCHITECTURE.md §7.5](../ARCHITECTURE.md#75-core-optionally-consuming-a-modules-public-api)).
Les comportements attendus sont définis dans
[specifications.md §39](../specifications.md#39-intelligence-artificielle-module-llm_connector).

## Établir les capacités avant de classer

`ProviderModelCatalog` est un objet interne au module. Chaque modèle porte
une preuve indépendante pour la conversation et pour les images : `true`
établit le support, `false` l’exclut, `null` le laisse inconnu. Une preuve
image seule ne prouve pas la compatibilité avec la route de conversation.
Les doublons contradictoires restent inconnus.

| Fournisseur | Source de la preuve |
| --- | --- |
| Mistral | Les booléens `capabilities.completion_chat` et `capabilities.vision` des fiches de `/v1/models`. Un champ absent ou mal typé reste inconnu. |
| Anthropic | Le contrat Messages des modèles Claude actuellement disponibles, qui acceptent le texte et les images ; la pagination de Models est conservée. Voir la [documentation vision](https://platform.claude.com/docs/en/build-with-claude/vision). |
| Scaleway | Les identifiants exacts et modalités du [catalogue officiel](https://www.scaleway.com/en/docs/generative-apis/reference-content/supported-models/), vérifiés le 4 novembre 2026 et conservés dans `Provider/ScalewayModelCapabilities.php`. Le [guide vision](https://www.scaleway.com/en/docs/generative-apis/how-to/query-vision-models/) confirme l’usage de `/v1/chat/completions` pour les images. |

Le [schéma OpenAPI Scaleway](https://www.scaleway.com/en/developers/api/generative-apis/v1/schema.yml)
ne fournit pour un modèle que `id`, `object`, `created` et `owned_by` :
aucun champ de capacité n’est présumé. Les modèles inconnus et les entrées
dont la route reste ambiguë sont indisponibles. Les embeddings BGE, les
modèles OCR dédiés et la transcription ne sont pas des modèles de chat.
Le registre ne fait aucun téléchargement de documentation à l’exécution.
La lecture d’images requiert ensuite une sonde synthétique, susceptible d’être facturée.

Pour ajouter ou modifier une entrée Scaleway, vérifier sa modalité **et sa
route** dans les sources officielles, puis mettre à jour le registre, sa
date de vérification et les tests. Les modalités documentées orientent les sondes ; elles ne suffisent pas à affecter OCR chez Scaleway. Un nouveau nom ou une réponse du
classificateur n’est jamais une preuve. Ainsi,
`mistral-small-24b-instruct-2501` est texte seulement, tandis que
`mistral-small-3.2-24b-instruct-2506` accepte les images : « small » ne les
distingue pas.

`OcrModelSelector` appelle au plus une fois un modèle de conversation
vérifié, avec deux listes distinctes de candidats texte et image. Les
trois choix retournés sont validés. Une réponse invalide ou un échec
emploie un secours soumis aux mêmes capacités. Les motifs de noms servent
seulement à classer les coûts, jamais à accorder une capacité. Sans chat,
aucun classement n’est appelé et les trois tiers restent vides ; sans
modèle image économique vérifié, OCR reste vide.

## Vérifier la lecture d’images chez Scaleway

`ScalewayVisionVerifier` choisit une liste stable d’au plus trois candidats
économiques, en privilégiant les indications vision de la documentation.
`VisionProbeChallenge` produit un PNG monochrome contenant huit chiffres
aléatoires, sans dépendre de GD ni d’ImageMagick. La valeur attendue ne
figure ni dans le prompt ni dans une métadonnée de l’image. Le driver
accorde `VISION_VERIFIED` uniquement à une réponse exacte et non tronquée,
avec 32 tokens de sortie au maximum et un délai réseau de 5 secondes.
Une réponse quelconque ou une simple affirmation de voir n’est pas une preuve.
Aucune donnée de l’unité ne participe à ces sondes.

Le premier succès arrête les appels. Les résultats techniques sont stockés
dans le champ JSON `llm_providers.model_probe_cache`, dans la même transaction
que les modèles et les tiers. Une fusion sous verrou de ligne conserve les preuves
les plus récentes et les différents contextes de configuration ; un rafraîchissement
ancien ne peut pas rétablir un modèle refusé entre-temps. Les choix sont revérifiés
après cette fusion, avec un secours sans réseau si les capacités ont changé. Le cache est lié à l’endpoint et à la clé par
une empreinte ; la clé n’y est jamais conservée. Une preuve positive dure
sept jours ; un résultat indéterminé est réessayable après une heure.
Un refus structuré `route_not_supported`, observé pendant une sonde ou un
appel ordinaire, neutralise également le chat et reste excluant jusqu’à une
preuve positive ou un changement d’endpoint/clé. Une sonde peut le réévaluer
après sept jours ; un échec temporaire n’efface jamais cette preuve négative.
Les candidats au-delà de la liste bornée restent non vérifiés. Une preuve
positive encore valable évite de lancer des sondes supplémentaires lors des
tests manuels. Le passage hebdomadaire renouvelle toujours les preuves positives,
en testant d’abord le modèle déjà confirmé : la preuve ne doit pas expirer
entre deux tâches lorsque la première était planifiée avant son acquisition
ou lors d’un changement d’heure.

Le test de connexion et le rafraîchissement hebdomadaire peuvent donc
engendrer jusqu’à trois appels supplémentaires facturés. La page et l’aide
l’indiquent avant activation. Le driver réserve le contournement du garde
image à cette image synthétique ; les requêtes métier exigent la preuve
fraîche transmise par le résolveur.

## Rafraîchir et utiliser

`ProviderCatalogRefresher` est commun au test de connexion et au traitement
hebdomadaire. La découverte et le classement précèdent la transaction ;
l’enregistrement des seuls modèles chat, la suppression des modèles
incompatibles ou disparus et le remplacement des tiers s’effectuent
ensemble via `ProviderModelRepository::transactional()`. Un échec de
stockage annule l’ensemble. Une transaction déjà ouverte reste sous la
responsabilité de son appelant.

`TierModelResolver` est commun à la façade et à la configuration. Il ne
replie jamais OCR sur CHEAP. Pour Scaleway, il neutralise aussi les
anciennes affectations incompatibles avant le premier rafraîchissement ;
le driver refuse en plus tout envoi incompatible avant de transmettre le
prompt ou l’image. OCR requiert une preuve de sonde fraîche pour la configuration
courante, même si la documentation annonce la vision. Les autres drivers utilisent les affectations validées
lors de leur réévaluation.

Le journal contient les identifiants exclus et leurs raisons
(`chat_endpoint_unsupported`, `chat_capability_unverified`), les compteurs
chat/image, le nombre de sondes, leurs statuts techniques et les tiers retenus. Il ne contient ni la réponse du
classificateur, ni son message d’exception susceptible de répéter un
contenu soumis. Les tests simulent les API localement : ils n’appellent
jamais réellement Scaleway. `ProviderCatalogRefreshOnMysqlTest` exerce les
deux entrées sur le schéma réel et vérifie aussi le rollback et l’isolation
entre fournisseurs.
