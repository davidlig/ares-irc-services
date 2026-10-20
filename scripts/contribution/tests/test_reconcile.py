"""Exercise the real HTTP adapter and reconciler against an in-memory GitHub API."""

from copy import deepcopy
from io import BytesIO
import json
from pathlib import Path
import sys
import unittest
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlsplit

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from policy import PolicyConfig
from reconcile import ApiError, Github, NoRedirect, Reconciler

REPO = "davidlig/ares-irc-services"
PREFIX = "/repos/" + REPO
OWNER = {"id": 561369, "login": "davidlig", "type": "User"}
AUTHOR = {"id": 123, "login": "contributor", "type": "User"}
BOT = {"id": 41898282, "login": "github-actions[bot]", "type": "Bot"}
APP_ID = 9876
HEAD = "a" * 40
MERGE = "b" * 40
BASE = "c" * 40
CONFIG = PolicyConfig(1167938167, REPO, 561369, frozenset({561369}))


def issue(number=12):
    return {"number": number, "labels": [{"name": "approved"}], "state": "open"}


def label_event(identifier=1, actor=None, action="labeled", when="2026-10-18T00:00:00Z"):
    return {"id": identifier, "event": action, "actor": deepcopy(actor or OWNER),
            "label": {"name": "approved"}, "created_at": when}


def pull(number=1, author=None, head=HEAD, merge=MERGE, body="Fixes #12"):
    return {"id": 1000 + number, "number": number, "state": "open", "body": body,
            "user": deepcopy(author or AUTHOR), "created_at": "2026-10-19T00:00:00Z",
            "head": {"sha": head}, "base": {"sha": BASE, "ref": "main", "repo": {"id": 1167938167}},
            "merge_commit_sha": merge, "mergeable": True, "mergeable_state": "clean"}


def review(identifier=1, state="APPROVED", sha=HEAD, user=None):
    return {"id": identifier, "state": state, "commit_id": sha, "user": deepcopy(user or OWNER),
            "submitted_at": "2026-10-19T01:00:00Z" if state != "PENDING" else None}


class Response(BytesIO):
    def __init__(self, data, headers=None):
        super().__init__(json.dumps(data).encode())
        self.status = 200
        self.headers = headers or {}


class Server:
    """Stateful fake transport, not a replacement for the production policy."""
    def __init__(self):
        self.prs = {1: pull()}
        self.issues = {12: issue()}
        self.events = {12: [label_event()]}
        self.scope_edits = {}
        self.pr_events = {1: []}
        self.reviews = {1: [review()]}
        self.links = {1: []}
        self.permissions = {561369: "admin"}
        self.checks = []
        self.comments = {1: []}
        self.calls = []
        self.hook = None
        self.fail = None
        self.graphql_page_size = 100
        self.repo_id = 1167938167
        self.next_id = 100

    def client(self, token):
        return Github(token, open_url=self.open, sleep=lambda _: None)

    def engine(self):
        return Reconciler(CONFIG, self.client("metadata"), self.client("checks"), APP_ID)

    def open(self, request, timeout):
        parsed = urlsplit(request.full_url)
        path, query = parsed.path, parse_qs(parsed.query)
        token = request.get_header("Authorization").split()[-1]
        method = request.get_method()
        data = json.loads(request.data) if request.data else None
        call = (token, method, path, data)
        self.calls.append(call)
        if self.hook:
            self.hook(self, call)
        if self.fail:
            failure = self.fail(call)
            if failure:
                raise HTTPError(request.full_url, failure, "withheld", {}, BytesIO(b'{}'))
        self.assert_scope(token, method, path)
        if path == PREFIX:
            return Response({"id": self.repo_id, "full_name": REPO, "default_branch": "main", "owner": OWNER})
        if path == "/graphql":
            number = data["variables"]["number"]
            if "issue(number:" in data["query"]:
                return Response({"data": {"repository": {"databaseId": self.repo_id, "issue": {
                    "lastEditedAt": self.scope_edits.get(number)}}}})
            all_links = self.links.get(number, [])
            offset = int(data["variables"].get("cursor") or 0)
            end = offset + self.graphql_page_size
            return Response({"data": {"repository": {"databaseId": self.repo_id, "pullRequest": {
                "closingIssuesReferences": {"totalCount": len(all_links), "nodes": deepcopy(all_links[offset:end]),
                    "pageInfo": {"hasNextPage": end < len(all_links), "endCursor": str(end)}}}}}})
        relative = path.removeprefix(PREFIX)
        if relative == "/pulls" and method == "GET":
            return self.page([p for p in self.prs.values() if p["state"] == "open"], path, query)
        if relative == "/issues" and method == "GET":
            return self.page([i for i in self.issues.values() if any(l["name"] == "approved" for l in i["labels"])], path, query)
        parts = relative.strip("/").split("/")
        if parts[0] == "pulls" and len(parts) == 2:
            pr = self.prs[int(parts[1])]
            if method == "PATCH":
                pr["state"] = data["state"]
            return Response(deepcopy(pr))
        if parts[0] == "pulls" and parts[-1] == "reviews":
            return self.page(self.reviews.get(int(parts[1]), []), path, query)
        if parts[0] == "issues" and len(parts) == 2:
            item = self.issues.get(int(parts[1]))
            if item is None:
                raise HTTPError(request.full_url, 404, "not found", {}, BytesIO(b'{}'))
            return Response(deepcopy(item))
        if parts[0] == "issues" and parts[-1] == "events":
            number = int(parts[1])
            return self.page(self.events.get(number, []) if number in self.issues else self.pr_events.get(number, []), path, query)
        if parts[0] == "issues" and len(parts) == 4 and parts[2] == "labels":
            number = int(parts[1])
            self.issues[number]["labels"] = [l for l in self.issues[number]["labels"] if l["name"] != "approved"]
            self.events.setdefault(number, []).append(label_event(9999, BOT, "unlabeled", "2026-10-20T00:00:00Z"))
            return Response(self.issues[number]["labels"])
        if parts[0] == "collaborators":
            return Response({"permission": self.permissions.get(561369, "read"), "user": deepcopy(OWNER)})
        if parts[0] == "commits" and parts[-1] == "check-runs":
            runs = [c for c in self.checks if c["head_sha"] == parts[1]]
            return self.page(runs, path, query, "check_runs")
        if relative == "/check-runs" and method == "POST":
            self.next_id += 1
            run = {**data, "id": self.next_id, "app": {"id": APP_ID}}
            self.checks.append(run)
            return Response(deepcopy(run))
        if parts[0] == "check-runs" and method == "PATCH":
            run = next(c for c in self.checks if c["id"] == int(parts[1]))
            run.update(data)
            if data["status"] == "in_progress":
                run["conclusion"] = None
            return Response(deepcopy(run))
        if parts[0] == "issues" and parts[-1] == "comments":
            number = int(parts[1])
            if method == "GET":
                return self.page(self.comments.get(number, []), path, query)
            self.next_id += 1
            comment = {"id": self.next_id, "body": data["body"], "user": deepcopy(BOT)}
            self.comments.setdefault(number, []).append(comment)
            return Response(deepcopy(comment))
        if parts[:2] == ["issues", "comments"] and method == "PATCH":
            comment = next(c for values in self.comments.values() for c in values if c["id"] == int(parts[2]))
            comment.update(data)
            return Response(deepcopy(comment))
        raise AssertionError(f"Unhandled fake route: {method} {path}")

    def page(self, items, path, query, key=None):
        page = int(query.get("page", ["1"])[0])
        subset = deepcopy(items[(page - 1) * 100:page * 100])
        headers = {}
        if page * 100 < len(items):
            from urllib.parse import urlencode
            parameters = {k: v[0] for k, v in query.items()}
            parameters["page"] = page + 1
            headers["Link"] = f'<https://api.github.com{path}?{urlencode(parameters)}>; rel="next"'
        return Response({key: subset, "total_count": len(items)} if key else subset, headers)

    def assert_scope(self, token, method, path):
        if "/check-runs" in path:
            assert token == "checks", "Checks must never use GITHUB_TOKEN"
        elif token == "checks":
            assert method == "GET" and path == PREFIX, "App token is checks + metadata only"
        else:
            assert token == "metadata", "Metadata/mutations use the metadata client"

    def conclusion(self, sha):
        return [r.get("conclusion") for r in self.checks if r["head_sha"] == sha and r["app"]["id"] == APP_ID][-1]


class AdapterTests(unittest.TestCase):
    def test_valid_external_pr_gets_head_and_merge_success(self):
        server = Server()
        result = server.engine().run()
        self.assertTrue(result.ok)
        self.assertEqual("success", server.conclusion(HEAD))
        self.assertEqual("success", server.conclusion(MERGE))
        self.assertEqual("open", server.prs[1]["state"])

    def test_missing_review_blocks_both_checks_without_closing(self):
        server = Server()
        server.reviews[1] = [review(state="PENDING")]
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("failure", server.conclusion(HEAD))
        self.assertEqual("open", server.prs[1]["state"])
        self.assertFalse(server.comments[1])

    def test_owner_author_exception_not_merger_exception(self):
        server = Server()
        server.prs[1]["user"] = deepcopy(OWNER)
        server.reviews[1] = []
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("success", server.conclusion(HEAD))

    def test_invalid_issue_checks_then_comment_then_close(self):
        server = Server()
        server.issues[12]["labels"] = []
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("closed", server.prs[1]["state"])
        writes = [(m, p, d) for _, m, p, d in server.calls if m != "GET"]
        close = next(i for i, (m, p, _) in enumerate(writes) if m == "PATCH" and p == PREFIX + "/pulls/1")
        comment = next(i for i, (m, p, _) in enumerate(writes) if m == "POST" and p.endswith("/comments"))
        blocked = [i for i, (_, p, d) in enumerate(writes) if "/check-runs" in p and d.get("status") == "in_progress"]
        self.assertTrue(blocked)
        self.assertLess(max(blocked[:2]), comment)
        self.assertLess(comment, close)

    def test_comment_failure_never_closes_and_replay_reuses_bot_comment(self):
        server = Server()
        server.issues[12]["labels"] = []
        server.fail = lambda c: 403 if c[1] == "POST" and c[2].endswith("/comments") else None
        self.assertFalse(server.engine().run().ok)
        self.assertEqual("open", server.prs[1]["state"])
        self.assertNotEqual("success", server.conclusion(HEAD))
        server.fail = None
        self.assertTrue(server.engine().run().ok)
        server.prs[1]["state"] = "open"
        self.assertTrue(server.engine().run().ok)
        self.assertEqual(1, len(server.comments[1]))

    def test_forged_marker_comment_does_not_impersonate_actions_bot(self):
        server = Server()
        server.issues[12]["labels"] = []
        server.comments[1] = [{"id": 9, "user": AUTHOR, "body": "<!-- ares-contribution-policy:v1 -->"}]
        self.assertTrue(server.engine().run().ok)
        self.assertEqual(2, len(server.comments[1]))
        self.assertEqual(BOT["id"], server.comments[1][-1]["user"]["id"])

    def test_foreign_manual_link_and_pull_request_issue_close(self):
        for kind in ("foreign", "pull", "missing", "second"):
            with self.subTest(kind=kind):
                server = Server()
                if kind == "foreign":
                    server.links[1] = [{"number": 12, "repository": {"nameWithOwner": "other/repo", "databaseId": 44}}]
                elif kind == "pull":
                    server.issues[12]["pull_request"] = {}
                elif kind == "missing":
                    del server.issues[12]
                else:
                    server.prs[1]["body"] = "Fixes #12, #13"
                self.assertTrue(server.engine().run().ok)
                self.assertEqual("closed", server.prs[1]["state"])

    def test_transient_reads_never_become_policy_violation_or_success(self):
        for code in (403, 429, 500):
            server = Server()
            server.fail = lambda c: code if c[1] == "GET" and c[2] == PREFIX + "/issues/12" else None
            self.assertFalse(server.engine().run().ok)
            self.assertEqual("open", server.prs[1]["state"])
            self.assertFalse(server.comments[1])
            self.assertNotEqual("success", server.conclusion(HEAD))

    def test_unauthorized_label_removed_without_pr_and_no_sender_trust(self):
        server = Server()
        server.prs = {}
        server.events[12] = [label_event(actor=AUTHOR)]
        self.assertTrue(server.engine().run(trigger_issue=12).ok)
        self.assertFalse(server.issues[12]["labels"])

    def test_later_legitimate_label_is_not_deleted_and_removed_permissions_revoke(self):
        server = Server()
        server.events[12] = [label_event(actor=AUTHOR), label_event(identifier=2)]
        self.assertTrue(server.engine().run(trigger_issue=12).ok)
        self.assertTrue(server.issues[12]["labels"])
        server.permissions[561369] = "read"
        self.assertTrue(server.engine().run().ok)
        self.assertFalse(server.issues[12]["labels"])
        self.assertEqual("closed", server.prs[1]["state"])

    def test_bot_label_removal_reconciles_without_retriggered_event(self):
        server = Server()
        server.events[12] = [label_event(actor=AUTHOR)]
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("closed", server.prs[1]["state"])
        self.assertFalse(server.issues[12]["labels"])

    def test_post_open_approval_and_reopening_timing(self):
        server = Server()
        server.events[12] = [label_event(when="2026-10-20T00:00:00Z")]
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("closed", server.prs[1]["state"])
        server.prs[1]["state"] = "open"
        server.pr_events[1] = [{"id": 10, "event": "reopened", "created_at": "2026-10-21T00:00:00Z"}]
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("success", server.conclusion(HEAD))

    def test_shared_sha_invalid_sibling_closes_then_remaining_pr_succeeds(self):
        server = Server()
        server.prs[2] = pull(2, head=MERGE, merge="d" * 40, body="Fixes #99")
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("closed", server.prs[2]["state"])
        self.assertEqual("open", server.prs[1]["state"])
        self.assertEqual("success", server.conclusion(MERGE))

    def test_shared_sha_missing_review_blocks_head_merge_cross_role(self):
        server = Server()
        server.prs[2] = pull(2, head=MERGE, merge="d" * 40)
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("failure", server.conclusion(MERGE))
        self.assertEqual("open", server.prs[2]["state"])

    def test_unknown_merge_sha_or_mergeability_never_succeeds(self):
        for field, value in (("merge_commit_sha", None), ("mergeable", None), ("mergeable_state", "unknown")):
            server = Server()
            server.prs[1][field] = value
            self.assertTrue(server.engine().run().ok)
            self.assertEqual("failure", server.conclusion(HEAD))

    def test_forged_same_name_actions_check_is_never_updated(self):
        server = Server()
        forged = {"id": 4, "name": "Contribution policy", "external_id": "ares-contribution-policy:v1:1167938167:" + HEAD,
                  "head_sha": HEAD, "app": {"id": 15368}, "status": "completed", "conclusion": "success"}
        server.checks = [deepcopy(forged)]
        self.assertTrue(server.engine().run().ok)
        self.assertEqual(forged, server.checks[0])
        self.assertFalse(any(m == "PATCH" and p == PREFIX + "/check-runs/4" for _, m, p, _ in server.calls))

    def test_repeated_runs_update_mutable_check_runs_not_append(self):
        server = Server()
        self.assertTrue(server.engine().run().ok)
        self.assertTrue(server.engine().run().ok)
        self.assertEqual(2, len(server.checks))

    def test_body_head_base_and_links_change_during_terminal_writes(self):
        for change in ("body", "head", "base", "merge", "links", "issue", "permission"):
            with self.subTest(change=change):
                server = Server()
                changed = []
                def hook(s, c):
                    if c[1] == "PATCH" and "/check-runs/" in c[2] and c[3].get("conclusion") == "success" and not changed:
                        changed.append(True)
                        if change == "body": s.prs[1]["body"] = "Fixes #99"
                        if change == "head": s.prs[1]["head"]["sha"] = "e" * 40
                        if change == "base":
                            s.prs[1]["base"]["sha"] = "e" * 40
                            s.prs[1]["merge_commit_sha"] = None
                        if change == "merge":
                            s.prs[1]["merge_commit_sha"] = "e" * 40
                            s.prs[1]["mergeable"] = None
                        if change == "issue": s.issues[12]["labels"] = []
                        if change == "permission": s.permissions[561369] = "read"
                        if change == "links":
                            s.links[1] = [{"number": 99, "repository": {"nameWithOwner": REPO, "databaseId": 1167938167}}]
                server.hook = hook
                server.engine().run()
                self.assertTrue(changed)
                self.assertNotEqual("success", server.conclusion(HEAD))

    def test_changed_issue_before_close_prevents_stale_closure(self):
        server = Server()
        server.issues[12]["labels"] = []
        changed = []
        def hook(s, c):
            if c[1] == "POST" and c[2].endswith("/comments") and not changed:
                changed.append(True)
                s.issues[12]["labels"] = [{"name": "approved"}]
        server.hook = hook
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("open", server.prs[1]["state"])
        self.assertEqual("success", server.conclusion(HEAD))

    def test_identity_mismatch_and_permission_response_id_mismatch_fail_closed(self):
        server = Server()
        server.repo_id = 1
        self.assertFalse(server.engine().run().ok)
        self.assertEqual("open", server.prs[1]["state"])
        server = Server()
        original = server.open
        def wrong_user(request, timeout):
            if "/collaborators/" in request.full_url:
                return Response({"permission": "admin", "user": AUTHOR})
            return original(request, timeout)
        server.client = lambda token: Github(token, open_url=wrong_user, sleep=lambda _: None)
        self.assertFalse(server.engine().run().ok)
        self.assertEqual("open", server.prs[1]["state"])

    def test_reviews_label_events_and_graphql_paginate_past_first_hundred(self):
        server = Server()
        server.reviews[1] = [review(i, state="COMMENTED") for i in range(1, 101)] + [review(101)]
        server.events[12] = [label_event(i, actor=AUTHOR) for i in range(1, 101)] + [label_event(101)]
        server.links[1] = [{"number": n, "repository": {"nameWithOwner": REPO, "databaseId": 1167938167}} for n in range(12, 113)]
        for number in range(13, 113):
            server.issues[number] = issue(number)
            server.events[number] = [label_event()]
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("success", server.conclusion(HEAD))

    def test_required_invalidation_write_failure_prevents_comment_and_closure(self):
        server = Server()
        server.issues[12]["labels"] = []
        server.fail = lambda c: 403 if c[0] == "checks" and c[1] in {"POST", "PATCH"} else None
        self.assertFalse(server.engine().run().ok)
        self.assertEqual("open", server.prs[1]["state"])
        self.assertFalse(server.comments[1])

    def test_current_permissions_restored_during_label_audit_preserve_approval(self):
        server = Server()
        server.prs = {}
        server.permissions[561369] = "read"
        calls = []
        def hook(s, c):
            if "/collaborators/" in c[2]:
                calls.append(c)
                if len(calls) == 2:
                    s.permissions[561369] = "admin"
        server.hook = hook
        self.assertTrue(server.engine().run().ok)
        self.assertTrue(server.issues[12]["labels"])

    def test_unique_issue_and_current_permission_are_cached_within_snapshot(self):
        server = Server()
        server.prs[2] = pull(2)
        server.reviews[2] = [review()]
        engine = server.engine()
        snapshot = engine._snapshot()
        self.assertEqual(2, len(snapshot))
        self.assertEqual(1, sum(m == "GET" and p == PREFIX + "/issues/12" for _, m, p, _ in server.calls))
        self.assertEqual(1, sum("/collaborators/" in p for _, _, p, _ in server.calls))

    def test_review_state_mapping_does_not_erase_changes_request_with_comment(self):
        server = Server()
        server.reviews[1] = [review(), review(2, "CHANGES_REQUESTED"), review(3, "COMMENTED")]
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("failure", server.conclusion(HEAD))
        server.reviews[1].append(review(4))
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("success", server.conclusion(HEAD))
        server.reviews[1][-1]["state"] = "DISMISSED"
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("failure", server.conclusion(HEAD))

    def test_open_pr_scan_pagination_retains_second_page(self):
        server = Server()
        for number in range(2, 102):
            server.prs[number] = pull(number, author=OWNER)
        engine = server.engine()
        self.assertEqual(101, len(engine._snapshot()))

    def test_partial_open_pr_scan_invalidates_known_first_page_success(self):
        server = Server()
        self.assertTrue(server.engine().run().ok)
        for number in range(2, 102):
            server.prs[number] = pull(number, author=OWNER)
        original = server.open
        def fail_second_page(request, timeout):
            if urlsplit(request.full_url).path == PREFIX + "/pulls" and "page=2" in request.full_url:
                raise HTTPError(request.full_url, 403, "withheld", {}, BytesIO(b'{}'))
            return original(request, timeout)
        server.client = lambda token: Github(token, open_url=fail_second_page, sleep=lambda _: None)
        self.assertFalse(server.engine().run().ok)
        self.assertEqual("failure", server.conclusion(HEAD))
        self.assertEqual("open", server.prs[1]["state"])

    def test_missing_snapshot_fields_are_unknown_not_permission_to_close(self):
        for field in ("labels", "number"):
            server = Server()
            del server.issues[12][field]
            self.assertFalse(server.engine().run().ok)
            self.assertEqual("open", server.prs[1]["state"])
            self.assertFalse(server.comments[1])

    def test_issue_body_and_title_scope_edits_require_fresh_approval(self):
        for kind in ("body", "title"):
            server = Server()
            if kind == "body":
                server.scope_edits[12] = "2026-10-18T02:00:00Z"
            else:
                server.events[12].append({"id": 2, "event": "renamed", "created_at": "2026-10-18T02:00:00Z"})
            self.assertTrue(server.engine().run().ok)
            self.assertFalse(server.issues[12]["labels"])
            self.assertEqual("closed", server.prs[1]["state"])
            server.events[12].append(label_event(10000, when="2026-10-20T00:00:00Z"))
            server.issues[12]["labels"] = [{"name": "approved"}]
            server.prs[1]["state"] = "open"
            server.pr_events[1] = [{"id": 10, "event": "reopened", "created_at": "2026-10-21T00:00:00Z"}]
            self.assertTrue(server.engine().run().ok)
            self.assertTrue(server.issues[12]["labels"])
            self.assertEqual("success", server.conclusion(HEAD))

    def test_old_issue_edit_signal_preserves_newer_maintainer_approval(self):
        server = Server()
        server.prs = {}
        server.scope_edits[12] = "2026-10-17T00:00:00Z"
        self.assertTrue(server.engine().run(trigger_issue=12).ok)
        self.assertTrue(server.issues[12]["labels"])

    def test_latest_legitimate_label_arriving_during_audit_is_preserved(self):
        server = Server()
        server.prs = {}
        server.events[12] = [label_event(actor=AUTHOR)]
        event_reads = []
        def hook(s, c):
            if c[2] == PREFIX + "/issues/12/events":
                event_reads.append(c)
                if len(event_reads) == 2:
                    s.events[12].append(label_event(2))
        server.hook = hook
        self.assertTrue(server.engine().run().ok)
        self.assertTrue(server.issues[12]["labels"])

    def test_comment_after_hundred_untrusted_comments_and_bot_update_is_idempotent(self):
        server = Server()
        server.issues[12]["labels"] = []
        server.comments[1] = [{"id": n, "body": "<!-- ares-contribution-policy:v1 -->", "user": AUTHOR}
                              for n in range(1, 101)] + [{"id": 1000, "body": "<!-- ares-contribution-policy:v1 -->old", "user": BOT}]
        self.assertTrue(server.engine().run().ok)
        self.assertEqual(101, len(server.comments[1]))
        self.assertIn("Thanks", server.comments[1][-1]["body"])

    def test_graphql_final_page_invalid_link_is_not_ignored(self):
        server = Server()
        server.graphql_page_size = 1
        server.links[1] = [{"number": 12, "repository": {"nameWithOwner": REPO, "databaseId": 1167938167}},
                           {"number": 99, "repository": {"nameWithOwner": "foreign/repo", "databaseId": 2}}]
        self.assertTrue(server.engine().run().ok)
        self.assertEqual("closed", server.prs[1]["state"])


class HttpSafetyTests(unittest.TestCase):
    def test_reject_absolute_foreign_and_out_of_scope_paths_before_transport(self):
        server = Server()
        client = server.client("metadata")
        for path in ("https://evil.invalid/", "/repos/other/repo/issues", "//evil.invalid/", PREFIX + "/../secrets"):
            with self.assertRaises(ApiError):
                client.request("GET", path)
        self.assertFalse(server.calls)

    def test_redirect_handler_never_forwards_authorization(self):
        from urllib.request import Request
        with self.assertRaises(ApiError):
            NoRedirect().redirect_request(Request("https://api.github.com", headers={"Authorization": "secret"}),
                                          None, 302, "redirect", {}, "https://evil.invalid")

    def test_timeout_has_bounded_read_retries_and_no_mutation_retry(self):
        calls = []
        def unavailable(request, timeout):
            calls.append(request.get_method())
            raise URLError("sensitive transport detail")
        client = Github("secret", open_url=unavailable, sleep=lambda _: None)
        for method, count in (("GET", 3), ("PATCH", 1)):
            calls.clear()
            with self.assertRaises(ApiError) as caught:
                client.request(method, PREFIX)
            self.assertEqual(count, len(calls))
            self.assertNotIn("sensitive", str(caught.exception))

    def test_pagination_rejects_foreign_nonprogressing_and_missing_next_links(self):
        for link in ('<https://evil.invalid/data>; rel="next"',
                     '<https://api.github.com' + PREFIX + '/issues?per_page=100&page=1>; rel="next"',
                     '<broken>; rel=next'):
            client = Github("secret", open_url=lambda request, timeout: Response([], {"Link": link}), sleep=lambda _: None)
            with self.assertRaises(ApiError):
                client.pages(PREFIX + "/issues")

    def test_graphql_errors_and_malformed_api_data_fail_closed(self):
        for payload in ({"errors": [{"message": "private content"}]}, {"data": None}, []):
            server = Server()
            original = server.open
            def malformed(request, timeout):
                return Response(payload) if request.full_url.endswith("/graphql") else original(request, timeout)
            server.client = lambda token: Github(token, open_url=malformed, sleep=lambda _: None)
            self.assertFalse(server.engine().run().ok)
            self.assertEqual("open", server.prs[1]["state"])
            self.assertNotEqual("success", server.conclusion(HEAD))


if __name__ == "__main__":
    unittest.main()
