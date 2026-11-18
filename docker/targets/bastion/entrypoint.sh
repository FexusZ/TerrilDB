#!/bin/sh
# Génère à chaque démarrage les clés d'hôte et la clé cliente de test, puis publie dans /keys (volume réservé aux tests) la
# clé cliente et l'empreinte de la clé d'hôte. Matériel jetable : il n'ouvre que ce bastion, sur un réseau de test.
set -eu

ssh-keygen -A >/dev/null
ssh-keygen -q -t ed25519 -N '' -C test-client -f /tmp/client
install -d -m 700 -o jump -g jump /home/jump/.ssh
install -m 600 -o jump -g jump /tmp/client.pub /home/jump/.ssh/authorized_keys

install -m 644 /tmp/client /keys/client_key
ssh-keygen -l -f /etc/ssh/ssh_host_ed25519_key.pub | cut -d' ' -f2 > /keys/host_fingerprint
rm -f /tmp/client /tmp/client.pub

exec /usr/sbin/sshd -D -e
