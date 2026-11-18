# Rebond SSH (tunnel) : architecture

Une connexion peut passer par un bastion SSH (issue #13). Ce document décrit le tunnel ; le modèle chiffré
(`SshJump` dans `ConnectionParameters`) et la règle d'adresses du premier saut sont dans `src/Core/Connection`.

## État

Livré : le contrat, le conteneur, le bastion de test, la preuve de bout en bout, et le branchement : `TargetOpener::connect`
ouvre une connexion à rebond (MySQL, MariaDB, PostgreSQL) par le tunnel, essai de paramètres compris, avec la règle
d'adresses sur le bastion ET sur la cible, la liste de bastions dès l'enregistrement, la lecture de l'empreinte à confirmer
(`POST /web/connections/host-key`), le formulaire et le corps de l'API à jetons. **En production** : service `tunnel` facultatif de `compose.prod.yaml` (profil `tunnel`), démarré par `deploy.sh` seulement si `TERRILDB_SSH_ALLOWED_BASTIONS` n'est pas vide dans `deploy.env` (voir `docs/deploy.md`, « Tunnel SSH »).

## Les pièces

```
 application (php)                 conteneur `tunnel`                     bastion          base
 ------------------                ------------------                     -------          ----
 TunnelOpener  --JSON, 1 ligne-->  relay.py  --ssh -N -L sock:cible--->   sshd   ------->  cible
   (socket de contrôle)            (seul à avoir `ssh`)
        \                                |
         `--- volume `tunnel-sockets` (0700) : control.sock + <id>/<socket> ---'
```

- `App\Core\Connection\Tunnel\TunnelOpener` : `open(TunnelRequest): TunnelEndpoint`, `close(TunnelEndpoint)`.
- `AllowedBastionTunnelOpener` : n'envoie au relais que les bastions de `BastionAllowList`.
- `App\Tunnel\UnixSocketTunnelOpener` : client du socket de contrôle (une ligne JSON par demande, une par réponse).
- `AuditedTunnels` : journalise un bastion refusé (`target.forbidden`, adresse et port seulement).
- `docker/tunnel/relay.py` : le relais (Python, stdlib), image `docker/tunnel/Dockerfile` (Alpine, `openssh-client`).
- `docker/targets/bastion` : bastion sshd de test, avec `target-behind-bastion` (base sur un réseau `internal` que `php`
  ne voit pas). Service `tunnel` et `tunnel-init` dans `compose.yaml` (profil `targets`).

## Modèle de menaces

| Risque | Parade |
| --- | --- |
| Un compte vise un hôte quelconque via le rebond (sondage, SSRF) | Liste de bastions `TERRILDB_SSH_ALLOWED_BASTIONS` (« hôte » ou « hôte:port », virgules), **vide par défaut = tout refusé**. Contrôlée par l'application ET, indépendamment, par le relais. Comparaison exacte. Refus : 403 `forbidden_bastion`, sans dire ce que la liste contient. |
| Compromission de l'application : `ssh` disponible | L'image de production n'embarque pas `ssh` ; seul le conteneur `tunnel` sort vers les bastions (pare-feu de sortie à poser, voir `docs/deploy.md`). |
| Clé privée lisible au repos | Elle reste chiffrée par la clé de l'utilisateur (zero-knowledge). Déchiffrée seulement en mémoire du processus PHP, elle voyage dans le flux du socket de contrôle, jamais dans un journal, un audit, un message ou une exception (`TunnelRequest` et `SshJump` masquent la clé des dumps et refusent la sérialisation). Dans le relais, elle est en mémoire et, le temps de l'authentification, dans un fichier `0600` d'un **tmpfs** supprimé dès que le tunnel est établi. |
| Clé d'hôte usurpée (homme du milieu) | L'empreinte SHA-256 fait partie de la connexion chiffrée et est **obligatoire** : absente, `host_key_unconfirmed` ; différente, `host_key_changed`. Le relais lit les clés avec `ssh-keyscan`, ne retient que celle qui porte l'empreinte, et `ssh` tourne en `StrictHostKeyChecking=yes` avec ce seul fichier `known_hosts`. Jamais de `accept-new`. |
| Autre processus qui parle au relais | Socket de contrôle `0600` dans un dossier `0700`, et contrôle de l'uid du pair (`SO_PEERCRED`). `php` et `tunnel` tournent sous le même uid (`user:` dans compose). |
| Sortie d'`ssh` qui fuit | stdout/stderr jetés ; le relais ne renvoie qu'un code (`invalid_request`, `forbidden_bastion`, `host_key_unconfirmed`, `host_key_changed`, `unavailable`). |
| Injection dans la ligne de commande | Aucun shell ; hôtes, utilisateur et noms de socket validés par expressions strictes (rien ne commence par `-`), `-F /dev/null`, options en liste. |
| Conteneur compromis | Utilisateur non root, `read_only`, `cap_drop: ALL`, `no-new-privileges`, aucun port publié, réseau dédié ; seul volume partagé : les sockets. |
| Tunnels oubliés | Fermeture après 5 minutes sans nouvelle demande d'ouverture (maximum accepté par le relais) ; un tunnel dont `ssh` est mort est rouvert ; les dossiers laissés par un redémarrage sont purgés au démarrage. |
| Déni de service du relais | Le verrou ne protège que les structures (jamais tenu pendant `ssh-keyscan` ou `ssh`) ; deux ouvertures identiques simultanées n'en font qu'une ; plafonds `TUNNEL_MAX_TUNNELS` (50), `TUNNEL_MAX_PER_BASTION` (10), `TUNNEL_MAX_CONNECTIONS` (32, au-delà : `busy`), `TUNNEL_OPEN_TIMEOUT` (20 s au total par ouverture) ; `pids_limit`, `mem_limit` et `cpus` sur le service. |
| Clé privée dans une trace d'exception | `#[\SensitiveParameter]` sur tout paramètre qui porte la clé (`SshJump`, `UnixSocketTunnelOpener::call`, lecture du JSON chiffré), avec des tests qui lisent `getTrace()`. |

## Limites connues (à lire avant de brancher)

- **Inactivité** : le relais ne voit pas le trafic du socket. Le délai de 5 minutes court depuis la DERNIÈRE DEMANDE d'ouverture :
  une session de base qui dure plus longtemps sans que l'application rappelle `open()` est coupée. Le branchement de
  `TargetOpener` doit donc rappeler `open()` à chaque usage (il retrouve le tunnel et renouvelle le délai).
- **Propriété** : la réutilisation est liée à la clé privée (son empreinte entre dans l'identité) : deux comptes qui
  partagent une clé partagent le tunnel. `close` ne vérifie pas de propriétaire : l'identifiant (128 bits) fait foi.
- **Contrôle du pair** : `SO_PEERCRED` ne compare que l'uid. Aucun autre service ne doit tourner sous l'uid de `php` et de
  `tunnel` (le dossier `0700` donne déjà cette garantie). L'uid de l'image `tunnel` est un argument de build (`UID`/`GID`,
  passés par compose) : `ssh` refuse de tourner sous un uid sans entrée `passwd`, il doit donc être celui du conteneur.
- **Liste de bastions** : comparaison exacte, point final ignoré, une adresse IPv6 s'écrit `[2001:db8::1]:22` ; le relais
  est plus strict que PHP sur les noms (échec fermé). N'y mettre que de vrais bastions SSH : y inscrire un service interne
  offrirait un `ssh-keyscan` vers lui.
- **Cible derrière le bastion** : `TargetHostPolicy` juge le bastion (résolu et épinglé ici) ET la cible, mais la cible est
  résolue côté bastion (sa « boucle locale » est la sienne) : un membre ne peut pas la nommer comme le serveur de TerrilDB
  (`localhost`, adresse littérale de boucle locale, de lien local ou 0.0.0.0, base interne, nom qui se résout ICI vers
  l'une d'elles), mais un nom que seul le bastion résout n'est pas jugé. Refus par prudence, jamais d'exemption silencieuse.
  Le risque résiduel repose sur la liste de bastions et sur le compte SSH. Un administrateur n'est pas borné.
- **Adresse du bastion** : le relais résout lui-même le nom du bastion (la règle d'adresses d'ici est une vérification, pas
  l'adresse épinglée) ; la liste de bastions est le rempart contre un DNS qui changerait.
- **Plafond par compte** : les plafonds du relais (50 tunnels, 10 par bastion) sont globaux ; l'application ajoute un plafond de
  `TunnelQuota::MAX_PER_ACCOUNT` (5) tunnels simultanés PAR COMPTE (429 `too_many_tunnels`), tenu en cache avec la même échéance que
  le relais (5 min, renouvelée à chaque usage ; un tunnel déjà compté ne compte pas deux fois). Comptabilité approchée, sans verrou
  (un dépassement d'une unité en cas de requêtes simultanées est possible). Un tunnel ouvert pour rien (plafond atteint) est refermé.
- **Essai de connexion** : `TargetOpener::probe` referme le tunnel ouvert POUR l'essai dès qu'il est fini (réussi ou non) et libère sa
  place ; la réponse du relais porte `reused` : s'il existait déjà (celui d'une connexion enregistrée), il n'est pas refermé. Un relais
  qui ne dit rien est traité comme `reused` (on ne referme que ce qu'on est sûr d'avoir ouvert). Deux comptes qui partagent une clé et
  une cible partagent le tunnel : celui qui l'a ouvert pour un essai le referme, l'autre le rouvre à l'usage suivant.
- **Lecture de l'empreinte** : chaque lecture lance un `ssh-keyscan` depuis le conteneur (au plus 32 demandes en parallèle) ;
  plafonnées à 20 par 10 minutes et par compte, refusées comprises (limiteur `host_key_read`). Un sshd récent pénalise les connexions sans authentification d'une même source
  (`PerSourcePenalties`) : le relais ne tente qu'un type de clé par connexion et s'arrête au premier.
- **Pare-feu de sortie** : le réseau du tunnel (`bastion-net`) sort librement ; les règles de `docs/deploy.md` doivent viser
  aussi le conteneur `tunnel`, et n'autoriser que les bastions déclarés.
- Le service `UnixSocketTunnelOpener` ne doit pas être injecté seul : passer par l'alias `TunnelOpener` (liste) et par
  `AuditedTunnels` (audit).

## Sockets

Le dossier des sockets (`/run/tunnels`, volume partagé) est en `0700`. Le relais y crée `control.sock` puis, par tunnel,
un dossier `<id>/` (`0700`, id aléatoire de 128 bits) qui contient `known_hosts` et le socket de la base : `tunnel.sock`
ou, pour PostgreSQL, `.s.PGSQL.<port>` (libpq cherche ce nom dans le dossier : `host=/run/tunnels/<id>`). MySQL/MariaDB
utilisent `unix_socket=<chemin>`. Réutilisation : une même demande (bastion, utilisateur, empreinte, cible, nom de socket,
clé) retrouve le tunnel ouvert au lieu d'en créer un autre.

Repli sur un port local (loopback) : **non implémenté**. Il ne sera nécessaire que pour un pilote qui ne sait pas se
connecter par socket Unix (ClickHouse HTTP et Redis via leurs clients actuels ?) ; à décider avec le branchement des drivers.

## Configuration

| Variable | Où | Rôle |
| --- | --- | --- |
| `TERRILDB_SSH_ALLOWED_BASTIONS` | application ET conteneur tunnel | bastions autorisés, vide = tout refusé |
| `TERRILDB_TUNNEL_CONTROL` | application | socket de contrôle (`/run/tunnels/control.sock`) |

En production, le service `tunnel` de `compose.prod.yaml` reprend celui de `compose.yaml` (uid 33 fixé, réseau `tunnel` propre, profil
`tunnel`) ; la valeur vient de `deploy.env` et est la même pour l'application et le conteneur.

## Essayer

```bash
docker compose --profile targets up -d --build --wait tunnel target-bastion target-behind-bastion
docker compose run --rm --no-deps php vendor/bin/phpunit --group integration tests/Integration/Tunnel tests/Integration/Web/JumpConnectionsWebTest.php
docker compose run --rm --no-deps --entrypoint python3 -w /opt tunnel -m unittest   # tests du relais
```

Les clés du bastion de test sont générées à chaque démarrage (volume `bastion-keys`), jamais versionnées.

## Ce qui reste

1. Poser à la main les règles de sortie du réseau `tunnel` (`docs/deploy.md`) : jamais appliquées par `deploy.sh`.
2. Étape CI pour le service `tunnel` : tests du relais (`python3 -m unittest`) et `--group integration tests/Integration/Tunnel`
   + `JumpConnectionsWebTest` (profil `targets`, hôte de CI à nommer).
3. ClickHouse et Redis : repli sur un port local (loopback) pour les pilotes qui ne savent pas se connecter par un socket Unix.
4. Outil MCP `create_connection` : pas de rebond (l'API à jetons l'accepte) ; lecture de l'empreinte par l'API à jetons.
5. Changement d'empreinte : parcours dédié (aujourd'hui, l'utilisateur relit et
   enregistre la nouvelle dans le formulaire, après avoir vérifié auprès de l'administrateur du bastion).
6. Clé protégée par phrase : non prise en charge (`BatchMode`) ; décision à prendre. Génération de clé côté navigateur à étudier.
7. SOCKS5 : avec le compagnon VPN (#14/#15).
