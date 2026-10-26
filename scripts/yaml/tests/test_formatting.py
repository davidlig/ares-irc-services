"""Exercise the public YAML formatting commands on disposable fixtures."""

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
FIXTURE = r'''enabled:   true
count: 42
ratio: 1.5
missing: null
numeric_text: "007"
items: [one,two]
message: "\x02Hello %nickname%\x0F"
literal: '\x02 stays literal'
help: !irc |-
  \x02Usage\x0F
    %command_prefix%HELP
  \x03 and \x0f, not \x0A or \x20
mail: |
  Hello %nickname%,

  Keep this terminal newline.
'''


class HistorySectionCommentsTest(unittest.TestCase):
    def test_history_sections_have_uniform_standalone_english_headings(self):
        headings = (
            ("history", "HISTORY"),
            ("history.message", "HISTORY MESSAGES"),
            ("history.extra", "HISTORY EXTRA FIELDS"),
        )
        for service in ("nickserv", "chanserv"):
            for locale in "ca de el en es eu fr gl it nl pl pt ro tr".split():
                path = ROOT / "translations" / f"{service}.{locale}.yaml"
                lines = path.read_text().splitlines()
                for key, title in headings:
                    with self.subTest(catalog=path.name, section=key):
                        index = lines.index(key + ":")
                        self.assertEqual("# --- " + title + " ---", lines[index - 1])
                        self.assertEqual("", lines[index - 2])
                        # A heading must not retain a preceding localized duplicate.
                        cursor = index - 2
                        while cursor >= 0 and (not lines[cursor].strip() or lines[cursor].startswith("#")):
                            self.assertFalse(lines[cursor].startswith("#"))
                            cursor -= 1


class YamlFormattingTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="ares-yaml-test-")
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        for name in ("composer.json", "package.json", ".prettierrc.json"):
            if (ROOT / name).is_file():
                shutil.copyfile(ROOT / name, self.directory / name)
        if (ROOT / "node_modules").is_dir():
            (self.directory / "node_modules").symlink_to(ROOT / "node_modules", target_is_directory=True)
        self.paths = (
            "config/nested/example.yml", "translations/example.en.yaml",
            ".github/workflows/example.yaml", ".github/workflows/nested/example.yml", "deptrac.yaml",
        )
        for name in self.paths:
            path = self.directory / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(FIXTURE.replace("\n", "\r\n").encode())
        # Files outside the declared scope must not be normalized.
        (self.directory / "outside.yaml").write_text("outside:   unchanged\n")

    def command(self, *arguments):
        environment = dict(os.environ, COMPOSER_NO_INTERACTION="1")
        return subprocess.run(
            arguments, cwd=self.directory, env=environment,
            capture_output=True, text=True, check=False,
        )

    def snapshot(self):
        return {name: (self.directory / name).read_bytes() for name in (*self.paths, "outside.yaml")}

    def parsed(self):
        # PHP serialization preserves scalar types, custom tag objects and exact string bytes.
        code = (
            'require $argv[1]; foreach (array_slice($argv, 2) as $file) {'
            'echo base64_encode(serialize(Symfony\\Component\\Yaml\\Yaml::parseFile('
            '$file, Symfony\\Component\\Yaml\\Yaml::PARSE_CUSTOM_TAGS))), "\\n"; }'
        )
        result = self.command("php", "-r", code, str(ROOT / "vendor/autoload.php"), *self.paths)
        self.assertEqual(0, result.returncode, result.stderr)
        return result.stdout

    def test_check_detects_every_scoped_path_without_writing(self):
        before = self.snapshot()
        result = self.command("composer", "yaml-check")
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        for name in self.paths:
            self.assertIn(name, result.stdout + result.stderr)
        self.assertEqual(before, self.snapshot())

    def test_fix_preserves_symfony_values_and_tags_and_is_idempotent(self):
        before = self.snapshot()
        parsed = self.parsed()
        result = self.command("composer", "yaml-fix")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(parsed, self.parsed())
        formatted = self.snapshot()
        self.assertEqual(before["outside.yaml"], formatted["outside.yaml"])
        for name in self.paths:
            self.assertNotEqual(before[name], formatted[name])
            self.assertNotIn(b"\r", formatted[name])
            self.assertIn(b"help: !irc |-\n", formatted[name])
        result = self.command("npm", "run", "yaml:fix")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(formatted, self.snapshot())

    def test_check_accepts_normalized_files_without_writing(self):
        result = self.command("npm", "run", "yaml:fix")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        before = self.snapshot()
        for arguments in (("npm", "run", "yaml:check"), ("composer", "yaml-check")):
            result = self.command(*arguments)
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
            self.assertEqual(before, self.snapshot())

    def test_ci_checks_yaml_immediately_after_php_cs_fixer(self):
        code = (
            'require $argv[1]; echo json_encode(Symfony\\Component\\Yaml\\Yaml::parseFile($argv[2]));'
        )
        result = self.command("php", "-r", code, str(ROOT / "vendor/autoload.php"), str(ROOT / ".github/workflows/ci.yml"))
        self.assertEqual(0, result.returncode, result.stderr)
        steps = json.loads(result.stdout)["jobs"]["test"]["steps"]
        cs_index = next(i for i, step in enumerate(steps) if "php-cs-fixer" in step.get("run", ""))
        self.assertEqual("composer yaml-check", steps[cs_index + 1].get("run"))
        setup_index = next(i for i, step in enumerate(steps) if step.get("uses", "").startswith("actions/setup-node@"))
        self.assertEqual("24", str(steps[setup_index]["with"]["node-version"]))
        install_index = next(i for i, step in enumerate(steps) if step.get("run") == "npm ci")
        self.assertLess(setup_index, install_index)
        self.assertLess(install_index, cs_index)
        dependencies_index = next(i for i, step in enumerate(steps) if step.get("run", "").startswith("composer install "))
        tests_index = next(i for i, step in enumerate(steps) if step.get("name") == "Test YAML formatting commands")
        self.assertLess(dependencies_index, tests_index)


if __name__ == "__main__":
    unittest.main()
