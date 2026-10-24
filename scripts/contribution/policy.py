"""Pure contribution authorization. Callers supply complete, trusted API snapshots.

This module never reads credentials, performs I/O, or authorizes semantic scope.
Issue admission and independent review are separate decisions: only the former
makes a PR eligible for automatic closure by the reconciliation adapter.
"""

from dataclasses import dataclass
from datetime import datetime
import re
from typing import Mapping
from grants import GrantComment, Opening, POINTER_PREFIX, first_consumer, parse_grant, pointer_id, valid_ref


@dataclass(frozen=True)
class PolicyConfig:
    repository_id: int
    repository: str
    owner_id: int
    maintainer_ids: frozenset[int]
    approval_label: str = "approved"
    check_name: str = "Contribution policy"

    def __post_init__(self) -> None:
        if (self.repository_id != 1167938167 or self.repository != "davidlig/ares-irc-services"
                or self.owner_id != 561369):
            raise ValueError("Policy must target the canonical repository and owner IDs")
        identifiers = (self.repository_id, self.owner_id, *self.maintainer_ids)
        if any(type(value) is not int or value <= 0 for value in identifiers):
            raise ValueError("Trust identities must be positive integer IDs")
        if self.owner_id not in self.maintainer_ids:
            raise ValueError("The owner must remain in the maintainer allowlist")
        if self.approval_label != "approved" or self.check_name != "Contribution policy":
            raise ValueError("The exact approval label and required check name are fixed")
        object.__setattr__(self, "maintainer_ids", frozenset(self.maintainer_ids))

    @classmethod
    def from_mapping(cls, raw: Mapping[str, object]) -> "PolicyConfig":
        """Display names are intentionally not read as authority."""
        ids = raw.get("maintainer_ids")
        if not isinstance(ids, list):
            raise ValueError("maintainer_ids must be a list of numeric IDs")
        if any(type(value) is not int or value <= 0 for value in ids):
            raise ValueError("Maintainer IDs must be positive integers")
        repository_id, owner_id = raw.get("repository_id"), raw.get("owner_id")
        repository = raw.get("repository")
        label, check = raw.get("approval_label"), raw.get("check_name")
        if type(repository_id) is not int or type(owner_id) is not int:
            raise ValueError("Repository and owner identities must be numeric IDs")
        if not all(isinstance(value, str) for value in (repository, label, check)):
            raise ValueError("Repository, label and check must be strings")
        return cls(repository_id, repository, owner_id, frozenset(ids), label, check)


@dataclass(frozen=True)
class Actor:
    id: int
    kind: str


@dataclass(frozen=True, order=True)
class IssueReference:
    repository: str
    number: int


@dataclass(frozen=True)
class ParsedReferences:
    references: tuple[IssueReference, ...]
    errors: tuple[str, ...]


@dataclass(frozen=True)
class LabelEvent:
    id: int
    action: str
    label: str
    actor: Actor
    created_at: datetime


@dataclass(frozen=True)
class Issue:
    number: int
    repository_id: int
    labels: frozenset[str]
    is_pull_request: bool
    events: tuple[LabelEvent, ...]
    scope_changed_at: datetime | None = None
    state: str = "open"
    comments: tuple[GrantComment, ...] = ()
    reopened_at: tuple[datetime, ...] = ()


@dataclass(frozen=True)
class PullRequest:
    author: Actor
    head_sha: str
    created_at: datetime
    reopened_at: tuple[datetime, ...]
    body: str
    id: int | None = None
    head_repository_id: int | None = None
    head_ref: str | None = None
    base_ref: str | None = None


@dataclass(frozen=True)
class Review:
    id: int
    actor: Actor
    state: str
    commit_sha: str
    submitted_at: datetime


@dataclass(frozen=True)
class Decision:
    admitted: bool
    review_satisfied: bool
    issue_numbers: tuple[int, ...]
    admission_errors: tuple[str, ...]
    review_errors: tuple[str, ...]


_KEYWORD = re.compile(r"\b(?:close[sd]?|fix(?:es|ed)?|resolve[sd]?)\b", re.IGNORECASE)
_LOCAL = re.compile(r"#([1-9][0-9]*)")
_QUALIFIED = re.compile(r"([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)#([1-9][0-9]*)")
_URL = re.compile(r"https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/issues/([1-9][0-9]*)", re.IGNORECASE)


def _visible_markdown(body: str) -> str:
    """Ignore examples; API-provided linked issues must still be validated separately."""
    def mask(match: re.Match[str]) -> str:
        return re.sub(r"[^\n]", " ", match[0])

    body = re.sub(r"<!--[\s\S]*?(?:-->|\Z)", mask, body)
    lines = []
    fence = None
    for line in body.splitlines(keepends=True):
        marker = re.match(r"^ {0,3}(`{3,}|~{3,})(.*)$", line.rstrip("\r\n"))
        if fence is not None:
            if marker and marker[1][0] == fence[0] and len(marker[1]) >= len(fence) and not marker[2].strip():
                fence = None
            lines.append("\n")
        elif marker:
            fence = marker[1]
            lines.append("\n")
        elif line.startswith(("    ", "\t")):
            lines.append("\n")
        else:
            lines.append(line)
    return re.sub(r"(`+)([\s\S]*?)\1", mask, "".join(lines))


def _reference_token(token: str, repository: str) -> IssueReference | None:
    for pattern in (_LOCAL, _QUALIFIED, _URL):
        match = pattern.fullmatch(token)
        if match:
            if pattern is _LOCAL:
                return IssueReference(repository, int(match[1]))
            return IssueReference(match[1].lower(), int(match[2]))
    return None


def _looks_like_reference(token: str) -> bool:
    return token.startswith(("#", "http://", "https://", "<")) or "#" in token


def parse_closing_references(body: str, repository: str) -> ParsedReferences:
    """Parse explicit declarations and every reference in their lists.

    Supported lists use commas, semicolons, whitespace, ``and``, or bullet lines.
    Keep explanatory prose in separate sentences. A malformed declaration or
    list item is an error, never a silently ignored authorization reference.
    Names are case-insensitive; repository identity is checked again by ID.
    """
    repository = repository.lower()
    visible = _visible_markdown(body)
    keywords = list(_KEYWORD.finditer(visible))
    references = set()
    errors = []
    continuation_required = False
    for index, keyword in enumerate(keywords):
        end = keywords[index + 1].start() if index + 1 < len(keywords) else len(visible)
        segment = visible[keyword.end():end]
        colon = bool(re.match(r"\s*:", segment))
        segment = re.sub(r"^\s*:\s*", "", segment).lstrip()
        prefix = visible[:keyword.start()].rstrip()
        declaration = (colon or not prefix or prefix[-1] in ".!?:" or continuation_required
                       or not visible[:keyword.start()].split("\n")[-1].strip())
        continuation_required = False
        segment = re.sub(r"^(?:[-*+]|[0-9]+[.)])\s+", "", segment)
        # Punctuation/conjunctions in prose do not start declarations. Only an
        # actual closing list may require the next keyword to supply a reference.
        first_token = re.match(r"([^\s,;]+)", segment)
        if not declaration and (first_token is None or not _looks_like_reference(first_token[1])):
            continue
        found = False
        required = False
        while segment:
            token_match = re.match(r"([^\s,;]+)", segment)
            if not token_match:
                errors.append("Closing declaration has an empty or malformed list item")
                break
            token = token_match[1]
            sentence_end = token.endswith(".")
            token = token.removesuffix(".")
            if token.startswith("<") and token.endswith(">"):
                token = token[1:-1]
            reference = _reference_token(token, repository)
            if reference is None:
                if required or _looks_like_reference(token) or (not found and declaration):
                    errors.append(f"Malformed closing reference: {token}")
                break
            found = True
            references.add(reference)
            if reference.repository != repository:
                errors.append(f"Foreign closing reference: {reference.repository}#{reference.number}")
            if sentence_end:
                break
            segment = segment[token_match.end():]
            # A new paragraph or Markdown heading ends this declaration's list.
            # Explicit comma/and continuations still reject malformed extra items.
            if (re.match(r"^[ \t]*\n[ \t]*\n", segment)
                    or re.match(r"^[ \t]*\n[ \t]*#{1,6}\s", segment)):
                break
            separator = re.match(r"\s*(?:[,;]\s*(?:and\b\s*)?|and\b\s*|&\s*)", segment, re.IGNORECASE)
            required = separator is not None
            if separator:
                segment = segment[separator.end():]
            else:
                segment = segment.lstrip()
                segment = re.sub(r"^(?:[-*+]|[0-9]+[.)])\s+", "", segment)
            if not segment and required and index + 1 == len(keywords):
                errors.append("Closing reference list has a missing item")
        continuation_required = found and required and not segment
        if not found and not segment and declaration:
            errors.append("Closing declaration has no issue reference")
    return ParsedReferences(tuple(sorted(references)), tuple(errors))


def _trusted(actor: Actor, config: PolicyConfig, permissions: Mapping[int, str | None]) -> bool:
    return (type(actor.id) is int and actor.kind == "User" and actor.id in config.maintainer_ids
            and permissions.get(actor.id) in {"write", "maintain", "admin"})


def _timestamp(value: datetime) -> datetime:
    if value.tzinfo is None or value.utcoffset() is None:
        raise ValueError("API timestamps must include a timezone")
    return value


def _issue_errors(config: PolicyConfig, issue: Issue, opening: datetime,
                  permissions: Mapping[int, str | None]) -> tuple[str, ...]:
    if issue.is_pull_request or issue.repository_id != config.repository_id:
        return (f"#{issue.number} is not an issue in the canonical repository",)
    if config.approval_label not in issue.labels:
        return (f"#{issue.number} lacks the exact approved label",)
    events = [event for event in issue.events
              if event.label == config.approval_label and event.action in {"labeled", "unlabeled"}]
    if not events:
        return (f"#{issue.number} has no approval provenance",)
    latest = max(events, key=lambda event: (_timestamp(event.created_at), event.id))
    if latest.action != "labeled" or not _trusted(latest.actor, config, permissions):
        return (f"#{issue.number} approval is not from a currently authorized human maintainer",)
    if issue.scope_changed_at is not None and _timestamp(latest.created_at) <= _timestamp(issue.scope_changed_at):
        return (f"#{issue.number} scope changed after approval; maintainer reapproval is required",)
    if _timestamp(latest.created_at) >= opening:
        return (f"#{issue.number} approval must precede the latest PR opening; reopen after approval",)
    return ()


def _grant_errors(config, issue, pr, permissions, history):
    if (any(type(identifier) is not int or identifier <= 0 for identifier in (pr.id, pr.author.id, pr.head_repository_id))
            or not valid_ref(pr.head_ref) or not valid_ref(pr.base_ref)):
        raise ValueError("Complete PR identity is required")
    if issue.state != "open":
        return (f"#{issue.number} must remain open; reopening requires fresh authorization",)
    identifier = pointer_id(issue.labels)
    comments = [comment for comment in issue.comments if comment.id == identifier]
    if identifier is None or len(comments) != 1:
        return (f"#{issue.number} requires exactly one valid approved-grant pointer and its comment",)
    comment = comments[0]
    grant = parse_grant(comment.body)
    if (comment.issue_number != issue.number or not _trusted(Actor(comment.author_id, comment.author_kind), config, permissions)
            or _timestamp(comment.created_at) != _timestamp(comment.updated_at) or grant is None):
        return (f"#{issue.number} grant must be an unedited, authorized human command on this issue",)
    boundaries = (*issue.reopened_at, *((issue.scope_changed_at,) if issue.scope_changed_at else ()))
    if any(_timestamp(comment.created_at) <= _timestamp(boundary) for boundary in boundaries):
        return (f"#{issue.number} requires a fresh grant after its latest scope edit or reopening",)
    pointer = POINTER_PREFIX + str(identifier)
    events = [event for event in issue.events if event.label == pointer and event.action in {"labeled", "unlabeled"}]
    if not events:
        return (f"#{issue.number} grant label lacks provenance",)
    current = max(events, key=lambda e: (_timestamp(e.created_at), e.id))
    trusted_applications = [event for event in events if event.action == "labeled"
                            and event.actor.kind == "User" and event.actor.id in config.maintainer_ids]
    if (current.action != "labeled" or not _trusted(current.actor, config, permissions) or not trusted_applications):
        return (f"#{issue.number} grant label is not from a currently authorized human",)
    original = min(trusted_applications, key=lambda e: (_timestamp(e.created_at), e.id))
    if not _trusted(original.actor, config, permissions):
        return (f"#{issue.number} original grant approver is no longer authorized",)
    approvals = [event for event in issue.events if event.label == config.approval_label and event.action == "labeled"]
    following = [event for event in approvals if (_timestamp(event.created_at), event.id)
                 > (_timestamp(original.created_at), original.id)]
    if not following:
        return (f"#{issue.number} grant must precede its original approval",)
    epoch = min(following, key=lambda e: (_timestamp(e.created_at), e.id))
    latest = max(approvals, key=lambda e: (_timestamp(e.created_at), e.id))
    if (epoch.id != latest.id or not (_timestamp(comment.created_at) < _timestamp(original.created_at)
                                    <= _timestamp(current.created_at) < _timestamp(epoch.created_at))):
        return (f"#{issue.number} old grants cannot be reused by reapplying approval or pointer labels",)
    if (grant.author_id != pr.author.id or pr.author.kind != "User"
            or grant.head_repository_id != pr.head_repository_id or grant.head_ref != pr.head_ref
            or grant.base_ref != pr.base_ref):
        return (f"#{issue.number} grant does not authorize this author, source repository and exact branch",)
    if first_consumer(grant.author_id, _timestamp(epoch.created_at), history) != pr.id:
        return (f"#{issue.number} grant was consumed by another PR; request a fresh grant and approval",)
    return ()


def _review_errors(config: PolicyConfig, pr: PullRequest, reviews: tuple[Review, ...],
                   permissions: Mapping[int, str | None]) -> tuple[str, ...]:
    if type(pr.author.id) is int and pr.author.id == config.owner_id and pr.author.kind == "User":
        return ()
    effective = {}
    for review in sorted(reviews, key=lambda item: (_timestamp(item.submitted_at), item.id)):
        # COMMENTED/PENDING are not authoritative state transitions and cannot
        # erase an approval or an outstanding request for changes.
        if review.state in {"APPROVED", "CHANGES_REQUESTED", "DISMISSED"}:
            effective[review.actor.id] = review
    eligible = [review for review in effective.values()
                if review.actor.id != pr.author.id and _trusted(review.actor, config, permissions)]
    if any(review.state == "CHANGES_REQUESTED" for review in eligible):
        return ("An authorized maintainer has an active request for changes",)
    if not pr.head_sha or not any(review.state == "APPROVED" and review.commit_sha == pr.head_sha
                                  for review in eligible):
        return ("A current-HEAD approval by an independent authorized human maintainer is required",)
    return ()


def evaluate(config: PolicyConfig, pr: PullRequest, issues: Mapping[int, Issue],
             reviews: tuple[Review, ...], linked_references: tuple[IssueReference, ...],
             permissions: Mapping[int, str | None], history: tuple[Opening, ...] | None = None) -> Decision:
    """Authorize supplied snapshots, not comments or labels on the PR.

    The adapter must paginate all linked references, issue label events, PR
    reopening events, and reviews; resolve current permissions by stable actor
    ID; and supply the union of API manual/closing links, even hidden examples.
    An incomplete or failed API read must never be passed off as complete here.
    """
    parsed = parse_closing_references(pr.body, config.repository)
    errors = list(parsed.errors)
    if not parsed.references:
        errors.append("The PR body requires at least one explicit closing-keyword issue reference")
    references = set(parsed.references)
    references.update(IssueReference(ref.repository.lower(), ref.number) for ref in linked_references)
    opening = max(_timestamp(value) for value in (pr.created_at, *pr.reopened_at))
    numbers = set()
    for reference in sorted(references):
        if reference.repository != config.repository or type(reference.number) is not int or reference.number <= 0:
            errors.append(f"Invalid linked issue: {reference.repository}#{reference.number}")
            continue
        numbers.add(reference.number)
        snapshot = issues.get(reference.number)
        if snapshot is None or snapshot.number != reference.number:
            errors.append(f"Missing issue snapshot for #{reference.number}")
        else:
            issue_errors = _issue_errors(config, snapshot, opening, permissions)
            errors.extend(issue_errors or _grant_errors(config, snapshot, pr, permissions, history))
    review_errors = _review_errors(config, pr, reviews, permissions)
    return Decision(not errors, not review_errors, tuple(sorted(numbers)), tuple(errors), review_errors)
