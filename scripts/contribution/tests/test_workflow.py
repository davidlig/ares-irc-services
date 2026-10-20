"""Structural trust-boundary checks supplement the executable fake-API tests."""

import hashlib
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[3]
CHECKOUT = "d23441a48e516b6c34aea4fa41551a30e30af803"
APP_TOKEN = "fee1f7d63c2ff003460e3d139729b119787bc349"


class WorkflowTests(unittest.TestCase):
    def policy(self):
        return (ROOT / ".github/workflows/contribution-policy.yml").read_text()

    def test_privileged_job_runs_only_trusted_main_and_pinned_official_actions(self):
        workflow = self.policy()
        actions = re.findall(r"uses: ([^\s]+)", workflow)
        self.assertEqual(["actions/checkout@" + CHECKOUT, "actions/create-github-app-token@" + APP_TOKEN], actions)
        self.assertIn("repository: davidlig/ares-irc-services", workflow)
        self.assertIn("ref: refs/heads/main", workflow)
        self.assertIn("persist-credentials: false", workflow)
        self.assertIn("runs-on: ubuntu-latest", workflow)
        for forbidden in ("self-hosted", "head_ref", "head.sha", "upload-artifact", "download-artifact", "cache@", "composer", "npm ", "pip ", "pull_request:"):
            self.assertNotIn(forbidden, workflow)
        self.assertNotRegex(workflow, r"\$\{\{\s*github\.event\.[^}]*(?:body|title|head_ref)")

    def test_app_scoped_checks_and_metadata_permissions_are_distinct(self):
        workflow = self.policy()
        self.assertIn("contents: read", workflow)
        self.assertIn("issues: write", workflow)
        self.assertIn("pull-requests: write", workflow)
        self.assertNotRegex(workflow, r"(?m)^\s+checks: write")
        self.assertIn("permission-checks: write", workflow)
        self.assertIn("owner: davidlig", workflow)
        self.assertIn("repositories: ares-irc-services", workflow)
        self.assertIn("vars.CONTRIBUTION_APP_ID", workflow)
        self.assertIn("secrets.CONTRIBUTION_APP_PRIVATE_KEY", workflow)
        self.assertIn("CONTRIBUTION_CHECK_TOKEN: ${{ steps.contribution-app.outputs.token }}", workflow)
        self.assertIn("GITHUB_TOKEN: ${{ github.token }}", workflow)

    def test_fresh_scan_wakeups_and_repo_wide_serialization(self):
        workflow = self.policy()
        for word in ("pull_request_target:", "opened", "reopened", "edited", "synchronize", "ready_for_review", "closed",
                     "issues:", "labeled", "unlabeled", "transferred", "deleted", "label:", "push:", "branches: [main]",
                     "workflow_run:", "Contribution review signal", "completed", "workflow_dispatch:", "7,22,37,52 * * * *"):
            self.assertIn(word, workflow)
        self.assertIn("group: contribution-policy", workflow)
        self.assertIn("cancel-in-progress: false", workflow)
        self.assertIn("github.event.workflow_run.repository.id", workflow)
        self.assertNotIn("workflow_run.conclusion", workflow)
        self.assertIn("1167938167", workflow)
        self.assertIn("561369", workflow)
        self.assertIn("python3 scripts/contribution/reconcile.py", workflow)

    def test_grant_comment_and_issue_lifecycle_wakeups(self):
        workflow = self.policy()
        self.assertRegex(workflow, r"issue_comment:\s+types: \[created, edited, deleted\]")
        self.assertRegex(workflow, r"issues:\s+types: \[[^\]]*closed[^\]]*reopened[^\]]*\]")

    def test_review_signal_has_no_privileges_checkout_artifacts_or_event_text(self):
        workflow = (ROOT / ".github/workflows/contribution-review-signal.yml").read_text()
        self.assertIn("name: Contribution review signal", workflow)
        self.assertIn("types: [submitted, edited, dismissed]", workflow)
        self.assertIn("permissions: {}", workflow)
        self.assertIn("runs-on: ubuntu-latest", workflow)
        for forbidden in ("uses:", "secrets.", "github.event.", "artifact", "self-hosted", "checkout"):
            self.assertNotIn(forbidden, workflow)
        self.assertEqual(["'true'"], re.findall(r"run: (.+)", workflow))
        subprocess.run(["bash", "-n"], input="true\n", text=True, capture_output=True, check=True)

    def test_ci_adds_only_policy_test_step_and_preserves_deploy_bytes(self):
        workflow = (ROOT / ".github/workflows/ci.yml").read_text()
        self.assertIn("PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s scripts/contribution/tests -v", workflow)
        deployment = workflow.split("\n  deploy:\n", 1)[1]
        self.assertEqual("8bf02032a65cc8ebeb198f5f4b6986ea7e72a9af918facd6f5f3f5d0e2e4627b", hashlib.sha256(deployment.encode()).hexdigest())

    def test_codeowners_protects_workflows_itself_and_all_feature_scope(self):
        self.assertEqual("* @davidlig", (ROOT / ".github/CODEOWNERS").read_text().strip())

    def test_template_examples_are_inert_and_activation_pending_is_explicit(self):
        import sys
        sys.path.insert(0, str(ROOT / "scripts/contribution"))
        from policy import parse_closing_references
        template = (ROOT / ".github/PULL_REQUEST_TEMPLATE.md").read_text()
        parsed = parse_closing_references(template, "davidlig/ares-irc-services")
        self.assertEqual((), parsed.references)
        self.assertIn("not active", (ROOT / "CONTRIBUTING.md").read_text())
        guide = (ROOT / "docs/contribution-policy-setup.md").read_text()
        self.assertIn("PENDING", guide)
        self.assertIn("expected source", guide)
        self.assertIn("561369", guide)
        self.assertIn("pull_request", guide)


if __name__ == "__main__":
    unittest.main()
