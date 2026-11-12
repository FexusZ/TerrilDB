<?php

declare(strict_types=1);

// Faux relais pour UnixSocketTunnelOpenerTest : php fake-relay.php <socket> <fichier de capture> <réponse>.
/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];
[, $socket, $capture, $reply] = $arguments;
$server = stream_socket_server('unix://'.$socket);
if (false === $server) {
    exit(2);
}
fwrite(\STDOUT, "ready\n");
$connection = stream_socket_accept($server, 5);
if (false === $connection) {
    exit(3);
}
file_put_contents($capture, fgets($connection));
fwrite($connection, $reply."\n");
fclose($connection);
