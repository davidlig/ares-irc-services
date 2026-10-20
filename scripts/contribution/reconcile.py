"""Trusted-main reconciliation, never a runner for contributor code.

GITHUB_TOKEN reads metadata and writes labels/comments/PR state. A separately
scoped installation token is the ONLY writer of the required App check runs.
No event payload, workflow conclusion, artifact, username or PR file is authority.
"""

from dataclasses import dataclass
from datetime import datetime
import json
import os
from pathlib import Path
import re
import time
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, quote, urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

from policy import (Actor, Decision, Issue, IssueReference, LabelEvent, PolicyConfig,
                    PullRequest, Review, evaluate, parse_closing_references)

REPOSITORY = "davidlig/ares-irc-services"
PREFIX = "/repos/" + REPOSITORY
API = "https://api.github.com"
MAX_PAGES = 200
MAX_ROUNDS = 8
MARKER = "<!-- ares-contribution-policy:v1 -->"
COMMENT = (MARKER + "\nThanks for the contribution. This PR needs prior maintainer approval on every linked issue.\n\n"
           "Please check the closing references and approved issue scopes in CONTRIBUTING.md. "
           "After all issues have valid approval, correct the description and reopen the PR yourself. "
           "Approval must precede that reopening. Missing code review alone does not cause closure.")
SHA = re.compile(r"[0-9a-f]{40}")


class ApiError(Exception):
    """Unknown/unavailable metadata, never evidence of a policy violation."""
    def __init__(self, status=None):
        self.status = status
        super().__init__(f"GitHub API unavailable (HTTP {status})" if status else "GitHub API data unavailable")


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ApiError(code)


def mapping(value):
    if not isinstance(value, dict):
        raise ApiError()
    return value


def integer(value):
    if type(value) is not int or value <= 0:
        raise ApiError()
    return value


def string(value):
    if not isinstance(value, str):
        raise ApiError()
    return value


def array(value):
    if not isinstance(value, list):
        raise ApiError()
    return value


def timestamp(value):
    try:
        result = datetime.fromisoformat(string(value).replace("Z", "+00:00"))
        if result.tzinfo is None or result.utcoffset() is None:
            raise ValueError()
        return result
    except (ValueError, TypeError):
        raise ApiError() from None


def sha(value):
    if not isinstance(value, str) or not SHA.fullmatch(value):
        raise ApiError()
    return value


class Github:
    def __init__(self, token, *, open_url=None, sleep=time.sleep):
        if not token:
            raise ApiError()
        self.token = token
        self.open_url = open_url or build_opener(NoRedirect()).open
        self.sleep = sleep

    def request(self, method, path, data=None):
        parsed = urlsplit(path)
        if (parsed.scheme or parsed.netloc or parsed.fragment or ".." in path or "\\" in path
                or any(ord(c) < 32 for c in path)
                or not (parsed.path == "/graphql" or parsed.path == PREFIX or parsed.path.startswith(PREFIX + "/"))):
            raise ApiError()
        read_only = method == "GET" or (method == "POST" and parsed.path == "/graphql")
        for attempt in range(3 if read_only else 1):
            try:
                request = Request(API + path, method=method,
                                  data=json.dumps(data).encode() if data is not None else None,
                                  headers={"Authorization": "Bearer " + self.token,
                                           "Accept": "application/vnd.github+json",
                                           "Content-Type": "application/json",
                                           "X-GitHub-Api-Version": "2022-11-28",
                                           "User-Agent": "ares-contribution-policy"})
                with self.open_url(request, timeout=20) as response:
                    if response.status >= 300:
                        raise ApiError(response.status)
                    raw = response.read(4 * 1024 * 1024 + 1)
                    if len(raw) > 4 * 1024 * 1024:
                        raise ApiError()
                    payload = json.loads(raw) if raw else None
                    return payload, response.headers
            except HTTPError as error:
                retryable = (error.code in {429, 500, 502, 503, 504}
                             or (error.code == 403 and (error.headers.get("Retry-After")
                                                      or error.headers.get("X-RateLimit-Remaining") == "0")))
                error.close()
                if not read_only or not retryable or attempt == 2:
                    raise ApiError(error.code) from None
            except (URLError, TimeoutError, OSError):
                if not read_only or attempt == 2:
                    raise ApiError() from None
            except (json.JSONDecodeError, UnicodeDecodeError):
                raise ApiError() from None
            self.sleep(attempt + 1)
        raise ApiError()

    def get(self, path):
        return self.request("GET", path)[0]

    def pages(self, path, key=None, on_page=None):
        base = urlsplit(path)
        parameters = dict(parse_qs(base.query))
        parameters = {k: v[0] for k, v in parameters.items()}
        parameters["per_page"] = "100"
        result = []
        expected_total = None
        for page in range(1, MAX_PAGES + 1):
            parameters["page"] = str(page)
            payload, headers = self.request("GET", base.path + "?" + urlencode(parameters))
            if key is not None:
                payload = mapping(payload)
                total = payload.get("total_count")
                if type(total) is not int or total < 0 or (expected_total is not None and total != expected_total):
                    raise ApiError()
                expected_total = total
                payload = payload.get(key)
            items = array(payload)
            if on_page is not None:
                on_page(items)
            result.extend(items)
            link = headers.get("Link", "")
            next_links = re.findall(r'<([^>]+)>;\s*rel="next"', link)
            if "next" in link and not next_links:
                raise ApiError()
            if not next_links:
                if expected_total is not None and len(result) != expected_total:
                    raise ApiError()
                return result
            if len(next_links) != 1:
                raise ApiError()
            next_url = urlsplit(next_links[0])
            expected = {**parameters, "page": str(page + 1)}
            if (next_url.scheme != "https" or next_url.netloc != "api.github.com"
                    or next_url.path != base.path or next_url.fragment
                    or parse_qs(next_url.query) != {k: [str(v)] for k, v in expected.items()}):
                raise ApiError()
        raise ApiError()


class Checks:
    def __init__(self, api, app_id, config):
        self.api, self.app_id, self.config = api, integer(app_id), config
        self.identities = {}

    def external_id(self, commit):
        return f"ares-contribution-policy:v1:{self.config.repository_id}:{commit}"

    def validate(self, run, commit):
        run = mapping(run)
        if (mapping(run.get("app")).get("id") != self.app_id or run.get("head_sha") != commit
                or run.get("name") != self.config.check_name or run.get("external_id") != self.external_id(commit)):
            raise ApiError()
        return integer(run.get("id"))

    def set(self, commit, conclusion=None):
        sha(commit)
        if commit not in self.identities:
            runs = self.api.pages(PREFIX + "/commits/" + commit + "/check-runs?" + urlencode({
                "check_name": self.config.check_name, "filter": "all"}), "check_runs")
            self.identities[commit] = [self.validate(run, commit) for run in runs
                                      if mapping(run).get("name") == self.config.check_name
                                      and mapping(run.get("app")).get("id") == self.app_id
                                      and run.get("external_id") == self.external_id(commit)]
        payload = {"name": self.config.check_name, "external_id": self.external_id(commit),
                   "status": "completed" if conclusion else "in_progress",
                   "output": {"title": "Contribution policy", "summary":
                              "Live approved-issue and independent current-HEAD review policy. "
                              "Maintainers must separately verify semantic scope and existing CI."}}
        if conclusion:
            payload["conclusion"] = conclusion
        if not self.identities[commit]:
            run, _ = self.api.request("POST", PREFIX + "/check-runs", {**payload, "head_sha": commit})
            self.identities[commit] = [self.validate(run, commit)]
            self._status(run, payload)
        else:
            # Invalidate/update all exact duplicates after an ambiguous past create.
            for identifier in self.identities[commit]:
                run, _ = self.api.request("PATCH", PREFIX + f"/check-runs/{identifier}", payload)
                self.validate(run, commit)
                self._status(run, payload)

    @staticmethod
    def _status(run, payload):
        if run.get("status") != payload["status"] or (payload.get("conclusion") and run.get("conclusion") != payload["conclusion"]):
            raise ApiError()


@dataclass(frozen=True)
class Candidate:
    number: int
    pr: PullRequest
    base_sha: str
    base_ref: str
    merge_sha: str | None
    merge_ready: bool
    links: tuple[IssueReference, ...]
    issues: tuple[Issue, ...]
    reviews: tuple[Review, ...]
    permissions: tuple[tuple[int, str], ...]
    decision: Decision

    @property
    def shas(self):
        return tuple(sorted({self.pr.head_sha, *([self.merge_sha] if self.merge_sha else [])}))


@dataclass(frozen=True)
class RunResult:
    ok: bool
    closed: tuple[int, ...]
    blocked: tuple[int, ...]
    reason: str


class Reconciler:
    def __init__(self, config, metadata, checks, app_id):
        self.config, self.api = config, metadata
        self.checks = Checks(checks, app_id, config)
        self.held = set()
        self.logins = {}
        self.issue_cache = {}
        self.permission_cache = {}

    def _identity(self, api):
        repo = mapping(api.get(PREFIX))
        if (repo.get("id") != self.config.repository_id or repo.get("full_name") != self.config.repository
                or mapping(repo.get("owner")).get("id") != self.config.owner_id or repo.get("default_branch") != "main"):
            raise ApiError()

    def _actor(self, raw):
        if raw is None:
            return Actor(0, "Unknown")
        raw = mapping(raw)
        actor = Actor(integer(raw.get("id")), string(raw.get("type")))
        login = string(raw.get("login"))
        if not re.fullmatch(r"[A-Za-z0-9-]+(?:\[bot\])?", login):
            raise ApiError()
        self.logins[actor.id] = login
        return actor

    def _permission(self, actor):
        if actor.id not in self.config.maintainer_ids or actor.kind != "User":
            return "none"
        if actor.id not in self.permission_cache:
            login = self.logins.get(actor.id)
            if not login:
                raise ApiError()
            try:
                result = mapping(self.api.get(PREFIX + "/collaborators/" + quote(login, safe="") + "/permission"))
            except ApiError as error:
                if error.status != 404:
                    raise
                self.permission_cache[actor.id] = "none"
            else:
                if mapping(result.get("user")).get("id") != actor.id:
                    raise ApiError()
                permission = string(result.get("permission"))
                if permission not in {"admin", "write", "maintain", "triage", "read", "none"}:
                    raise ApiError()
                self.permission_cache[actor.id] = permission
        return self.permission_cache[actor.id]

    def _issue(self, number):
        if number in self.issue_cache:
            return self.issue_cache[number]
        try:
            raw = mapping(self.api.get(PREFIX + f"/issues/{number}"))
            if integer(raw.get("number")) != number:
                raise ApiError()
            labels = frozenset(string(mapping(label).get("name")) for label in array(raw.get("labels")))
            events, scope_changes = [], []
            if "pull_request" not in raw:
                for item in self.api.pages(PREFIX + f"/issues/{number}/events"):
                    item = mapping(item)
                    if item.get("event") == "renamed":
                        scope_changes.append(timestamp(item.get("created_at")))
                    if item.get("event") in {"labeled", "unlabeled"} and mapping(item.get("label")).get("name") == "approved":
                        actor = self._actor(item.get("actor"))
                        self._permission(actor)
                        events.append(LabelEvent(integer(item.get("id")), item["event"], "approved", actor,
                                                 timestamp(item.get("created_at"))))
                edit_query = ('query($number:Int!){repository(owner:"davidlig",name:"ares-irc-services"){'
                              'databaseId issue(number:$number){lastEditedAt}}}')
                edit_result, _ = self.api.request("POST", "/graphql", {"query": edit_query, "variables": {"number": number}})
                edit_result = mapping(edit_result)
                if edit_result.get("errors"):
                    raise ApiError()
                repo = mapping(mapping(edit_result.get("data")).get("repository"))
                if repo.get("databaseId") != self.config.repository_id:
                    raise ApiError()
                scope = mapping(repo.get("issue"))
                if "lastEditedAt" not in scope:
                    raise ApiError()
                if scope["lastEditedAt"] is not None:
                    scope_changes.append(timestamp(scope["lastEditedAt"]))
            value = Issue(number, self.config.repository_id, labels, "pull_request" in raw,
                          tuple(sorted(events, key=lambda event: (event.created_at, event.id))),
                          max(scope_changes) if scope_changes else None)
        except ApiError as error:
            if error.status not in {404, 410}:
                raise
            value = None
        self.issue_cache[number] = value
        return value

    def _links(self, number):
        query = ('query($number:Int!,$cursor:String){repository(owner:"davidlig",name:"ares-irc-services"){'
                 'databaseId pullRequest(number:$number){closingIssuesReferences(first:100,after:$cursor,'
                 'excludeUserLinked:false){totalCount nodes{number repository{nameWithOwner databaseId}}'
                 'pageInfo{hasNextPage endCursor}}}}}')
        cursor, seen, references = None, set(), []
        expected_total = None
        for _ in range(MAX_PAGES):
            payload, _ = self.api.request("POST", "/graphql", {"query": query, "variables": {"number": number, "cursor": cursor}})
            payload = mapping(payload)
            if payload.get("errors"):
                raise ApiError()
            repo = mapping(mapping(payload.get("data")).get("repository"))
            if repo.get("databaseId") != self.config.repository_id:
                raise ApiError()
            connection = mapping(mapping(repo.get("pullRequest")).get("closingIssuesReferences"))
            total = connection.get("totalCount")
            if type(total) is not int or total < 0 or (expected_total is not None and total != expected_total):
                raise ApiError()
            expected_total = total
            for node in array(connection.get("nodes")):
                node = mapping(node)
                target = mapping(node.get("repository"))
                name = string(target.get("nameWithOwner")).lower()
                if name == self.config.repository and target.get("databaseId") != self.config.repository_id:
                    raise ApiError()
                references.append(IssueReference(name, integer(node.get("number"))))
            info = mapping(connection.get("pageInfo"))
            if type(info.get("hasNextPage")) is not bool:
                raise ApiError()
            if not info["hasNextPage"]:
                if len(references) != expected_total:
                    raise ApiError()
                return tuple(sorted(set(references)))
            cursor = string(info.get("endCursor"))
            if not cursor or cursor in seen:
                raise ApiError()
            seen.add(cursor)
        raise ApiError()

    def _hold(self, commits):
        for commit in sorted(set(commits) - self.held):
            # Retain the SHA before attempting the write, so failure cleanup retries
            # invalidation without treating an ambiguous mutation as approval.
            self.held.add(commit)
            self.checks.set(commit)

    def _snapshot(self):
        self.issue_cache, self.permission_cache, self.logins = {}, {}, {}
        numbers = []
        def hold_headers(items):
            for raw in items:
                raw = mapping(raw)
                numbers.append(integer(raw.get("number")))
                self._hold([sha(mapping(raw.get("head")).get("sha")),
                            *([sha(raw["merge_commit_sha"])] if raw.get("merge_commit_sha") else [])])

        # Hold each successfully read page immediately. A later pagination
        # failure must not leave known first-page successes untouched.
        self.api.pages(PREFIX + "/pulls?state=open&sort=created&direction=asc", on_page=hold_headers)
        if len(numbers) != len(set(numbers)):
            raise ApiError()
        candidates = []
        for number in sorted(numbers):
            raw = mapping(self.api.get(PREFIX + f"/pulls/{number}"))
            if raw.get("state") == "closed":
                continue
            if raw.get("state") != "open" or raw.get("number") != number:
                raise ApiError()
            base = mapping(raw.get("base"))
            if mapping(base.get("repo")).get("id") != self.config.repository_id:
                raise ApiError()
            head = sha(mapping(raw.get("head")).get("sha"))
            merge = sha(raw["merge_commit_sha"]) if raw.get("merge_commit_sha") else None
            self._hold([head, *([merge] if merge else [])])
            reopened = []
            for item in self.api.pages(PREFIX + f"/issues/{number}/events"):
                item = mapping(item)
                if item.get("event") == "reopened":
                    reopened.append(timestamp(item.get("created_at")))
            author = self._actor(raw.get("user"))
            pr = PullRequest(author, head, timestamp(raw.get("created_at")), tuple(sorted(reopened)),
                             "" if raw.get("body") is None else string(raw["body"]))
            reviews = []
            for item in self.api.pages(PREFIX + f"/pulls/{number}/reviews"):
                item = mapping(item)
                state = string(item.get("state"))
                if state == "PENDING":
                    continue
                if state not in {"APPROVED", "CHANGES_REQUESTED", "DISMISSED", "COMMENTED"}:
                    raise ApiError()
                actor = self._actor(item.get("user"))
                self._permission(actor)
                reviews.append(Review(integer(item.get("id")), actor, state, sha(item.get("commit_id")),
                                      timestamp(item.get("submitted_at"))))
            reviews = tuple(sorted(reviews, key=lambda item: (item.submitted_at, item.id)))
            links = self._links(number)
            refs = set(links) | set(parse_closing_references(pr.body, self.config.repository).references)
            issues = {}
            for reference in sorted(refs):
                if reference.repository == self.config.repository:
                    value = self._issue(reference.number)
                    if value is not None:
                        issues[reference.number] = value
            permission_ids = {event.actor.id for value in issues.values() for event in value.events}
            permission_ids.update(review.actor.id for review in reviews)
            permissions = {identifier: self.permission_cache.get(identifier, "none") for identifier in permission_ids}
            decision = evaluate(self.config, pr, issues, reviews, links, permissions)
            merge_ready = bool(merge and raw.get("mergeable") is True and raw.get("mergeable_state") not in {None, "unknown"})
            candidates.append(Candidate(number, pr, sha(base.get("sha")), string(base.get("ref")), merge,
                                        merge_ready, links, tuple(issues[k] for k in sorted(issues)), reviews,
                                        tuple(sorted(permissions.items())), decision))
        return tuple(candidates)

    def _label_authorized(self, value):
        latest = max(value.events, key=lambda e: (e.created_at, e.id)) if value.events else None
        return bool(latest and latest.action == "labeled" and latest.actor.kind == "User"
                    and (value.scope_changed_at is None or latest.created_at > value.scope_changed_at)
                    and latest.actor.id in self.config.maintainer_ids
                    and self.permission_cache.get(latest.actor.id) in {"write", "admin", "maintain"})

    def _sanitize_labels(self, trigger_issue):
        numbers = {integer(mapping(raw).get("number")) for raw in self.api.pages(PREFIX + "/issues?state=all&labels=approved")}
        if trigger_issue is not None:
            numbers.add(integer(trigger_issue))
        for number in sorted(numbers):
            self.issue_cache, self.permission_cache = {}, {}
            value = self._issue(number)
            if value is None or value.is_pull_request or "approved" not in value.labels:
                continue
            if self._label_authorized(value):
                continue
            # Sender is irrelevant. A second fresh history/permission read avoids
            # deleting a later legitimate label merely because an older event fired.
            self.issue_cache, self.permission_cache = {}, {}
            fresh = self._issue(number)
            if fresh != value or (fresh is not None and self._label_authorized(fresh)):
                continue
            try:
                self.api.request("DELETE", PREFIX + f"/issues/{number}/labels/approved")
            except ApiError as error:
                if error.status != 404:
                    raise
        self.issue_cache, self.permission_cache = {}, {}

    def _comment(self, number):
        comments = self.api.pages(PREFIX + f"/issues/{number}/comments")
        own = [mapping(item) for item in comments if MARKER in string(mapping(item).get("body"))
               and mapping(item.get("user")).get("id") == 41898282 and item["user"].get("type") == "Bot"]
        if own:
            existing = min(own, key=lambda item: integer(item.get("id")))
            if existing["body"] == COMMENT:
                return
            result, _ = self.api.request("PATCH", PREFIX + "/issues/comments/" + str(integer(existing.get("id"))), {"body": COMMENT})
        else:
            result, _ = self.api.request("POST", PREFIX + f"/issues/{number}/comments", {"body": COMMENT})
        result = mapping(result)
        if (result.get("body") != COMMENT or mapping(result.get("user")).get("id") != 41898282
                or result["user"].get("type") != "Bot"):
            raise ApiError()
        integer(result.get("id"))

    def _block_all(self):
        for commit in sorted(self.held):
            try:
                self.checks.set(commit, "failure")
            except ApiError:
                # A failed write cannot invalidate a previous success. Report the
                # run as unavailable, never pretend GitHub state was changed.
                pass

    def run(self, trigger_issue=None):
        closed = []
        try:
            self._identity(self.api)
            self._identity(self.checks.api)
            # Hold known current SHAs before audit mutations. Every run scans all
            # open PRs, rather than trusting an event snapshot or queued run order.
            baseline = self._snapshot()
            self._sanitize_labels(trigger_issue)
            for _ in range(MAX_ROUNDS):
                current = self._snapshot()
                if current != baseline:
                    self._block_all()
                    baseline = current
                    continue
                invalid = next((candidate for candidate in current if not candidate.decision.admitted), None)
                if invalid:
                    for commit in invalid.shas:
                        self.checks.set(commit, "failure")
                    if self._snapshot() != current:
                        self._block_all()
                        baseline = self._snapshot()
                        continue
                    self._comment(invalid.number)
                    if self._snapshot() != current:
                        self._block_all()
                        baseline = self._snapshot()
                        continue
                    result, _ = self.api.request("PATCH", PREFIX + f"/pulls/{invalid.number}", {"state": "closed"})
                    if mapping(result).get("state") != "closed" or result.get("number") != invalid.number:
                        raise ApiError()
                    closed.append(invalid.number)
                    # Recompute all shared head/merge roles after closing a sibling.
                    baseline = self._snapshot()
                    continue
                groups = {}
                for candidate in current:
                    valid = candidate.merge_ready and candidate.decision.review_satisfied
                    for commit in candidate.shas:
                        groups.setdefault(commit, []).append(valid)
                for commit, approvals in sorted(groups.items()):
                    if self._snapshot() != current:
                        break
                    self.checks.set(commit, "success" if all(approvals) else "failure")
                else:
                    if self._snapshot() == current:
                        blocked = tuple(c.number for c in current if not c.merge_ready or not c.decision.review_satisfied)
                        return RunResult(True, tuple(closed), blocked, "reconciled")
                self._block_all()
                baseline = self._snapshot()
            self._block_all()
            return RunResult(False, tuple(closed), (), "snapshot_changed_or_work_budget_exhausted")
        except (ApiError, ValueError, TypeError, KeyError):
            self._block_all()
            return RunResult(False, tuple(closed), (), "metadata_or_write_unavailable")


def main():
    # Only the trusted checkout's fixed configuration is loaded. No CLI allows
    # redirecting the host/repository/script/config to contributor-controlled data.
    try:
        config = PolicyConfig.from_mapping(json.loads((Path(__file__).resolve().parents[2] / ".github/contribution-policy.json").read_text()))
        metadata = Github(os.environ.get("GITHUB_TOKEN"))
        checks = Github(os.environ.get("CONTRIBUTION_CHECK_TOKEN"))
        app_id = int(os.environ.get("CONTRIBUTION_APP_ID", ""))
        trigger = None
        event_path = os.environ.get("GITHUB_EVENT_PATH")
        if event_path and os.environ.get("GITHUB_EVENT_NAME") == "issues":
            event = mapping(json.loads(Path(event_path).read_text()))
            trigger = integer(mapping(event.get("issue")).get("number"))
        result = Reconciler(config, metadata, checks, app_id).run(trigger)
    except (ApiError, ValueError, TypeError, OSError, KeyError):
        print("Contribution reconciliation unavailable; external configuration or API access needs attention.")
        return 1
    print(f"Contribution reconciliation: {result.reason}; closed={len(result.closed)}; blocked={len(result.blocked)}")
    return 0 if result.ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
