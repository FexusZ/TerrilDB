# Conteneur du tunnel SSH (#13) : seul à embarquer `ssh` et seul à sortir vers les bastions. Voir docs/ssh-tunnel.md.
FROM alpine:3

# `ssh` refuse de tourner sous un uid sans entrée passwd : l'uid de l'image doit être celui du conteneur (compose le passe).
ARG UID=1000
ARG GID=1000
RUN apk add --no-cache openssh-client-default python3 \
    && addgroup -g "$GID" tunnel \
    && adduser -D -H -u "$UID" -G tunnel -s /sbin/nologin tunnel

COPY relay.py test_relay.py /opt/

USER tunnel
ENTRYPOINT ["python3", "-u", "/opt/relay.py"]
