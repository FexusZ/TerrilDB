"""Tests du relais (stdlib seulement) : python3 -m unittest discover -s /opt -p 'test_*.py'."""
import os
import tempfile
import unittest

import relay

FP = "SHA256:" + "A" * 43
KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAfaux\n-----END OPENSSH PRIVATE KEY-----\n"


def message(**overrides):
    base = {
        "op": "open", "host": "bastion.example", "port": 22, "user": "tunnel", "private_key": KEY,
        "fingerprint": FP, "target_host": "db.internal", "target_port": 5432,
        "socket_name": "tunnel.sock", "idle_seconds": 300,
    }
    base.update(overrides)
    return base


class FakeTunnel:
    def __init__(self, directory, request):
        self.directory = directory
        self.request = request
        self.running = True

    def alive(self):
        return self.running

    def close(self):
        self.running = False


class FakeFactory:
    def __init__(self):
        self.created = []
        self.error = None

    def __call__(self, directory, request):
        if self.error:
            raise relay.RelayError(self.error)
        tunnel = FakeTunnel(directory, request)
        self.created.append(tunnel)
        return tunnel


class Clock:
    def __init__(self):
        self.now = 1000.0

    def __call__(self):
        return self.now


class RelayTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.factory = FakeFactory()
        self.clock = Clock()

    def relay(self, allowed="bastion.example"):
        return relay.Relay(self.tmp.name, relay.parse_allowed(allowed), self.factory, self.clock)

    def test_empty_allow_list_refuses_everything(self):
        for configured in ("", "  ", ", ,"):
            reply = self.relay(configured).handle(message())
            self.assertEqual({"ok": False, "error": "forbidden_bastion"}, reply)
        self.assertEqual([], self.factory.created)

    def test_host_without_port_means_22_and_port_is_exact(self):
        self.assertTrue(self.relay("bastion.example").handle(message())["ok"])
        self.assertEqual("forbidden_bastion", self.relay("bastion.example").handle(message(port=2222))["error"])
        self.assertTrue(self.relay("Bastion.Example:2222").handle(message(port=2222, host="bastion.example."))["ok"])

    def test_open_returns_a_socket_in_a_private_directory(self):
        reply = self.relay().handle(message(socket_name=".s.PGSQL.5432"))

        self.assertTrue(reply["ok"])
        directory = os.path.join(self.tmp.name, reply["id"])
        self.assertEqual(os.path.join(directory, ".s.PGSQL.5432"), reply["socket"])
        self.assertEqual(0o700, os.stat(directory).st_mode & 0o777)
        self.assertRegex(reply["id"], r"^[0-9a-f]{32}$")

    def test_same_request_reuses_the_open_tunnel(self):
        r = self.relay()
        first = r.handle(message())
        second = r.handle(message())

        self.assertEqual(first, second)
        self.assertEqual(1, len(self.factory.created))

    def test_another_key_or_target_is_another_tunnel(self):
        r = self.relay()
        ids = {r.handle(message())["id"], r.handle(message(target_port=3306))["id"], r.handle(message(private_key=KEY + "\n"))["id"]}

        self.assertEqual(3, len(ids))

    def test_a_dead_tunnel_is_reopened(self):
        r = self.relay()
        first = r.handle(message())
        self.factory.created[0].running = False

        self.assertNotEqual(first["id"], r.handle(message())["id"])

    def test_idle_tunnel_closes_after_the_delay_and_use_renews_it(self):
        r = self.relay()
        reply = r.handle(message(idle_seconds=60))
        self.clock.now += 50
        r.handle(message(idle_seconds=60))
        self.clock.now += 50
        r.sweep()
        self.assertTrue(self.factory.created[0].running)
        self.clock.now += 11
        r.sweep()
        self.assertFalse(self.factory.created[0].running)
        self.assertFalse(os.path.exists(os.path.join(self.tmp.name, reply["id"])))

    def test_the_idle_delay_is_capped_at_five_minutes(self):
        self.assertEqual("invalid_request", self.relay().handle(message(idle_seconds=301))["error"])
        self.assertEqual("invalid_request", self.relay().handle(message(idle_seconds=0))["error"])

    def test_missing_fingerprint_is_refused_without_trust_on_first_use(self):
        reply = self.relay().handle(message(fingerprint=None))

        self.assertEqual({"ok": False, "error": "host_key_unconfirmed"}, reply)
        self.assertEqual([], self.factory.created)

    def test_close_removes_the_tunnel(self):
        r = self.relay()
        reply = r.handle(message())

        self.assertEqual({"ok": True}, r.handle({"op": "close", "id": reply["id"]}))
        self.assertFalse(self.factory.created[0].running)
        self.assertFalse(os.path.exists(os.path.join(self.tmp.name, reply["id"])))
        self.assertEqual({"ok": True}, r.handle({"op": "close", "id": reply["id"]}))

    def test_close_refuses_an_identifier_with_a_trailing_newline(self):
        self.assertEqual("invalid_request", self.relay().handle({"op": "close", "id": "a" * 32 + "\n"})["error"])

    def test_close_refuses_a_path_like_identifier(self):
        self.assertEqual("invalid_request", self.relay().handle({"op": "close", "id": "../x"})["error"])

    def test_invalid_requests(self):
        bad = [
            message(host="bad host"), message(port=0), message(port="22"), message(user="a b"), message(user=""),
            message(private_key="nope"), message(private_key="x" * 20000), message(fingerprint="SHA256:short"),
            message(target_host=""), message(target_host="-oProxyCommand=x"), message(target_port=70000),
            message(socket_name="../x"), message(socket_name="a/b"), message(host="-oProxyCommand=x"),
            message(user="-oProxyCommand=x"), {"op": "nope"}, {},
            message(socket_name="tunnel.sock\n"), message(user="u\n"), message(target_host="db\n"), message(host="bastion.example\n"),
            message(fingerprint=FP + "\n"),
        ]
        for item in bad:
            self.assertEqual("invalid_request", self.relay().handle(item)["error"], item)

    def test_failures_expose_only_a_code(self):
        for code in ("host_key_changed", "unavailable"):
            self.factory.error = code
            self.assertEqual({"ok": False, "error": code}, self.relay().handle(message()))

    def test_a_failed_open_leaves_no_directory_behind(self):
        self.factory.error = "unavailable"
        self.relay().handle(message())

        self.assertEqual([], os.listdir(self.tmp.name))

    def test_replies_never_contain_the_key(self):
        reply = self.relay().handle(message())
        self.assertNotIn("AAAAfaux", repr(reply))


if __name__ == "__main__":
    unittest.main()
