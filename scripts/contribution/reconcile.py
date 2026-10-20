"""Trusted-main reconciliation, never a runner for contributor code.

GITHUB_TOKEN reads metadata and writes labels/comments/PR state. A separately
scoped installation token is the ONLY writer of the required App check runs.
No event payload, workflow conclusion, artifact, username or PR file is authority.
"""

from contextlib import contextmanager
from contextvars import ContextVar
from dataclasses import dataclass, replace
from datetime import datetime
from enum import Enum
from functools import wraps
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
from grants import GrantComment, Opening, POINTER_PREFIX, pointer_id, valid_ref

REPOSITORY = "davidlig/ares-irc-services"
PREFIX = "/repos/" + REPOSITORY
API = "https://api.github.com"
MAX_PAGES = 200
MAX_ROUNDS = 8
MARKER = "<!-- ares-contribution-policy:v1 -->"
COMMENT = (MARKER + "\nThanks for the contribution. Every linked issue must remain open and authorize this contributor and branch.\n\n"
           "Ask a maintainer to post an unedited /authorize-contribution command on each issue, then apply "
           "approved-grant:<comment_id> followed by approved before opening or reopening this PR. "
           "The grant binds your numeric author ID, source repository ID and exact branch. "
           "The first subsequent PR opening or reopening by that author consumes it, even on another branch; "
           "only that same PR can reuse it. A closed or edited issue needs a fresh grant and approval. "
           "See CONTRIBUTING.md for scope review. Correct the references and reopen yourself after approval. "
           "Missing code review alone does not cause closure.")
SHA = re.compile(r"[0-9a-f]{40}")


class Stage(Enum):
    UNKNOWN = "unknown"
    CONFIGURATION = "configuration"
    IDENTITY = "identity"
    SNAPSHOT = "snapshot"
    LABEL_AUDIT = "label_audit"
    INVALIDATION = "invalidation"
    COMMENT = "comment"
    CLOSURE = "closure"
    PUBLICATION = "publication"
    FINAL_VALIDATION = "final_validation"
    CLEANUP = "cleanup"


class Operation(Enum):
    UNKNOWN = "unknown"
    REPOSITORY_READ = "repository_read"
    PULL_LIST = "pull_list"
    HISTORY_LIST = "history_list"
    PULL_READ = "pull_read"
    PULL_CLOSE = "pull_close"
    ISSUE_LIST = "issue_list"
    ISSUE_READ = "issue_read"
    ISSUE_EVENTS = "issue_events"
    REVIEW_LIST = "review_list"
    PERMISSION_READ = "permission_read"
    GRAPHQL = "graphql"
    LABEL_DELETE = "label_delete"
    COMMENT_LIST = "comment_list"
    COMMENT_CREATE = "comment_create"
    COMMENT_UPDATE = "comment_update"
    CHECK_LIST = "check_list"
    CHECK_CREATE = "check_create"
    CHECK_UPDATE = "check_update"


class Principal(Enum):
    UNKNOWN = "unknown"
    METADATA = "metadata"
    CHECKS = "checks"


class Category(Enum):
    UNKNOWN = "unknown"
    CONFIGURATION = "configuration"
    HTTP_ERROR = "http_error"
    TRANSPORT = "transport"
    MALFORMED_RESPONSE = "malformed_response"
    RESPONSE_MISMATCH = "response_mismatch"
    INVALID_REQUEST = "invalid_request"
    UNEXPECTED = "unexpected"


@dataclass(frozen=True)
class Diagnostic:
    stage: Stage = Stage.UNKNOWN
    operation: Operation = Operation.UNKNOWN
    principal: Principal = Principal.UNKNOWN
    category: Category = Category.UNKNOWN
    http_status: int | None = None

    def __post_init__(self):
        # Accept only enum instances, never user-controlled strings or messages.
        for name, kind in (("stage", Stage), ("operation", Operation),
                           ("principal", Principal), ("category", Category)):
            if not isinstance(getattr(self, name), kind):
                object.__setattr__(self, name, kind.UNKNOWN)
        if type(self.http_status) is not int or not 100 <= self.http_status <= 599:
            object.__setattr__(self, "http_status", None)

    def format(self):
        return (f"stage={self.stage.value} operation={self.operation.value} principal={self.principal.value} "
                f"category={self.category.value} http_status={self.http_status or 'none'}")


_DIAGNOSTIC = ContextVar("contribution_diagnostic", default=Diagnostic())


@contextmanager
def diagnostic_scope(**fields):
    token = _DIAGNOSTIC.set(replace(_DIAGNOSTIC.get(), **fields))
    try:
        yield
    finally:
        _DIAGNOSTIC.reset(token)


def diagnosed(**fields):
    """Attach safe context before parsing, without retaining an exception message."""
    def decorate(function):
        @wraps(function)
        def call(*args, **kwargs):
            with diagnostic_scope(**fields):
                try:
                    return function(*args, **kwargs)
                except ApiError:
                    raise
                except (ValueError, TypeError, KeyError, OSError):
                    category = (Category.CONFIGURATION if _DIAGNOSTIC.get().stage == Stage.CONFIGURATION
                                else Category.MALFORMED_RESPONSE)
                    raise ApiError(category=category) from None
                except Exception:
                    raise ApiError(category=Category.UNEXPECTED) from None
        return call
    return decorate


class ApiError(Exception):
    """Unknown/unavailable metadata, never evidence of a policy violation."""
    def __init__(self, status=None, *, category=Category.MALFORMED_RESPONSE, **fields):
        self.diagnostic = replace(_DIAGNOSTIC.get(), category=category, http_status=status, **fields)
        self.status = self.diagnostic.http_status
        super().__init__(self.diagnostic.format())


def request_operation(method, path):
    # Do not copy an arbitrary path, query, method or upstream error into output.
    if method == "GET" and isinstance(path, str) and path.split("?", 1)[0] == PREFIX + "/pulls":
        if parse_qs(urlsplit(path).query).get("state") == ["all"]:
            return Operation.HISTORY_LIST
    routes = (("GET", re.escape(PREFIX), Operation.REPOSITORY_READ),
              ("POST", "/graphql", Operation.GRAPHQL),
              ("GET", re.escape(PREFIX) + "/pulls", Operation.PULL_LIST),
              ("GET", re.escape(PREFIX) + r"/pulls/\d+", Operation.PULL_READ),
              ("PATCH", re.escape(PREFIX) + r"/pulls/\d+", Operation.PULL_CLOSE),
              ("GET", re.escape(PREFIX) + "/issues", Operation.ISSUE_LIST),
              ("GET", re.escape(PREFIX) + r"/issues/\d+", Operation.ISSUE_READ),
              ("GET", re.escape(PREFIX) + r"/issues/\d+/events", Operation.ISSUE_EVENTS),
              ("GET", re.escape(PREFIX) + r"/pulls/\d+/reviews", Operation.REVIEW_LIST),
              ("GET", re.escape(PREFIX) + r"/collaborators/[^/]+/permission", Operation.PERMISSION_READ),
              ("DELETE", re.escape(PREFIX) + r"/issues/\d+/labels/[^/]+", Operation.LABEL_DELETE),
              ("GET", re.escape(PREFIX) + r"/issues/\d+/comments", Operation.COMMENT_LIST),
              ("POST", re.escape(PREFIX) + r"/issues/\d+/comments", Operation.COMMENT_CREATE),
              ("PATCH", re.escape(PREFIX) + r"/issues/comments/\d+", Operation.COMMENT_UPDATE),
              ("GET", re.escape(PREFIX) + r"/commits/[0-9a-f]{40}/check-runs", Operation.CHECK_LIST),
              ("POST", re.escape(PREFIX) + "/check-runs", Operation.CHECK_CREATE),
              ("PATCH", re.escape(PREFIX) + r"/check-runs/\d+", Operation.CHECK_UPDATE))
    target = path.split("?", 1)[0] if isinstance(path, str) else ""
    return next((operation for verb, pattern, operation in routes
                 if verb == method and re.fullmatch(pattern, target)), Operation.UNKNOWN)


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ApiError(code, category=Category.HTTP_ERROR)


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
    def __init__(self, token, *, open_url=None, sleep=time.sleep, principal=Principal.UNKNOWN):
        if not token:
            raise ApiError(category=Category.CONFIGURATION)
        self.token = token
        self.principal = principal
        self.open_url = open_url or build_opener(NoRedirect()).open
        self.sleep = sleep

    def request(self, method, path, data=None):
        return self._contextual(method, path, self._request, method, path, data)

    def _contextual(self, method, path, function, *args):
        with diagnostic_scope(operation=request_operation(method, path), principal=self.principal):
            try:
                return function(*args)
            except ApiError:
                raise
            except Exception:
                raise ApiError(category=Category.UNEXPECTED) from None

    def _request(self, method, path, data=None):
        parsed = urlsplit(path)
        if (parsed.scheme or parsed.netloc or parsed.fragment or ".." in path or "\\" in path
                or any(ord(c) < 32 for c in path)
                or not (parsed.path == "/graphql" or parsed.path == PREFIX or parsed.path.startswith(PREFIX + "/"))):
            raise ApiError(category=Category.INVALID_REQUEST)
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
                        raise ApiError(response.status, category=Category.HTTP_ERROR)
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
                    raise ApiError(error.code, category=Category.HTTP_ERROR) from None
            except (URLError, TimeoutError, OSError):
                if not read_only or attempt == 2:
                    raise ApiError(category=Category.TRANSPORT) from None
            except (json.JSONDecodeError, UnicodeDecodeError):
                raise ApiError() from None
            self.sleep(attempt + 1)
        raise ApiError()

    def get(self, path):
        return self.request("GET", path)[0]

    def pages(self, path, key=None, on_page=None):
        return self._contextual("GET", path, self._pages, path, key, on_page)

    def _pages(self, path, key=None, on_page=None):
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

    @diagnosed(principal=Principal.CHECKS)
    def set(self, commit, conclusion=None):
        sha(commit)
        if commit not in self.identities:
            with diagnostic_scope(operation=Operation.CHECK_LIST):
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
            with diagnostic_scope(operation=Operation.CHECK_CREATE):
                run, _ = self.api.request("POST", PREFIX + "/check-runs", {**payload, "head_sha": commit})
                self.identities[commit] = [self.validate(run, commit)]
                self._status(run, payload)
        else:
            # Invalidate/update all exact duplicates after an ambiguous past create.
            for identifier in self.identities[commit]:
                with diagnostic_scope(operation=Operation.CHECK_UPDATE):
                    run, _ = self.api.request("PATCH", PREFIX + f"/check-runs/{identifier}", payload)
                    self.validate(run, commit)
                    self._status(run, payload)

    @staticmethod
    def _status(run, payload):
        if run.get("status") != payload["status"] or (payload.get("conclusion") and run.get("conclusion") != payload["conclusion"]):
            raise ApiError(category=Category.RESPONSE_MISMATCH)


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
    primary_error: Diagnostic | None = None
    cleanup_errors: tuple[Diagnostic, ...] = ()


class Reconciler:
    def __init__(self, config, metadata, checks, app_id):
        metadata.principal, checks.principal = Principal.METADATA, Principal.CHECKS
        self.config, self.api = config, metadata
        self.checks = Checks(checks, app_id, config)
        self.held = set()
        self.logins = {}
        self.issue_cache = {}
        self.permission_cache = {}
        self.cleanup_errors = []

    def _identity(self, api):
        with diagnostic_scope(operation=Operation.REPOSITORY_READ, principal=api.principal):
            repo = mapping(api.get(PREFIX))
            if (repo.get("id") != self.config.repository_id or repo.get("full_name") != self.config.repository
                    or mapping(repo.get("owner")).get("id") != self.config.owner_id or repo.get("default_branch") != "main"):
                raise ApiError(category=Category.RESPONSE_MISMATCH)

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

    @diagnosed(operation=Operation.PERMISSION_READ, principal=Principal.METADATA)
    def _permission(self, actor):
        if actor.id not in self.config.maintainer_ids or actor.kind != "User":
            return "none"
        if actor.id not in self.permission_cache:
            login = self.logins.get(actor.id)
            if not login:
                raise ApiError()
            result = mapping(self.api.get(PREFIX + "/collaborators/" + quote(login, safe="") + "/permission"))
            if mapping(result.get("user")).get("id") != actor.id:
                raise ApiError()
            permission = string(result.get("permission"))
            if permission not in {"admin", "write", "maintain", "triage", "read", "none"}:
                raise ApiError()
            self.permission_cache[actor.id] = permission
        return self.permission_cache[actor.id]

    @diagnosed(operation=Operation.ISSUE_READ, principal=Principal.METADATA)
    def _issue(self, number):
        if number in self.issue_cache:
            return self.issue_cache[number]
        try:
            raw = mapping(self.api.get(PREFIX + f"/issues/{number}"))
        except ApiError as error:
            if error.status not in {404, 410}:
                raise
            self.issue_cache[number] = None
            return None
        if integer(raw.get("number")) != number:
            raise ApiError()
        labels = frozenset(string(mapping(label).get("name")) for label in array(raw.get("labels")))
        state = string(raw.get("state"))
        if state not in {"open", "closed"}:
            raise ApiError()
        events, scope_changes, reopened, comments = [], [], [], []
        if "pull_request" not in raw:
            for item in self.api.pages(PREFIX + f"/issues/{number}/events"):
                item = mapping(item)
                if item.get("event") == "renamed":
                    scope_changes.append(timestamp(item.get("created_at")))
                if item.get("event") == "reopened":
                    reopened.append(timestamp(item.get("created_at")))
                if item.get("event") in {"labeled", "unlabeled"}:
                    label = string(mapping(item.get("label")).get("name"))
                    if label != "approved" and not label.startswith(POINTER_PREFIX):
                        continue
                    actor = self._actor(item.get("actor"))
                    self._permission(actor)
                    events.append(LabelEvent(integer(item.get("id")), item["event"], label, actor,
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
            identifier = pointer_id(labels)
            if identifier is not None:
                seen_comments = set()
                for item in self.api.pages(PREFIX + f"/issues/{number}/comments"):
                    item = mapping(item)
                    comment_id = integer(item.get("id"))
                    if comment_id in seen_comments:
                        raise ApiError()
                    seen_comments.add(comment_id)
                    if comment_id != identifier:
                        continue
                    actor = self._actor(item.get("user"))
                    self._permission(actor)
                    local = item.get("issue_url") == API + PREFIX + f"/issues/{number}"
                    comments.append(GrantComment(identifier, number if local else 0, actor.id, actor.kind,
                                                 timestamp(item.get("created_at")), timestamp(item.get("updated_at")),
                                                 string(item.get("body"))))
        value = Issue(number, self.config.repository_id, labels, "pull_request" in raw,
                      tuple(sorted(events, key=lambda event: (event.created_at, event.id))),
                      max(scope_changes) if scope_changes else None, state, tuple(comments), tuple(sorted(reopened)))
        self.issue_cache[number] = value
        return value

    @diagnosed(operation=Operation.GRAPHQL, principal=Principal.METADATA)
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
            # A terminal check need not reopen as in_progress. Explicit failure
            # blocks merging for both new and reused checks during evaluation.
            self.checks.set(commit, "failure")

    @diagnosed(operation=Operation.HISTORY_LIST, principal=Principal.METADATA)
    def _history(self):
        """Read all openings once; mutable references or branches cannot free a grant."""
        history, records, seen_ids = [], {}, set()
        for raw in self.api.pages(PREFIX + "/pulls?state=all&sort=created&direction=asc"):
            raw = mapping(raw)
            number, identifier = integer(raw.get("number")), integer(raw.get("id"))
            if number in records or identifier in seen_ids or raw.get("state") not in {"open", "closed"}:
                raise ApiError()
            if mapping(mapping(raw.get("base")).get("repo")).get("id") != self.config.repository_id:
                raise ApiError()
            actor, created = self._actor(raw.get("user")), timestamp(raw.get("created_at"))
            integer(actor.id)
            history.append(Opening(identifier, actor.id, created, 0))
            reopened, seen_events = [], set()
            for item in self.api.pages(PREFIX + f"/issues/{number}/events"):
                item = mapping(item)
                if item.get("event") == "reopened":
                    event_id, at = integer(item.get("id")), timestamp(item.get("created_at"))
                    if event_id in seen_events or at < created:
                        raise ApiError()
                    seen_events.add(event_id)
                    reopened.append(at)
                    history.append(Opening(identifier, actor.id, at, event_id))
            records[number] = (identifier, actor, created, tuple(sorted(reopened)))
            seen_ids.add(identifier)
        return tuple(sorted(history, key=lambda e: (e.at, e.pr_id, e.event_id))), records

    @diagnosed(operation=Operation.PULL_READ, principal=Principal.METADATA)
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
        history, records = self._history()
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
            author = self._actor(raw.get("user"))
            identifier, known_author, created, reopened = records[number]
            if identifier != integer(raw.get("id")) or known_author != author or created != timestamp(raw.get("created_at")):
                raise ApiError()
            source = mapping(raw.get("head"))
            source_repo = integer(mapping(source.get("repo")).get("id"))
            source_ref, base_ref = string(source.get("ref")), string(base.get("ref"))
            if not valid_ref(source_ref) or not valid_ref(base_ref):
                raise ApiError()
            pr = PullRequest(author, head, created, reopened, "" if raw.get("body") is None else string(raw["body"]),
                             identifier, source_repo, source_ref, base_ref)
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
            permission_ids.update(comment.author_id for value in issues.values() for comment in value.comments)
            permissions = {identifier: self.permission_cache.get(identifier, "none") for identifier in permission_ids}
            decision = evaluate(self.config, pr, issues, reviews, links, permissions, history)
            merge_ready = bool(merge and raw.get("mergeable") is True and raw.get("mergeable_state") not in {None, "unknown"})
            candidates.append(Candidate(number, pr, sha(base.get("sha")), string(base.get("ref")), merge,
                                        merge_ready, links, tuple(issues[k] for k in sorted(issues)), reviews,
                                        tuple(sorted(permissions.items())), decision))
        return tuple(candidates)

    def _label_authorized(self, value, label="approved"):
        if label == "approved" and pointer_id(value.labels) is None:
            return False
        events = [event for event in value.events if event.label == label]
        latest = max(events, key=lambda e: (e.created_at, e.id)) if events else None
        return bool(latest and latest.action == "labeled" and latest.actor.kind == "User"
                    and (value.scope_changed_at is None or latest.created_at > value.scope_changed_at)
                    and all(latest.created_at > at for at in value.reopened_at)
                    and latest.actor.id in self.config.maintainer_ids
                    and self.permission_cache.get(latest.actor.id) in {"write", "admin", "maintain"})

    @diagnosed(operation=Operation.ISSUE_LIST, principal=Principal.METADATA)
    def _sanitize_labels(self, trigger_issue):
        numbers = {integer(mapping(raw).get("number")) for raw in self.api.pages(PREFIX + "/issues?state=all&labels=approved")}
        if trigger_issue is not None:
            numbers.add(integer(trigger_issue))
        for number in sorted(numbers):
            self.issue_cache, self.permission_cache = {}, {}
            value = self._issue(number)
            if value is None or value.is_pull_request:
                continue
            # Sender is irrelevant. A second fresh history/permission read avoids
            # deleting a later legitimate label merely because an older event fired.
            for label in sorted(value.labels):
                if (label != "approved" and not label.startswith(POINTER_PREFIX)) or self._label_authorized(value, label):
                    continue
                self.issue_cache, self.permission_cache = {}, {}
                fresh = self._issue(number)
                if fresh != value or (fresh is not None and self._label_authorized(fresh, label)):
                    continue
                try:
                    self.api.request("DELETE", PREFIX + f"/issues/{number}/labels/" + quote(label, safe=""))
                except ApiError as error:
                    if error.status != 404:
                        raise
                self.issue_cache, self.permission_cache = {}, {}
                value = self._issue(number)
                if value is None:
                    break
        self.issue_cache, self.permission_cache = {}, {}

    @diagnosed(operation=Operation.COMMENT_LIST, principal=Principal.METADATA)
    def _comment(self, number):
        comments = self.api.pages(PREFIX + f"/issues/{number}/comments")
        own = [mapping(item) for item in comments if MARKER in string(mapping(item).get("body"))
               and mapping(item.get("user")).get("id") == 41898282 and item["user"].get("type") == "Bot"]
        with diagnostic_scope(operation=Operation.COMMENT_UPDATE if own else Operation.COMMENT_CREATE):
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
                raise ApiError(category=Category.RESPONSE_MISMATCH)
            integer(result.get("id"))

    def _block_all(self):
        complete = True
        for commit in sorted(self.held):
            try:
                self._at(Stage.CLEANUP, self.checks.set, commit, "failure")
            except ApiError as error:
                # A failed write cannot invalidate a previous success. Report the
                # run as unavailable, never pretend GitHub state was changed.
                complete = False
                self.cleanup_errors.append(error.diagnostic)
        return complete

    @staticmethod
    def _at(stage, function, *args):
        with diagnostic_scope(stage=stage):
            try:
                return function(*args)
            except ApiError:
                raise
            except (ValueError, TypeError, KeyError):
                raise ApiError(category=Category.MALFORMED_RESPONSE) from None
            except Exception:
                raise ApiError(category=Category.UNEXPECTED) from None

    def _failure(self, closed, error=None):
        return RunResult(False, tuple(closed), (), "metadata_or_write_unavailable",
                         error.diagnostic if error else None, tuple(self.cleanup_errors))

    def run(self, trigger_issue=None):
        closed = []
        try:
            self._at(Stage.IDENTITY, self._identity, self.api)
            self._at(Stage.IDENTITY, self._identity, self.checks.api)
            # Hold known current SHAs before audit mutations. Every run scans all
            # open PRs, rather than trusting an event snapshot or queued run order.
            baseline = self._at(Stage.SNAPSHOT, self._snapshot)
            self._at(Stage.LABEL_AUDIT, self._sanitize_labels, trigger_issue)
            for _ in range(MAX_ROUNDS):
                current = self._at(Stage.SNAPSHOT, self._snapshot)
                if current != baseline:
                    if not self._block_all():
                        return self._failure(closed)
                    baseline = current
                    continue
                invalid = next((candidate for candidate in current if not candidate.decision.admitted), None)
                if invalid:
                    for commit in invalid.shas:
                        self._at(Stage.INVALIDATION, self.checks.set, commit, "failure")
                    if self._at(Stage.FINAL_VALIDATION, self._snapshot) != current:
                        if not self._block_all():
                            return self._failure(closed)
                        baseline = self._at(Stage.SNAPSHOT, self._snapshot)
                        continue
                    self._at(Stage.COMMENT, self._comment, invalid.number)
                    if self._at(Stage.FINAL_VALIDATION, self._snapshot) != current:
                        if not self._block_all():
                            return self._failure(closed)
                        baseline = self._at(Stage.SNAPSHOT, self._snapshot)
                        continue
                    with diagnostic_scope(stage=Stage.CLOSURE, operation=Operation.PULL_CLOSE, principal=Principal.METADATA):
                        result, _ = self.api.request("PATCH", PREFIX + f"/pulls/{invalid.number}", {"state": "closed"})
                        if mapping(result).get("state") != "closed" or result.get("number") != invalid.number:
                            raise ApiError(category=Category.RESPONSE_MISMATCH)
                    closed.append(invalid.number)
                    # Recompute all shared head/merge roles after closing a sibling.
                    baseline = self._at(Stage.SNAPSHOT, self._snapshot)
                    continue
                groups = {}
                for candidate in current:
                    valid = candidate.merge_ready and candidate.decision.review_satisfied
                    for commit in candidate.shas:
                        groups.setdefault(commit, []).append(valid)
                # The fresh current snapshot matched baseline before aggregation.
                # Publish one finite batch, not a repository-wide read per SHA.
                # The final fresh scan includes membership and all dependencies;
                # any drift invalidates the whole batch before a successful return.
                for commit, approvals in sorted(groups.items()):
                    self._at(Stage.PUBLICATION, self.checks.set, commit, "success" if all(approvals) else "failure")
                if self._at(Stage.FINAL_VALIDATION, self._snapshot) == current:
                    blocked = tuple(c.number for c in current if not c.merge_ready or not c.decision.review_satisfied)
                    return RunResult(True, tuple(closed), blocked, "reconciled")
                if not self._block_all():
                    return self._failure(closed)
                baseline = self._at(Stage.SNAPSHOT, self._snapshot)
            self._block_all()
            return RunResult(False, tuple(closed), (), "snapshot_changed_or_work_budget_exhausted",
                             cleanup_errors=tuple(self.cleanup_errors))
        except ApiError as error:
            self._block_all()
            return self._failure(closed, error)
        except Exception:
            error = ApiError(category=Category.UNEXPECTED)
            self._block_all()
            return self._failure(closed, error)


@diagnosed(stage=Stage.CONFIGURATION)
def configured_run():
    # Only the trusted checkout's fixed configuration is loaded. No CLI allows
    # redirecting the host/repository/script/config to contributor-controlled data.
    config = PolicyConfig.from_mapping(json.loads((Path(__file__).resolve().parents[2] / ".github/contribution-policy.json").read_text()))
    metadata = Github(os.environ.get("GITHUB_TOKEN"))
    checks = Github(os.environ.get("CONTRIBUTION_CHECK_TOKEN"))
    app_id = int(os.environ.get("CONTRIBUTION_APP_ID", ""))
    trigger = None
    event_path = os.environ.get("GITHUB_EVENT_PATH")
    if event_path and os.environ.get("GITHUB_EVENT_NAME") in {"issues", "issue_comment"}:
        event = mapping(json.loads(Path(event_path).read_text()))
        trigger = integer(mapping(event.get("issue")).get("number"))
    return Reconciler(config, metadata, checks, app_id).run(trigger)


def main():
    try:
        result = configured_run()
    except ApiError as error:
        print("Contribution reconciliation unavailable; external configuration or API access needs attention.")
        print("Contribution diagnostic: role=primary " + error.diagnostic.format())
        return 1
    if result.primary_error:
        print("Contribution diagnostic: role=primary " + result.primary_error.format())
    for error in result.cleanup_errors:
        print("Contribution diagnostic: role=cleanup " + error.format())
    print(f"Contribution reconciliation: {result.reason}; closed={len(result.closed)}; blocked={len(result.blocked)}")
    return 0 if result.ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
