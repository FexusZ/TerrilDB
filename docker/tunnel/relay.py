"""Relais des tunnels SSH de TerrilDB (#13).

Reçoit des demandes JSON (une ligne) sur un socket Unix de contrôle, ouvre `ssh -N -L <socket>:<cible>` vers un bastion
autorisé, réutilise un tunnel déjà ouvert et le ferme après un délai d'inactivité. Stdlib seulement.

Sécurité (voir docs/ssh-tunnel.md) :
- la liste des bastions vient de TERRILDB_SSH_ALLOWED_BASTIONS ; vide = tout refusé ;
- clé privée et phrase ne sont jamais journalisées ; la clé n'existe que dans ce processus et, le temps de
  l'authentification, dans un fichier 0600 d'un tmpfs, supprimé aussitôt ;
- l'empreinte de la clé d'hôte est OBLIGATOIRE (pas de confiance à la première utilisation) : elle est vérifiée
  par ssh-keyscan puis ssh n'accepte que cette clé (StrictHostKeyChecking=yes) ;
- aucune sortie d'`ssh` n'est renvoyée : seulement un code d'erreur stable.
"""
import hashlib
import json
import os
import re
import secrets
import signal
import socket
import socketserver
import struct
import subprocess
import threading
import time
from dataclasses import dataclass

MAX_IDLE = 300
KEY_MAX_BYTES = 16384
REQUEST_MAX_BYTES = 40000
HOST_RE = re.compile(r"^(?:[A-Za-z0-9][A-Za-z0-9.\-]{0,254}|[0-9A-Fa-f:]*:[0-9A-Fa-f:]+)$")
USER_RE = re.compile(r"^[A-Za-z0-9_][A-Za-z0-9._\-]{0,63}$")
KEY_RE = re.compile(r"^-----BEGIN [A-Z ]*PRIVATE KEY-----\n.*-----END [A-Z ]*PRIVATE KEY-----\s*$", re.S)
FINGERPRINT_RE = re.compile(r"^SHA256:[A-Za-z0-9+/]{43}$")
SOCKET_NAME_RE = re.compile(r"^(tunnel\.sock|\.s\.PGSQL\.[0-9]{1,5})$")
ID_RE = re.compile(r"^[0-9a-f]{32}$")


class RelayError(Exception):
    """Erreur à code stable : le seul détail qui sorte du relais."""

    def __init__(self, code):
        super().__init__(code)
        self.code = code


@dataclass(frozen=True)
class Request:
    host: str
    port: int
    user: str
    private_key: str
    fingerprint: str
    target_host: str
    target_port: int
    socket_name: str
    idle_seconds: int

    def __repr__(self):
        return "Request(%s:%d -> %s:%d)" % (self.host, self.port, self.target_host, self.target_port)

    def identity(self):
        material = json.dumps([
            self.host.lower(), self.port, self.user, self.fingerprint, self.target_host.lower(),
            self.target_port, self.socket_name, hashlib.sha256(self.private_key.encode()).hexdigest(),
        ])
        return hashlib.sha256(material.encode()).hexdigest()


def normalize_host(host):
    return host.lower().strip("[] .\t")


def parse_allowed(configured):
    allowed = set()
    for item in configured.split(","):
        item = item.strip()
        if not item:
            continue
        host, port = item, 22
        match = re.fullmatch(r"(.+):(\d{1,5})", item)
        if match:
            host, port = match.group(1), int(match.group(2))
        allowed.add((normalize_host(host), port))
    return allowed


def _is_port(value):
    return isinstance(value, int) and not isinstance(value, bool) and 1 <= value <= 65535


def parse_request(message):
    host, user, key = message.get("host"), message.get("user"), message.get("private_key")
    fingerprint, target = message.get("fingerprint"), message.get("target_host")
    name, idle = message.get("socket_name"), message.get("idle_seconds")
    valid = (
        isinstance(host, str) and HOST_RE.fullmatch(host)
        and _is_port(message.get("port"))
        and isinstance(user, str) and USER_RE.fullmatch(user)
        and isinstance(key, str) and len(key) <= KEY_MAX_BYTES and KEY_RE.match(key)
        and (fingerprint is None or (isinstance(fingerprint, str) and FINGERPRINT_RE.fullmatch(fingerprint)))
        and isinstance(target, str) and HOST_RE.fullmatch(target)
        and _is_port(message.get("target_port"))
        and isinstance(name, str) and SOCKET_NAME_RE.fullmatch(name)
        and isinstance(idle, int) and not isinstance(idle, bool) and 1 <= idle <= MAX_IDLE
    )
    if not valid:
        raise RelayError("invalid_request")
    return Request(host, message["port"], user, key, fingerprint, target, message["target_port"], name, idle)


class Relay:
    def __init__(self, root, allowed, factory, clock=time.monotonic):
        self.root = root
        self.allowed = allowed
        self.factory = factory
        self.clock = clock
        self.lock = threading.RLock()
        self.by_identity = {}
        self.by_id = {}

    def handle(self, message):
        try:
            if not isinstance(message, dict):
                raise RelayError("invalid_request")
            if message.get("op") == "open":
                return self._open(parse_request(message))
            if message.get("op") == "close":
                return self._close(message.get("id"))
            raise RelayError("invalid_request")
        except RelayError as error:
            return {"ok": False, "error": error.code}

    def _open(self, request):
        if (normalize_host(request.host), request.port) not in self.allowed:
            raise RelayError("forbidden_bastion")
        if request.fingerprint is None:
            raise RelayError("host_key_unconfirmed")
        with self.lock:
            identity = request.identity()
            entry = self.by_identity.get(identity)
            if entry is not None and entry["tunnel"].alive():
                entry["last_used"] = self.clock()
                return self._reply(entry)
            if entry is not None:
                self._drop(entry)
            tunnel_id = secrets.token_hex(16)
            directory = os.path.join(self.root, tunnel_id)
            os.mkdir(directory, 0o700)
            try:
                tunnel = self.factory(directory, request)
            except RelayError:
                self._remove_directory(directory)
                raise
            except Exception:
                self._remove_directory(directory)
                raise RelayError("unavailable")
            entry = {"id": tunnel_id, "identity": identity, "tunnel": tunnel, "directory": directory,
                     "socket": os.path.join(directory, request.socket_name), "idle": request.idle_seconds,
                     "last_used": self.clock()}
            self.by_identity[identity] = entry
            self.by_id[tunnel_id] = entry
            return self._reply(entry)

    @staticmethod
    def _reply(entry):
        return {"ok": True, "id": entry["id"], "socket": entry["socket"]}

    def _close(self, tunnel_id):
        if not isinstance(tunnel_id, str) or not ID_RE.fullmatch(tunnel_id):
            raise RelayError("invalid_request")
        with self.lock:
            entry = self.by_id.get(tunnel_id)
            if entry is not None:
                self._drop(entry)
        return {"ok": True}

    def sweep(self):
        with self.lock:
            now = self.clock()
            for entry in list(self.by_id.values()):
                if now - entry["last_used"] > entry["idle"] or not entry["tunnel"].alive():
                    self._drop(entry)

    def close_all(self):
        with self.lock:
            for entry in list(self.by_id.values()):
                self._drop(entry)

    def _drop(self, entry):
        self.by_identity.pop(entry["identity"], None)
        self.by_id.pop(entry["id"], None)
        try:
            entry["tunnel"].close()
        finally:
            self._remove_directory(entry["directory"])

    @staticmethod
    def _remove_directory(directory):
        try:
            for name in os.listdir(directory):
                os.unlink(os.path.join(directory, name))
            os.rmdir(directory)
        except FileNotFoundError:
            pass


class SshTunnel:
    def __init__(self, process):
        self.process = process

    def alive(self):
        return self.process.poll() is None

    def close(self):
        if self.process.poll() is None:
            self.process.terminate()
            try:
                self.process.wait(3)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait()


def _fingerprint(line):
    done = subprocess.run(["ssh-keygen", "-l", "-f", "-"], input=line, capture_output=True, text=True, timeout=5)
    parts = done.stdout.split()
    return parts[1] if done.returncode == 0 and len(parts) > 1 else None


def verified_known_hosts(request):
    """Lit les clés publiées par le bastion et ne garde QUE celle dont l'empreinte est celle de la connexion."""
    try:
        scan = subprocess.run(
            ["ssh-keyscan", "-T", "5", "-t", "ed25519,ecdsa,rsa", "-p", str(request.port), request.host],
            capture_output=True, text=True, timeout=15,
        )
    except subprocess.TimeoutExpired:
        raise RelayError("unavailable")
    lines = [line for line in scan.stdout.splitlines() if line and not line.startswith("#")]
    if not lines:
        raise RelayError("unavailable")
    for line in lines:
        if _fingerprint(line + "\n") == request.fingerprint:
            return line + "\n"
    raise RelayError("host_key_changed")


def ssh_factory(key_dir):
    def open_tunnel(directory, request):
        known_hosts = os.path.join(directory, "known_hosts")
        with open(known_hosts, "w") as handle:
            handle.write(verified_known_hosts(request))
        sock = os.path.join(directory, request.socket_name)
        target = "[%s]" % request.target_host if ":" in request.target_host else request.target_host
        key_path = os.path.join(key_dir, secrets.token_hex(16))
        descriptor = os.open(key_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        try:
            with os.fdopen(descriptor, "w") as handle:
                handle.write(request.private_key if request.private_key.endswith("\n") else request.private_key + "\n")
            command = [
                "ssh", "-F", "/dev/null", "-N", "-T", "-i", key_path,
                "-o", "IdentitiesOnly=yes", "-o", "BatchMode=yes", "-o", "PasswordAuthentication=no",
                "-o", "KbdInteractiveAuthentication=no", "-o", "StrictHostKeyChecking=yes",
                "-o", "UserKnownHostsFile=" + known_hosts, "-o", "GlobalKnownHostsFile=/dev/null",
                "-o", "ExitOnForwardFailure=yes", "-o", "ServerAliveInterval=30", "-o", "ServerAliveCountMax=3",
                "-o", "ConnectTimeout=10", "-o", "ForwardAgent=no", "-o", "ForwardX11=no", "-o", "ControlMaster=no",
                "-p", str(request.port), "-l", request.user,
                "-L", "%s:%s:%d" % (sock, target, request.target_port), request.host,
            ]
            process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            tunnel = SshTunnel(process)
            deadline = time.monotonic() + 15
            while time.monotonic() < deadline:
                if os.path.exists(sock):
                    return tunnel
                if not tunnel.alive():
                    raise RelayError("unavailable")
                time.sleep(0.05)
            tunnel.close()
            raise RelayError("unavailable")
        finally:
            try:
                os.unlink(key_path)
            except FileNotFoundError:
                pass

    return open_tunnel


def serve(root, key_dir, allowed, idle_sweep=5):
    relay = Relay(root, allowed, ssh_factory(key_dir))

    class Handler(socketserver.StreamRequestHandler):
        def handle(self):
            self.request.settimeout(10)
            credentials = self.request.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize("3i"))
            if struct.unpack("3i", credentials)[1] != os.getuid():
                return
            try:
                line = self.rfile.readline(REQUEST_MAX_BYTES)
                reply = relay.handle(json.loads(line))
            except (ValueError, OSError):
                reply = {"ok": False, "error": "invalid_request"}
            try:
                self.wfile.write((json.dumps(reply) + "\n").encode())
            except OSError:
                pass

    control = os.path.join(root, "control.sock")
    if os.path.exists(control):
        os.unlink(control)
    server = socketserver.ThreadingUnixStreamServer(control, Handler)
    server.daemon_threads = True
    os.chmod(control, 0o600)

    def sweep_loop():
        while True:
            time.sleep(idle_sweep)
            relay.sweep()

    threading.Thread(target=sweep_loop, daemon=True).start()

    def stop(*_):
        threading.Thread(target=server.shutdown, daemon=True).start()

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    print("relay ready (%d bastions allowed)" % len(allowed), flush=True)
    try:
        server.serve_forever()
    finally:
        relay.close_all()


def main():
    os.umask(0o077)
    root = os.environ.get("TUNNEL_ROOT", "/run/tunnels")
    key_dir = os.environ.get("TUNNEL_KEY_DIR", "/run/relay-keys")
    serve(root, key_dir, parse_allowed(os.environ.get("TERRILDB_SSH_ALLOWED_BASTIONS", "")))


if __name__ == "__main__":
    main()
