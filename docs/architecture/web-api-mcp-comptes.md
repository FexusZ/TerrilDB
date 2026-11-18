# Couche web, API à jetons, MCP, comptes, audit

Détail déplacé de `CLAUDE.md` sans modification de fond ; les règles essentielles restent dans `CLAUDE.md`.

## Couche web (src/Http/Web)

API JSON de la SPA, routes `/web/*` (l'API REST à jetons sera `/api/v1/*`). Contrôleurs minces : `AccountController`
(inscription, connexion, `/web/me`), `AccountSecurityController` (mot de passe, TOTP, codes de récupération),
`ConnectionController`, `SchemaController`, `QueryController`. La connexion n'est pas faite par un authentificateur
Symfony mais par `LoginService` (qui dérive la clé) ; `WebSession::open` démarre la session, renouvelle son identifiant,
ouvre la session Symfony (`ProgrammaticAuthenticator`) et confie la clé au coffre : toujours démarrer la session AVANT de
lire son identifiant. Une session perd sa reconnaissance quand le hash du mot de passe change (`SecurityUser::isEqualTo`).
Protections : en-tête `X-TerrilDB-Request: 1` obligatoire sur toute requête non sûre (CSRF), plafond de tentatives
(`LoginThrottle` : 30/15 min par adresse ; 8/15 min par compte seulement sur les actions qui vérifient le mot de passe d'une session ouverte ; connexion et récupération par phrase passent par `LoginLockout`, blocage À PALIERS du COUPLE adresse + compte : 5 échecs de suite -> 15 min, puis 1 h, 3 h, 6 h, 12 h à chaque nouveau blocage (palier oublié après un jour calme, réussite = échecs remis à zéro), le compte n'est jamais verrouillé pour tous ; une adresse dont les blocages touchent 3 comptes différents est bannie 24 h, puis 3 jours, puis 7 jours ; compte inconnu = compte connu ; état dans le cache `cache.rate_limiter`, blocage ou ban déclenché = une ligne d'audit `login.throttled` avec `kind` et `seconds` ; levée manuelle d'un ban : `app:ban:lift <ip>`, le palier est conservé),
erreurs du domaine traduites en codes stables par `ErrorMapper` (jamais le message du moteur d'une cible injoignable).
Inscription : `APP_REGISTRATION=first_only` (défaut, seulement le premier compte) ou `open`. Le premier compte peut aussi naître en console : `app:user:create-first <e-mail> <utilisateur>` (`CreateFirstAccountCommand`, mot de passe saisi deux fois sans écho, jamais en argument ; refusé sans terminal et dès qu'un compte existe).
Tests fonctionnels : `tests/Integration/Web/WebApiTestCase` (conteneur redémarré à chaque requête, écritures annulées par DAMA).

## API REST à jetons (src/ApiToken, src/Http/Api, docs/api.md)

Le serveur ne détient jamais la clé de l'utilisateur ; un jeton d'API (`tdb_<id>_<secret>`) emporte donc une copie de
la clé enveloppée par son propre secret (`SessionKeyWrapper`, contexte = id du jeton). Le serveur ne garde que
l'empreinte du secret (`ApiTokenSecret::verifier`) et l'enveloppe : sans le jeton, rien n'est lisible. Lecture seule
par défaut, connexions listées (au moins une, sauf jeton « toutes » ou autorisé à en créer), expiration de 365 jours au plus OU aucune (`neverExpires: true`, `expires_at` nul : choix du propriétaire, la révocation reste possible à tout moment), supprimé à tout changement
de mot de passe (`PasswordChanger` → `ApiTokenService::revokeAll`, même transaction). Pare-feu `api` sans session
(`BearerTokenAuthenticator`) ; échecs plafonnés par adresse, requêtes plafonnées par jeton (`ApiThrottle`). Les
assistants JSON partagés avec l'interface web sont dans `App\Http\Json`.
Droits d'un jeton (`TokenPermissions`, fixés à la création, tous refusés par défaut) : `diagnostic` (supervision, voir « Diagnostics du serveur »), `writeData` (INSERT/UPDATE/DELETE),
`writeSchema` (CREATE/ALTER/DROP/TRUNCATE/GRANT/CALL), `createConnections`. Écrire passe par `POST /api/v1/connections/{id}/execute`
(et l'outil MCP `execute_statement`, visible des seuls jetons qui écrivent) ; `/query` et `run_query` restent en lecture seule pour
tous. Un destructif (UPDATE/DELETE sans WHERE, DROP, TRUNCATE…) répond 409 `confirmation_required` tant que `confirmed: true` manque ;
sans aucun droit d'écriture, 403 `permission_denied`. Limite connue : seul le filtre lexical sépare données et structure
(un SELECT d'une fonction qui écrit n'est pas retenu en lecture-écriture ; les droits du compte de la base cible restent le dernier rempart).
`POST /api/v1/connections` (outil MCP `create_connection`, `TokenConnections`) enregistre une connexion chiffrée par la clé du jeton,
sous la même règle d'adresses que l'interface web (`TargetHostPolicy`), l'ajoute à la liste du jeton et journalise `connection.created` (acteur api/mcp).
Droits de LECTURE à cocher (#37, premier lot ; `TokenPermissions::$readSchema` / `$export`, colonnes `read_schema` / `export_data`, refusés par défaut, migration à la main : un jeton existant garde exactement ses droits, testé) : `readSchema` = `GET /api/v1/connections/{id}/databases|schemas` et outils MCP `list_databases` / `list_schemas` (audit `schema.read` par `AuditedSchemaReads`, nombre de lignes seulement) ; `export` = `POST /api/v1/connections/{id}/export` et outil `export_query` (`ExportFormat` commun à l'interface, lecture seule, audit `query.*` avec `purpose: export`). `ApiTokenAccess::requireReadSchema|requireExport` -> 403 `permission_denied` ; un outil MCP n'est déclaré qu'aux jetons qui ont le droit. Plafond de lecture : un jeton SANS `export` reçoit au plus `ApiTokenAccess::ROWS_WITHOUT_EXPORT` (500) lignes de `/query`, `run_query`, `/execute` et `execute_statement` (`readRowLimit`, aussi appliqué par `writeOptions` : sans cela un jeton qui écrit lit par un SELECT ; les scripts ne renvoient que des NOMBRES de lignes, jamais leur contenu, donc rien à plafonner), `truncated` comme une coupe habituelle, jamais une erreur ; l'interface web, qui a une session, n'est pas plafonnée) ; migration à `export` faux = un jeton déjà créé tombe à 500 lignes (il lui faut `export` pour retrouver le plafond du serveur). Front : deux cases dans `TokensView`. À venir (#37) : diagramme/audit/plan sous `readSchema`, `editData`, `editStructure`, `manageConnections`, `readHistory`, validation humaine, limites de débit réglables ; un jeton ne gère pas d'autres jetons. Un jeton peut naître sans connexion s'il est « toutes » ou autorisé à en créer. Créations plafonnées à 20 par heure et par jeton (limiteur `api_connection_creation`), tentatives refusées comprises : 429 `too_many_attempts`.
Un jeton peut être émis pour « toutes mes connexions » (`allConnections: true` à la création, colonne `all_connections`) : la
liste est celle du compte à cet instant, puis ne bouge que par `POST /web/tokens/{id}/refresh` (`ApiTokenService::refresh`,
session requise, texte du jeton inchangé, connexions supprimées retirées, pas de plafond de connexions, refusé 422
pour un jeton à liste fixe ; l'actualisation ne prolonge pas l'expiration). Jamais automatique : le propriétaire décide quand les nouvelles connexions deviennent lisibles (audit `token.refreshed`).

Écriture en DEUX TEMPS (#41, `src/Confirmation`, `WriteApproval`) : `prepare` (`POST /api/v1/connections/{id}/prepare`, outil MCP `prepare_statement`) juge sans exécuter et émet un jeton `cft_…` (`ConfirmationTickets`, table `confirmation_ticket`, empreintes seulement) lié au jeton d'API, à la connexion, au texte EXACT, à la nature et au verdict, 300 s, consommé atomiquement (`DELETE … RETURNING`) à la première présentation même si elle échoue. `/execute` et `execute_statement` exigent ce jeton (409 `preparation_required`, 403 `invalid_confirmation`) sauf jeton d'API `singleStep` (colonne `api_token.single_step`, refusée par défaut, choix du propriétaire à la création) ; un jeton présenté est toujours vérifié ; un destructif exige aussi `confirmed` (vérifié AVANT consommation). Audit `query.prepared` et `query.refused` (`invalid_confirmation` / `confirmation_required`). Le refus `confirmation_required` (jeton de confirmation absent) précède tout jugement : il ne garde AUCUN texte (`length` seulement) ; de même une exception de l'exécuteur d'un script (`executor_error`). Pas encore : tables touchées et lignes estimées dans l'aperçu ; scripts par API/MCP (étape suivante, `ConfirmationKind::Script` prévu).

## Comptes multiples et invitations (src/Invitation, Entity/Invitation, Entity/Role)

Deux rôles : `admin` (le premier compte d'une installation, et le plus ancien à la migration) et `member`. Le rôle ne
change que la gestion des invitations ; chacun garde ses connexions, chiffrées par sa propre clé, donc invisibles aux
autres (y compris à l'administrateur). Une invitation est un lien à usage unique `inv_<id>_<secret>` : le serveur n'en
garde que l'empreinte, elle expire (7 jours par défaut, 30 au plus) et se révoque. Le serveur n'envoie aucun e-mail : la
note est un simple repère, l'administrateur transmet le lien. `InvitationService::register` crée le compte (toujours
membre) et consomme l'invitation dans une seule transaction manuelle (`wrapInTransaction` fermerait l'EntityManager à la
première exception du domaine) ; une inscription refusée ne brûle pas l'invitation ; inconnue, utilisée, expirée ou
révoquée rendent la même erreur `invalid_invitation`. Routes : `GET|POST /web/invitations`, `DELETE /web/invitations/{id}`
(403 `forbidden` pour un membre), `POST /web/register` accepte `invitation` même quand l'inscription libre est fermée.
Front : `/security/invitations` (`InvitationsView`), `/register?invitation=…`.
Envoi par e-mail facultatif (`InvitationMailer`, `symfony/mailer`) : actif seulement si `TERRILDB_MAIL_FROM` ET `TERRILDB_PUBLIC_URL`
sont renseignés (avec `MAILER_DSN`) ; le lien vient de l'adresse publique configurée, jamais de l'en-tête Host. `POST
/web/invitations` accepte `email` : l'adresse est validée AVANT d'émettre (rien d'orphelin), sert à l'envoi puis n'est
conservée nulle part (ni base, ni audit, ni journal) ; un échec de transport garde l'invitation (`emailSent: false`, le lien
reste à transmettre à la main) ; envoi non configuré = 409 `mail_disabled`. `GET /web/invitations` dit `emailEnabled`.

Administration des comptes (`AccountAdministration`, `AccountsController`, `AccountPresenter`, vue `AccountsView`, `/security/accounts`) : `GET /web/accounts`, `POST /web/accounts/{id}/disable|enable`, administrateur seulement (403 `forbidden`). La liste ne donne que identifiant, nom d'utilisateur, rôle, second facteur, dernière connexion (`last_login_at`, posée par `LoginService`), état et « c'est vous » : jamais l'e-mail ni rien de chiffré (connexions, jetons). Désactiver pose `disabled_at` et ne supprime rien : connexion refusée avec la réponse d'un mauvais mot de passe (`invalid_credentials`, après le même calcul), session ouverte non reconnue (`SecurityUserProvider`, `WebSession::user`), jeton d'API refusé comme un jeton inconnu (401 `invalid_token`, `ApiTokenService::authenticate`), phrase de récupération refusée (`invalid_recovery`) ; la réactivation rétablit tout, jetons compris. Refus 409 `cannot_disable_self` (son propre compte) et `last_administrator` (le dernier administrateur actif). Répéter l'action ne change ni ne journalise rien ; sinon audit `account.disabled` / `account.enabled` (acteur, cible en `subject`, aucun détail).
Jetons et journal d'administration (#48, suite ; `TokenAdministration`, `AdminController`, `AdminPresenter`, `CursorPage`, vue `AdministrationView`, `/security/administration`) : `GET /web/admin/tokens` (jetons de TOUS les comptes), `DELETE /web/admin/tokens/{id}` (204), `GET /web/admin/journal?limit=&before=` ; administrateur seulement (403 `forbidden`, un membre ne voit jamais les jetons d'autrui). Un jeton se montre par ses métadonnées : id, nom, propriétaire (`id`, `username`, `disabled` : les jetons d'un compte désactivé restent listés, marqués), `permissions` (tous les droits de `TokenPermissions` par `get_object_vars`, donc un droit ajouté plus tard apparaît sans autre changement ; le front affiche un droit inconnu sous son nom), `allConnections`, `connectionCount` (le nombre, jamais les identifiants ni le contenu des connexions), `status` (`active`/`expired`), `createdAt`, `expiresAt` (nul = n'expire jamais), `lastUsedAt` ; JAMAIS le texte du jeton, l'empreinte ni la clé enveloppée (un test scanne la réponse). Révoquer = suppression irréversible (le jeton présenté reçoit ensuite 401) ; révoquer le jeton d'UN AUTRE administrateur exige `confirmed: true` dans le corps (sinon 409 `confirmation_required`, `reason: other_administrator_token`, vérifié avant toute suppression ; son propre jeton et celui d'un membre : inchangé) et s'inscrit à part, `admin.administrator_token_revoked` (même forme que `admin.token_revoked`), l'interface redemande avec un texte propre ; jeton inconnu = 404 `not_found` sans dire à qui il serait, propriétaire non prévenu ; audit `admin.token_revoked` (acteur = l'administrateur, `subject` = id du jeton, détail `owner` = id du compte, jamais de nom de jeton ni de connexion). Le journal d'administration (`AuditAction::isAdministration()` : `account.disabled`, `account.enabled`, `admin.token_revoked`, `admin.administrator_token_revoked`) lit les actions de tous les administrateurs, pagination par curseur comme `/web/activity` (`CursorPage`), acteur rendu par id + nom d'utilisateur ; ces actions restent aussi dans le journal personnel de l'acteur (catégories `account`, `admin`). Front : confirmation avant révocation, texte par interpolation. Pas encore : politiques (mot de passe, second facteur obligatoire), jetons par compte désactivé en masse, e-mail au propriétaire.

## Audit (src/Audit, Entity/AuditEvent)

Journal d'événements immuables (table `audit_event`, insertion par DBAL hors unité de travail ORM). `AuditLog::record`
ne fait jamais échouer l'action en cours si l'écriture échoue (erreur consignée), mais refuse un détail qui ressemble
à un secret (`password`, `secret`, `token`, `code`, `key`) ou qui n'est pas un scalaire / une courte liste de chaînes.
`AuditedQueries` enveloppe `QueryService` pour les trois portes (web, API, MCP) : exécution, refus et échec sont
journalisés avec le texte de l'instruction ; une simple demande de confirmation ne l'est pas. Les connexions échouées
sont attribuées au compte visé, les blocages une fois par minute. Lecture : `/web/activity` (pagination par curseur,
filtre par catégorie) et `/web/connections/{id}/history`. Purge : `app:audit:purge [--days]`, conservation
`TERRILDB_AUDIT_RETENTION_DAYS` (365).

## Serveur MCP (src/Http/Mcp, docs/mcp.md)

SDK officiel `mcp/sdk` (0.10, expérimental : les révisions du protocole sont gérées par lui ; depuis la 0.10 les classes vivent sous `Mcp\Server\…`, le texte d’une réponse est du JSON compact, et sans `setSession` un serveur HTTP n’a plus de session, d’où le `FileSessionStore` explicite de `McpServerFactory`), confiné à `Http/Mcp`.
`POST|DELETE /mcp` derrière le même pare-feu `api` que l'API REST (jeton porteur) ; `McpServerFactory` rebâtit le serveur
à chaque requête (quatre outils de lecture déclarés explicitement, plus `lint_query` (`McpLintTool`) avec `readSchema`, plus `execute_statement` et `create_connection` selon les droits du jeton, sessions du protocole sur fichiers dans le cache) ; `McpTools`
réutilise `TargetSession`/`QueryService` (lecture seule sauf `execute_statement`) ; `McpSessionBinding` lie une session MCP au jeton qui l'a ouverte.
Pas de flux serveur (GET → 405). Hôtes acceptés : `TERRILDB_ALLOWED_HOSTS`. Les arguments d'outil portent le nom exact du
paramètre PHP (camelCase : `maxRows`, `timeoutMs`).
