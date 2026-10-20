"""Pure, strictly parsed prior authorization and first-author-PR consumption."""

from dataclasses import dataclass
from datetime import datetime
import json
import re

POINTER_PREFIX = "approved-grant:"


@dataclass(frozen=True)
class Grant:
    author_id: int
    head_repository_id: int
    head_ref: str
    base_ref: str


@dataclass(frozen=True)
class GrantComment:
    id: int
    issue_number: int
    author_id: int
    author_kind: str
    created_at: datetime
    updated_at: datetime
    body: str


@dataclass(frozen=True)
class Opening:
    pr_id: int
    author_id: int
    at: datetime
    event_id: int


def valid_ref(value):
    if (not isinstance(value, str) or not value or value == "@" or value.startswith(("/", "-", "refs/heads/"))
            or value.endswith(("/", ".")) or any(part in value for part in ("..", "//", "@{"))
            or any(ord(c) <= 32 or ord(c) == 127 or c in "~^:?*[\\" for c in value)):
        return False
    return all(not part.startswith(".") and not part.endswith(".lock") for part in value.split("/"))


def parse_grant(body):
    if not isinstance(body, str):
        return None
    match = re.fullmatch(r"/authorize-contribution[ \t]+(\{.*\})", body.strip(), re.DOTALL)
    if not match:
        return None
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError()
            result[key] = value
        return result
    def invalid_constant(_):
        raise ValueError()
    try:
        raw = json.loads(match[1], object_pairs_hook=unique, parse_constant=invalid_constant)
        if (not isinstance(raw, dict) or set(raw) != {"version", "author_id", "head_repository_id", "head_ref", "base_ref"}
                or type(raw["version"]) is not int or raw["version"] != 1
                or any(type(raw[k]) is not int or raw[k] <= 0 for k in ("author_id", "head_repository_id"))
                or not valid_ref(raw["head_ref"]) or raw["base_ref"] != "main"):
            return None
        return Grant(raw["author_id"], raw["head_repository_id"], raw["head_ref"], raw["base_ref"])
    except (ValueError, TypeError, RecursionError):
        return None


def pointer_id(labels):
    pointers = [label for label in labels if label.startswith(POINTER_PREFIX)]
    if len(pointers) != 1 or not re.fullmatch(r"approved-grant:[1-9][0-9]*", pointers[0]):
        return None
    return int(pointers[0][len(POINTER_PREFIX):])


def first_consumer(author_id, epoch, history):
    """Body edits, branch changes and PR closure cannot release a consumed grant."""
    if history is None:
        raise ValueError("Complete opening history is required")
    eligible = []
    for event in history:
        if (not isinstance(event, Opening) or type(event.pr_id) is not int or event.pr_id <= 0
                or type(event.author_id) is not int or event.author_id <= 0
                or type(event.event_id) is not int or event.event_id < 0
                or event.at.tzinfo is None or event.at.utcoffset() is None):
            raise ValueError("Invalid opening history")
        if event.author_id != author_id:
            continue
        if event.at == epoch:
            raise ValueError("Opening and approval timestamps are ambiguous")
        if event.at > epoch:
            eligible.append(event)
    if not eligible:
        raise ValueError("Current PR opening is missing from history")
    return min(eligible, key=lambda e: (e.at, e.pr_id, e.event_id)).pr_id
