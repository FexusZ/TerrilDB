# Secrets, connexions, introspection, capacités

Détail déplacé de `CLAUDE.md` sans modification de fond ; les règles essentielles restent dans `CLAUDE.md`.

## Crypto (src/Crypto)

Zero-knowledge, sans dépendance web/Doctrine. Flux : mot de passe → `KeyDerivation` (Argon2id, paramètres
`KdfParameters` versionnés et stockés par utilisateur) → `DerivedKey` (en mémoire seulement, effaçable,
non sérialisable) → `SecretCipher` (XChaCha20-Poly1305, format `v1:`…, l'identifiant de la ligne sert de
contexte authentifié). `SessionKeyVault` garde la clé le temps de la session (implémentation en mémoire
pour l'instant ; le stockage réel est à trancher). `SecretRotator` rechiffre tout-ou-rien au changement de
mot de passe. Toutes les erreurs héritent de `CryptoException`. Le hash d'authentification du mot de passe
est distinct (sel propre) et vit dans `Security/`, pas ici.

### Phrase de récupération (facultative, `RecoveryPhrase`, `RecoveryPhraseService`)

160 bits aléatoires en base 32 de Crockford (8 groupes de 4, lecture tolérante : casse, espaces, tirets, O/I/L). Le serveur
ne garde que `User::recoveryKey` : la clé de chiffrement enveloppée par un secret tiré de la phrase (`SessionKeyWrapper`,
contexte `recovery:<id>`), jamais la phrase. Elle sert à un seul geste, choisir un nouveau mot de passe sans connaître l'ancien
(`PasswordChanger::resetWithKey` + `SecretRotator::rotateWithKey`, même rotation tout-ou-rien du TOTP et des connexions,
jetons d'API révoqués). Elle est supprimée dès que la clé change (réinitialisation OU changement de mot de passe ordinaire,
`User::changePassword`) : il faut alors en générer une nouvelle. Création et retrait exigent session ET mot de passe ;
`POST /web/recover` (public, plafonné comme la connexion, réponse unique `invalid_recovery` pour phrase fausse, compte sans
phrase ou compte inconnu) n'ouvre AUCUNE session : le second facteur reste exigé à la connexion suivante. Une phrase
perdue = pas de récupération (comportement par défaut inchangé tant qu'on n'en a pas généré).

## Connexions (src/Connection, src/Core/Connection)

Une `DatabaseConnection` garde en clair son nom et son moteur (`Engine`) ; hôte, port, base, identifiant et mot
de passe forment un `ConnectionParameters` (JSON versionné) chiffré d'un bloc avec la clé de l'utilisateur,
contexte authentifié `connection:<uuid>`. L'entité refuse tout ce qui n'a pas la forme d'un chiffré. Les lectures
sont toujours cloisonnées par propriétaire (`findOwned`), une connexion d'autrui est indiscernable d'une
connexion inexistante. `TargetHostPolicy` (+ `HostResolver`) : seul un administrateur peut viser le serveur lui-même (boucle locale
127.x/::1, lien local 169.254.x/fe80::, 0.0.0.0, `localhost`, et la base interne repérée par l'hôte de `DATABASE_URL`, par nom OU par
adresse) ; un membre garde les autres machines, réseaux privés compris. Contrôle à la création, à la modification, à l'essai et à
l'OUVERTURE (`TargetOpener`, donc aussi pour une connexion enregistrée avant la règle), pour l'interface comme pour les jetons ;
erreur 403 `forbidden_target` sans jamais dire ce que le nom a donné ; tout refus est journalisé (`AuditedTargetHosts`, action `target.forbidden`, adresse saisie et port seulement, porte et jeton attribués). Le nom n'est résolu qu'UNE fois (`TargetHostPolicy::pinnedAddress`, IPv4 d'abord) et le driver reçoit
l'adresse contrôlée (`ConnectionParameters::withAddress`, jamais dans le JSON chiffré ; `AuditedTargetHosts::pinned`) : une réponse DNS qui change
ensuite n'a plus d'effet. L'hôte reste le nom pour l'identité : en-tête Host et TLS ClickHouse (option `resolve` du client HTTP), `peer_name` Redis TLS ;
PDO MySQL/PostgreSQL se connectent à l'adresse (aucune vérification de nom TLS n'y est configurée). Un nom à plusieurs adresses n'a pas de repli
sur la suivante (la première est épinglée). `PasswordChanger` rechiffre TOTP et connexions ensemble, tout-ou-rien : une connexion
illisible bloque le changement.
Ordre et couleur (en clair, rien de secret) : colonnes `position` (rang dans la liste du propriétaire ; une connexion créée va à la fin, `ConnectionManager::reorder` met les identifiants cités en tête et garde les autres derrière, un identifiant inconnu, répété ou d'autrui = 422 `invalid_input` sans dire lequel) et `color` (`ConnectionColor`, huit noms, jamais une valeur CSS libre ; jetons `--conn-*` clairs et sombres). `PUT /web/connections/order` `{ids}` ; `color` dans la création et la modification (absente = aucune). Front : glisser-déposer ou Alt+flèche dans l'arbre (`connections.reorder|move|moveOnto`, affichage immédiat puis rechargement si le serveur refuse).

## Introspection (src/Core/Driver, src/Core/Schema, src/Driver)

Un `Driver` (tagué `app.driver`, rassemblés dans `DriverRegistry`) sert un ou plusieurs `Engine` : il ouvre une
`TargetConnection` (DBAL + `SchemaIntrospector`). `DbalConnector` borne le délai de connexion et vérifie par un
`SELECT 1` ; un échec rend toujours `TargetConnectionFailedException` au message générique (le détail du moteur
reste dans l'exception précédente, jamais montré à l'utilisateur). Drivers : `MySql` (MySQL + MariaDB, information_schema),
`PostgreSql` (pg_catalog), `ClickHouse` et `Redis` (voir plus bas). `TargetConnection` ne dépend d'aucune bibliothèque
d'accès : le driver lui donne l'exécuteur, la façon de se libérer, et selon le moteur un introspecteur (SQL) et/ou une
navigation par clés (`keys()`). Ce qui manque lève `UnsupportedOperationException` (501 `unsupported_operation`). MariaDB : `TABLE_TYPE = 'SEQUENCE'` donne `TableKind::Sequence` (lecture seule comme une vue, ni éditable, ni diagramme, ni audit ; `JSON` y est un alias de `longtext`, la grille le traite donc comme du texte, exact car stocké tel quel). Les introspecteurs rendent le modèle `Core/Schema` (types natifs du moteur, index
primaire d'abord puis par nom, tri à l'octet car l'ordre du moteur dépend de la collation) ; périmètre = base de la
connexion (MySQL) ou schéma courant (PostgreSQL). Nombres de lignes = estimations du moteur, jamais un COUNT. Les noms
de tables passent toujours en paramètres liés. `TargetOpener` relie le tout : connexion enregistrée + clé -> cible ouverte.
Bases du serveur : `SchemaIntrospector::listDatabases()` (MySQL/MariaDB `information_schema.SCHEMATA` = seulement celles où le compte a un droit ; PostgreSQL `pg_database` sans modèles et avec `CONNECT` ; ClickHouse `system.databases` ; tri à l'octet ; `DatabaseSummary::system` pour information_schema/mysql/performance_schema/sys/system, masquées sauf `?system=1`). `GET /web/connections/{id}/databases` ; une autre base s'explore en OUVRANT la connexion sur elle (`TargetOpener::open(…, $database)` -> `ConnectionParameters::withDatabase`, adresse épinglée conservée) : une connexion reste une base, y compris sur PostgreSQL. Routes tables, structure, diagramme, audit et console acceptent `?database=` (corps `database` pour la console). Une base inconnue = échec de connexion générique (502). Redis : pas de liste (501). Tables paginées : `SchemaIntrospector::listTablesPage(TablePageRequest)` -> `TablePage` (`nextCursor` = nom de la dernière table, `total` seulement sur la première page, lu dans le catalogue). Pagination PAR CLÉ côté moteur, jamais en tranchant une liste complète : MySQL/MariaDB `ORDER BY BINARY TABLE_NAME` + `BINARY TABLE_NAME > BINARY ?`, PostgreSQL `COLLATE "C"`, ClickHouse `name > {after}` ; limite 1 à 500 (100 par défaut) ; recherche `q` = sous-chaîne sans casse en paramètre lié (`INSTR`/`strpos`/`positionCaseInsensitive`, donc `_` et `%` littéraux). `GET /web/connections/{id}/tables?limit=&cursor=&q=&database=` : sans `limit`/`cursor`/`q` la liste COMPLÈTE reste servie (compatibilité). Front : `workspace.tables` = tables chargées (cent par cent, `loadMoreTables`, `searchTables` côté serveur, réponse périmée ignorée) ; l'autocomplétion de l'éditeur SQL ne connaît donc que les tables chargées. Front : base et schéma se choisissent dans l'arbre des connexions (plus de sélecteur dans l'éditeur ; `?database=` / `?schema=` de l'adresse pilotent `workspace.database` / `workspace.schema`, et `WorkspaceView` ouvre la première base d'une connexion qui n'en nomme aucune). L'autocomplétion de l'éditeur connaît TOUTES les tables : `workspace.loadCompletion()` lit la liste complète des noms (route sans pagination), une fois par connexion/base/schéma.
La base d'une connexion est FACULTATIVE (`database` vide) : sans elle on joint le serveur (PostgreSQL ouvre la base `postgres`, ClickHouse `default`, Redis le numéro 0), `GET .../databases` rend `default` (null si aucune) et l'interface ouvre alors la première base de la liste. Schémas PostgreSQL : `SchemaIntrospector::listSchemas()` (501 ailleurs ; pg_catalog/information_schema masqués sauf `?system=1`, TOAST et temporaires jamais), `GET .../schemas?database=`, paramètre `?schema=` (corps `schema` pour la console) sur les mêmes routes : le schéma s'applique à l'OUVERTURE par `search_path` (`ConnectionParameters::withSchema`, éphémère comme l'adresse épinglée, jamais dans le JSON chiffré ; `set_config` + `quote_ident`, le nom est une donnée). Front : niveau schéma dans l'arbre, `workspace.schema`.


## Capacités par moteur et version (src/Core/Driver, CapabilitiesController)

Source de vérité unique de ce qu'un serveur sait faire : `EngineCapability` (récursion CTE, `LATERAL`, fenêtrage, `JSON_TABLE`, versionnement système, `SKIP LOCKED`, `NOWAIT`, index invisibles/ignorés, plan mesuré, colonnes générées, séquences, `CHECK` appliqué) et `CapabilityMatrix::for(Engine, ?ServerVersion)` (pure : version minimale par capacité et par famille, absente = jamais ; version inconnue = rien de revendiqué). La version est lue UNE fois à l'ouverture par chaque driver (`SELECT VERSION()`, `SHOW server_version`, `SELECT version()`, `INFO server` ; illisible = `null`, la connexion n'échoue pas) et portée par `TargetConnection::version()` / `capabilities()` (`ServerVersion::parse` : « 5.5.5-… » de MariaDB retiré, `-MariaDB` -> `ServerFlavour::MariaDb`, `-Vitess` -> `Vitess`). Un serveur enregistré MySQL qui annonce MariaDB est traité en MariaDB (`ServerCapabilities::$family`). Vitess ne revendique RIEN (aucune capacité n'y a été vérifiée) ; Redis non plus. Particularités constatées : `LATERAL` absent de MariaDB (11.8 et 13), présent sur MySQL, PostgreSQL ; ClickHouse 26.10 a `WITH RECURSIVE` et `EXPLAIN ANALYZE` (seuils figés sur 26.10, minimum plus ancien non vérifié) mais ni `LATERAL`, ni `JSON_TABLE`, ni verrous de lignes. `GET /web/connections/{id}/capabilities` rend `{engine, version: {major, minor, patch, flavour}|null, capabilities: {nom: bool}}` (rien n'est exécuté sur la cible). Ajouter une capacité : un `case`, une ligne par famille dans `CapabilityMatrix::MINIMUM`, une sonde dans `tests/Integration/Driver/CapabilityTest::probe` (rejouée contre les 6 cibles SQL : la table et le moteur doivent être d'accord, déclarée = marche, non déclarée = échoue). Tests : `tests/Core/Driver` (matrice, version), `CapabilityTest`, `tests/Integration/Web/CapabilitiesWebTest`. Front et extraits « SQL moderne » : non faits (issue #86, troisième PR).
