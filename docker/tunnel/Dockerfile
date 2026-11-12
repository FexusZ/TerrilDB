# Conteneur du tunnel SSH (#13) : seul à embarquer `ssh` et seul à sortir vers les bastions. Voir docs/ssh-tunnel.md.
FROM alpine:3

RUN apk add --no-cache openssh-client-default python3 \
    && adduser -D -H -u 1000 -s /sbin/nologin tunnel

COPY relay.py test_relay.py /opt/

USER tunnel
ENTRYPOINT ["python3", "-u", "/opt/relay.py"]
