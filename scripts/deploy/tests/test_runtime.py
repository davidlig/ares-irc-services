"""Runtime persistence and environment synchronization regression tests."""

import os
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
SYNC = ROOT / "docker/sync-env.sh"


class RuntimeTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.env_file = Path(self.directory.name) / ".env"
        self.local_file = Path(self.directory.name) / ".env.local"
        self.local_file.write_text("EXISTING=production\n")

    def run_sync(self):
        return subprocess.run(
            ["sh", str(SYNC)],
            env={**os.environ, "ENV_FILE": str(self.env_file),
                 "LOCAL_FILE": str(self.local_file)},
            capture_output=True, text=True, check=False,
        )

    def test_sync_adds_both_blocks_without_changing_existing_values(self):
        self.env_file.write_text(
            "IGNORED=value\n###> ares/irc-link ###\n"
            "EXISTING=default\n# Link description\nLINK_KEY=link\n"
            "###< ares/irc-link ###\n###> ares/services ###\n"
            "# Service description\nSERVICE_KEY=service\n"
            "###< ares/services ###\n"
        )
        result = self.run_sync()
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(
            "EXISTING=production\n# Link description\nLINK_KEY=link\n"
            "# Service description\nSERVICE_KEY=service\n",
            self.local_file.read_text(),
        )
        self.assertIn("Synced 2 new configuration key(s)", result.stdout)
        before = self.local_file.read_bytes()
        repeated = self.run_sync()
        self.assertEqual(0, repeated.returncode, repeated.stderr)
        self.assertEqual(before, self.local_file.read_bytes())
        self.assertIn("already up to date", repeated.stdout)

    def test_optional_variables_and_comments_are_preserved(self):
        self.env_file.write_text(
            "###> ares/irc-link ###\n# Optional description\n"
            "# OPTIONAL_KEY=default\n###< ares/irc-link ###\n"
            "###> ares/services ###\n# Existing description\n"
            "# EXISTING=default\n###< ares/services ###\n"
        )
        result = self.run_sync()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(
            "EXISTING=production\n# Optional description\n"
            "# OPTIONAL_KEY=default\n", self.local_file.read_text(),
        )
        self.assertIn("Synced 1 new configuration key(s)", result.stdout)
        before = self.local_file.read_bytes()
        self.assertEqual(0, self.run_sync().returncode)
        self.assertEqual(before, self.local_file.read_bytes())

    def test_existing_optional_variable_is_not_enabled_or_duplicated(self):
        self.local_file.write_text("# OPTIONAL_KEY=production\n")
        self.env_file.write_text(
            "###> ares/irc-link ###\nOPTIONAL_KEY=default\n"
            "###< ares/irc-link ###\n"
        )
        result = self.run_sync()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("# OPTIONAL_KEY=production\n", self.local_file.read_text())

    def test_count_is_not_limited_to_process_exit_status(self):
        self.env_file.write_text(
            "###> ares/irc-link ###\n" +
            "".join(f"KEY_{number}=value\n" for number in range(300)) +
            "###< ares/irc-link ###\n###> ares/services ###\n"
            "SERVICE_KEY=value\n###< ares/services ###\n"
        )
        result = self.run_sync()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("Synced 301 new configuration key(s)", result.stdout)
        self.assertEqual(302, len(self.local_file.read_text().splitlines()))

    def test_missing_template_fails_without_modifying_config(self):
        before = self.local_file.read_bytes()
        result = self.run_sync()
        self.assertNotEqual(0, result.returncode)
        self.assertIn(str(self.env_file), result.stdout)
        self.assertEqual(before, self.local_file.read_bytes())

    def test_missing_local_config_fails_without_creating_it(self):
        self.env_file.write_text("###> ares/irc-link ###\nKEY=value\n")
        self.local_file.unlink()
        result = self.run_sync()
        self.assertNotEqual(0, result.returncode)
        self.assertIn(str(self.local_file), result.stdout)
        self.assertFalse(self.local_file.exists())

    def test_entrypoint_generates_secret_without_logging_any_part(self):
        for initial in ("", "APP_SECRET=changeme\n"):
            with self.subTest(initial=initial):
                app = Path(self.directory.name) / "app"
                (app / "docker").mkdir(parents=True, exist_ok=True)
                (app / "vendor").mkdir(exist_ok=True)
                (app / "vendor/autoload.php").touch()
                (app / ".env").write_text("")
                (app / ".env.local").write_text(initial)
                sync = app / "docker/sync-env.sh"
                sync.write_text("#!/bin/sh\nexit 0\n")
                sync.chmod(0o700)
                php = app / "php"
                secret = "0123456789abcdef0123456789abcdef"
                php.write_text(
                    '#!/bin/sh\nif [ "$1" = "-r" ]; then\n'
                    f"    printf '%s' '{secret}'\nfi\n"
                )
                php.chmod(0o700)
                entrypoint = app / "entrypoint.sh"
                entrypoint.write_text(
                    (ROOT / "docker/entrypoint.sh").read_text()
                    .replace("/app", str(app))
                    .replace("/tmp/env.tmp", str(app / "env.tmp"))
                )
                result = subprocess.run(
                    ["sh", str(entrypoint), "true"],
                    env={**os.environ, "PATH": f"{app}:{os.environ['PATH']}"},
                    capture_output=True, text=True, check=False,
                )
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertIn(f"APP_SECRET={secret}", (app / ".env.local").read_text())
                self.assertIn("APP_SECRET generated", result.stdout)
                self.assertNotIn(secret[:8], result.stdout + result.stderr)

    def test_make_passes_production_override_to_every_compose_operation(self):
        release = Path(self.directory.name) / "release"
        release.mkdir()
        (release / "Makefile").write_text((ROOT / "Makefile").read_text())
        (release / ".env.local").touch()
        override = str(release / "docker/compose.production.yaml")
        targets = (
            "build", "build-no-cache", "up", "down", "logs", "logs-tail",
            "ps", "shell", "clean", "restart", "db-shell", "db-migrate",
            "db-restore", "config-show", "health",
        )
        for production in (False, True):
            for target in targets:
                with self.subTest(production=production, target=target):
                    environment = {**os.environ, "ARES_COMPOSE_OVERRIDE":
                                   override if production else ""}
                    result = subprocess.run(
                        ["make", "-n", target, "DOCKER_COMPOSE=compose-spy",
                         "FILE=fixture.db"], cwd=release, env=environment,
                        capture_output=True, text=True, check=False,
                    )
                    self.assertEqual(0, result.returncode, result.stderr)
                    commands = [line for line in result.stdout.splitlines()
                                if "compose-spy" in line]
                    self.assertTrue(commands, result.stdout)
                    for command in commands:
                        self.assertIn("-f docker/compose.yaml", command)
                        if production:
                            self.assertIn(f"-f {override}", command)
                        else:
                            self.assertNotIn("compose.production.yaml", command)

    def test_host_network_is_production_only(self):
        self.assertNotIn("network_mode: host",
                         (ROOT / "docker/compose.yaml").read_text())
        self.assertIn("network_mode: host",
                      (ROOT / "docker/compose.production.yaml").read_text())

    def test_compose_persistence_overrides_keep_local_defaults(self):
        compose = (ROOT / "docker/compose.yaml").read_text()
        self.assertIn("${ARES_DATA_DIR:-../var/data}:/app/var/data", compose)
        self.assertIn("${ARES_LOG_DIR:-../var/log}:/app/var/log", compose)
        self.assertIn("${ARES_ENV_FILE:-../.env.local}:/app/.env.local", compose)
        self.assertNotIn("/app/.env.local:ro", compose)
        self.assertIn("container_name: ares-irc-services", compose)
        self.assertIn("stop_grace_period: 30s", compose)


if __name__ == "__main__":
    unittest.main()
