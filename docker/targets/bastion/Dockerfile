# Bastion SSH de test (profil "targets") : sert à prouver le tunnel de bout en bout. Les clés sont générées au démarrage,
# jamais versionnées ; l'utilisateur n'a pas de shell, il ne sait que relayer (AllowTcpForwarding).
FROM alpine:3

RUN apk add --no-cache openssh-server \
    && adduser -D -s /bin/sh jump \
    && echo "jump:$(head -c 32 /dev/urandom | base64)" | chpasswd \
    && printf '%s\n' 'PasswordAuthentication no' 'KbdInteractiveAuthentication no' 'PermitRootLogin no' \
        'AllowUsers jump' 'AllowTcpForwarding local' 'AllowAgentForwarding no' 'X11Forwarding no' \
        'PermitTTY no' 'ForceCommand /bin/false' > /etc/ssh/sshd_config.d/test.conf

COPY entrypoint.sh /entrypoint.sh
ENTRYPOINT ["/bin/sh", "/entrypoint.sh"]
