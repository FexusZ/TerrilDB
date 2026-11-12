# Rebond SSH (tunnel) : architecture

Une connexion peut passer par un bastion SSH (issue #13). Ce document décrit le tunnel ; le modèle chiffré
(`SshJump` dans `ConnectionParameters`) et la règle d'adresses du premier saut sont dans `src/Core/Connection`.

## État

Livré : le contrat, le conteneur, le bastion de test et la preuve de bout en bout. **Pas encore branché** :
`TargetOpener` refuse toujours une connexion à rebond (`unsupported_operation`), pour ne jamais joindre la base en direct
à la place du rebond voulu.

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
| Tunnels oubliés | Fermeture après 5 minutes d'inactivité (maximum accepté par le relais), renouvelée à chaque ouverture demandée ; un tunnel dont `ssh` est mort est rouvert. |

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

Pour la production, le service `tunnel` de `compose.yaml` est le modèle (sans `tunnel-init` si le dossier des sockets est
préparé autrement) ; il n'est volontairement pas encore dans `compose.prod.yaml` tant que rien ne l'appelle.

## Essayer

```bash
docker compose --profile targets up -d --build --wait tunnel target-bastion target-behind-bastion
docker compose run --rm --no-deps php vendor/bin/phpunit --group integration tests/Integration/Tunnel
docker compose run --rm --no-deps --entrypoint python3 -w /opt tunnel -m unittest   # tests du relais
```

Les clés du bastion de test sont générées à chaque démarrage (volume `bastion-keys`), jamais versionnées.

## Ce qui reste

1. Brancher `TargetOpener` : demander le tunnel (`AuditedTunnels`), remplacer hôte/port par le socket Unix, fermer à la
   fin ; un driver par moteur (PostgreSQL : dossier ; MySQL : `unix_socket`) ; repli loopback si un pilote ne sait pas.
2. Empreinte de l'hôte : opération « lire l'empreinte » du relais, confirmation explicite côté web (jamais silencieuse),
   enregistrement dans la connexion chiffrée.
3. Formulaire et API de la connexion (transport SSH, clé ed25519 à coller ; génération de clé côté navigateur à étudier).
4. `compose.prod.yaml`, pare-feu de sortie, et étape CI pour le service `tunnel` et les tests du relais.
5. Clé protégée par phrase : non prise en charge (`BatchMode`) ; décision à prendre.
6. SOCKS5 : avec le compagnon VPN (#14/#15).
