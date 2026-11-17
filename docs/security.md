# Modèle de menaces

Ce document décrit ce que TerrilDB protège, contre qui, et ce qu'il ne protège pas. Il complète [`deploy.md`](deploy.md)
(exploitation) ; pour signaler une faille, utiliser « Report a vulnerability » sur la page GitHub du dépôt (pas d'issue publique).

## Ce qu'on protège

1. Les **identifiants des bases** que les utilisateurs enregistrent (hôte, base, identifiant, mot de passe, clé de saut).
2. Les **bases cibles** elles-mêmes : qu'un compte TerrilDB, un jeton d'API ou un agent MCP ne fasse que ce que son propriétaire a voulu.
3. Le **journal** de ce qui a été fait, par qui, par quelle porte.

## Zero-knowledge : ce que le serveur peut et ne peut pas lire

Les paramètres d'une connexion sont chiffrés d'un bloc (XChaCha20-Poly1305, libsodium) avec une clé dérivée du mot de passe de
l'utilisateur (Argon2id, paramètres versionnés). Le serveur ne conserve ni le mot de passe, ni cette clé.

| Le serveur (et une sauvegarde de sa base) | Peut lire | Ne peut pas lire |
| --- | --- | --- |
| Au repos | nom et moteur d'une connexion, e-mail et nom d'utilisateur, rôle, historique et journal d'audit (texte des instructions saisies), empreintes | hôte, base, identifiant, mot de passe d'une connexion, secret TOTP |
| Pendant une session | la clé de l'utilisateur, en mémoire, le temps de la session : elle sert à ouvrir ses connexions | celle des autres utilisateurs |
| Un administrateur | gère les invitations ; rien de plus sur les connexions | les connexions des autres utilisateurs |

Conséquences à connaître :
- Un **serveur compromis en cours de fonctionnement** peut capter la clé d'un utilisateur qui se connecte (le code qui la dérive tourne
  sur le serveur). Le zero-knowledge protège les données **au repos**, pas contre un serveur malveillant en ligne.
- Le secret qui déverrouille la clé d'une session vit dans un cookie du navigateur (`TERRILDB_UNLOCK`) : le fichier de session seul ne suffit pas.
- Un jeton d'API (`tdb_…`) emporte une copie de la clé enveloppée par son propre secret ; le serveur ne garde que l'empreinte du secret. Un jeton
  perdu = jeton à révoquer ; un changement de mot de passe révoque tous les jetons.
- **Mot de passe perdu = connexions perdues**, sauf phrase de récupération facultative générée avant. Aucun administrateur ne peut les rouvrir.
- Une sauvegarde de la base interne ne contient aucune clé : voir « Sauvegarde et restauration » dans `deploy.md`.

## Les trois portes

| Porte | Authentification | Droits |
| --- | --- | --- |
| Interface web (`/web/*`) | session + second facteur TOTP facultatif ; en-tête `X-TerrilDB-Request` obligatoire sur toute requête non sûre (CSRF) ; blocage à paliers des échecs de connexion par couple adresse + compte, bannissement des adresses qui en touchent plusieurs | tout ce que l'utilisateur peut faire |
| API REST (`/api/v1/*`) | jeton porteur, sans session, expiration de 365 jours au plus (ou aucune, choix explicite), connexions listées, requêtes plafonnées par jeton | lecture seule par défaut ; droits `writeData`, `writeSchema`, `createConnections`, `diagnostic` accordés un à un à la création |
| Serveur MCP (`/mcp`) | même jeton, même pare-feu ; hôtes acceptés limités (`TERRILDB_ALLOWED_HOSTS`) | mêmes droits que le jeton ; une session MCP est liée au jeton qui l'a ouverte |

Écrire par jeton passe par deux temps (préparer, puis exécuter avec un jeton de confirmation à usage unique, lié au texte exact et valable 300 s),
sauf jeton « une étape » choisi par son propriétaire. Les trois portes appellent le même cœur : aucune ne contient de logique métier propre.

## Exécuter du SQL sur une base : garde et moteur

Toute instruction passe par `QueryService` : (1) un **garde lexical** par dialecte n'accepte qu'une instruction, refuse définitivement les
constructions dangereuses (fichiers du serveur, verrous durables, instructions de contrôle de transaction…) et demande confirmation pour ce qui
détruit ; (2) l'exécution se fait dans une **transaction ou un réglage en lecture seule imposé par le moteur** (le moteur fait foi), avec délai et
nombre de lignes bornés ; (3) chaque exécution, refus ou échec est **journalisé**. La lecture seule est le défaut pour l'API et le MCP.

Limites connues :
- le filtre est lexical : il peut se tromper, et ne détecte pas une condition toujours vraie (`WHERE 1 = 1`) ;
- avec un jeton qui écrit, seul le filtre sépare « données » et « structure » (le moteur ne fait pas cette distinction) ; un SELECT qui appelle une
  fonction qui écrit n'est pas retenu en lecture-écriture ;
- MySQL n'a pas de délai moteur pour une écriture, et un DDL y valide implicitement ;
- **le dernier rempart reste le compte de la base cible** : lui donner le minimum de droits (un compte en lecture seule suffit à la plupart des usages).

## Politique d'adresses

TerrilDB ouvre des connexions réseau vers des adresses que des utilisateurs saisissent (risque de rebond vers des services internes, « SSRF »).
Seul un administrateur peut viser le serveur lui-même (boucle locale, liens locaux, `0.0.0.0`, `localhost`, base interne) ; un membre garde les autres
machines, réseaux privés compris. Le contrôle a lieu à la création, à la modification, à l'essai et à chaque ouverture, pour l'interface comme pour les
jetons ; le nom n'est résolu qu'une fois et la connexion part vers l'adresse contrôlée (une réponse DNS qui change ensuite n'a plus d'effet) ; un refus est
journalisé. **Un filtre applicatif n'est pas un pare-feu** : poser des règles de sortie sur la machine (voir « Limiter ce que le serveur peut joindre »
dans `deploy.md`) ; la base interne est en outre seule sur un réseau Docker sans sortie.

## Tunnel et TLS

- **Bastion SSH** : le modèle de données le prévoit (clé privée et empreinte de la clé d'hôte dans les paramètres chiffrés) mais l'ouverture d'une
  connexion par un saut n'est pas encore prise en charge : aujourd'hui, TerrilDB joint directement la base.
- **TLS vers les bases** : aucune option TLS n'est posée pour MySQL/MariaDB et PostgreSQL, aucune vérification de nom ou d'autorité ; ClickHouse
  (8443, 443) et Redis (6380) utilisent TLS. Le réglage par connexion est à venir ; en attendant, exiger TLS côté serveur cible (`deploy.md`,
  « TLS vers les bases cibles »).

## Autres limites connues

- Le serveur ne peut pas se protéger d'un administrateur de la machine : il lit la mémoire, les cookies et le trafic en clair derrière nginx.
- Pas de second facteur obligatoire : il est facultatif, par compte.
- Les statistiques et estimations de lignes sont celles du moteur (parfois périmées) ; elles ne sont pas une garantie.
- Les compteurs de tentatives (blocages, plafonds) vivent dans un `tmpfs` du conteneur : un redémarrage les remet à zéro.
- La sauvegarde de la base interne reste sur la machine par défaut ; la copie hors machine est à la charge de l'exploitant.
- Le dépôt est public : les journaux d'intégration continue le sont aussi (le domaine et les chemins de la machine y sont masqués).
