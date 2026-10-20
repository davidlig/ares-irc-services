"""Strict human grant syntax and irreversible first-author-PR consumption."""

from dataclasses import replace
from datetime import datetime, timedelta, timezone
import json
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from grants import GrantComment, Opening, first_consumer, parse_grant
from policy import Actor, Issue, LabelEvent, PolicyConfig, PullRequest, evaluate

NOW = datetime(2026, 10, 20, tzinfo=timezone.utc)
OWNER = Actor(561369, "User")
AUTHOR = Actor(123, "User")
CONFIG = PolicyConfig(1167938167, "davidlig/ares-irc-services", 561369, frozenset({561369}))
FIELDS = {"version": 1, "author_id": 123, "head_repository_id": 456,
          "head_ref": "feat/example", "base_ref": "main"}


def command(**changes):
    return "/authorize-contribution " + json.dumps(FIELDS | changes)


def fixture():
    comment = GrantComment(100, 12, OWNER.id, "User", NOW - timedelta(hours=4),
                           NOW - timedelta(hours=4), command())
    pointer = LabelEvent(1, "labeled", "approved-grant:100", OWNER, NOW - timedelta(hours=3))
    approval = LabelEvent(2, "labeled", "approved", OWNER, NOW - timedelta(hours=2))
    issue = Issue(12, CONFIG.repository_id, frozenset({"approved", pointer.label}), False,
                  (pointer, approval), comments=(comment,), state="open")
    pr = PullRequest(AUTHOR, "a" * 40, NOW, (), "Closes #12", id=1001,
                     head_repository_id=456, head_ref="feat/example", base_ref="main")
    history = (Opening(pr.id, AUTHOR.id, NOW, 0),)
    return issue, pr, history


def admitted(issue, pr, history):
    return evaluate(CONFIG, pr, {12: issue}, (), (), {OWNER.id: "admin"}, history).admitted


class GrantSyntaxTests(unittest.TestCase):
    def test_exact_schema_and_ref_validation(self):
        self.assertEqual(456, parse_grant(command()).head_repository_id)
        for field, values in {"version": (True, 2), "author_id": (True, 0, -1, "123"),
                              "head_repository_id": (None, 0), "base_ref": ("develop",),
                              "head_ref": ("refs/heads/a", "-a", "a..b", "a@{b", "a.lock", ".a",
                                           "a//b", "a/", "a.", "a b", "a\\b", "a\n", "@")}.items():
            for value in values:
                with self.subTest(field=field, value=value):
                    self.assertIsNone(parse_grant(command(**{field: value})))
        for text in ("`" + command() + "`", "<!-- " + command() + " -->", command() + "\nextra",
                     command(extra=1), command().replace('"version": 1', '"version": 1,"version": 1'),
                     command().replace('"version": 1', '"version": NaN')):
            self.assertIsNone(parse_grant(text))
        for ref in ("feature/a-b", "fix/a_b", "topic/é", "topic/a+b"):
            self.assertIsNotNone(parse_grant(command(head_ref=ref)))


class BoundGrantTests(unittest.TestCase):
    def test_valid_bound_grant_admits_but_owner_does_not_bypass_binding(self):
        issue, pr, history = fixture()
        self.assertTrue(admitted(issue, pr, history))
        for change in ({"author": OWNER}, {"head_repository_id": 999},
                       {"head_ref": "other"}, {"base_ref": "develop"}):
            self.assertFalse(admitted(issue, replace(pr, **change), history))

    def test_closed_scope_edited_and_reopened_issue_require_new_grant(self):
        issue, pr, history = fixture()
        for changed in (replace(issue, state="closed"), replace(issue, scope_changed_at=NOW),
                        replace(issue, reopened_at=(NOW - timedelta(hours=1),))):
            self.assertFalse(admitted(changed, pr, history))

    def test_missing_deleted_edited_foreign_and_untrusted_comment_never_fall_back(self):
        issue, pr, history = fixture()
        comment = issue.comments[0]
        for comments in ((), (replace(comment, updated_at=NOW),), (replace(comment, issue_number=13),),
                         (replace(comment, author_id=999),), (replace(comment, author_kind="Bot"),),
                         (replace(comment, body=command(extra=1)),)):
            self.assertFalse(admitted(replace(issue, comments=comments), pr, history))
        for labels in (frozenset({"approved"}), issue.labels | {"approved-grant:101"},
                       frozenset({"approved", "approved-grant:0"}),
                       frozenset({"approved", "approved-grant:101"})):
            self.assertFalse(admitted(replace(issue, labels=labels), pr, history))

    def test_both_label_provenances_and_strict_order_required(self):
        issue, pr, history = fixture()
        pointer, approval = issue.events
        for events in ((approval,), (pointer,),
                       (replace(pointer, actor=AUTHOR), approval),
                       (pointer, replace(approval, actor=AUTHOR)),
                       (replace(pointer, created_at=issue.comments[0].created_at), approval),
                       (pointer, replace(approval, created_at=pointer.created_at))):
            self.assertFalse(admitted(replace(issue, events=events), pr, history))

    def test_relabel_and_old_pointer_reapplication_cannot_reset_original_epoch(self):
        issue, pr, history = fixture()
        later = LabelEvent(3, "labeled", "approved", OWNER, NOW - timedelta(hours=1))
        self.assertFalse(admitted(replace(issue, events=issue.events + (later,)), pr, history))
        old_pointer = replace(issue.events[0], id=4, created_at=NOW - timedelta(minutes=30))
        self.assertFalse(admitted(replace(issue, events=issue.events + (old_pointer,)), pr, history))

    def test_equal_time_original_approval_cannot_be_repaired_by_relabeling(self):
        issue, pr, history = fixture()
        pointer, approval = issue.events
        tied = replace(approval, created_at=pointer.created_at)
        later = replace(approval, id=3)
        self.assertFalse(admitted(replace(issue, events=(pointer, tied, later)), pr, history))

    def test_fresh_comment_pointer_and_epoch_authorize_after_issue_reopening(self):
        issue, pr, history = fixture()
        reopened = NOW - timedelta(hours=2)
        old = Opening(999, AUTHOR.id, NOW - timedelta(hours=3), 0)
        created = reopened + timedelta(minutes=10)
        comment = replace(issue.comments[0], id=101, created_at=created, updated_at=created)
        pointer = replace(issue.events[0], id=3, label="approved-grant:101", created_at=created + timedelta(minutes=10))
        approval = replace(issue.events[1], id=4, created_at=created + timedelta(minutes=20))
        fresh = replace(issue, reopened_at=(reopened,), labels=frozenset({"approved", pointer.label}),
                        comments=issue.comments + (comment,), events=issue.events + (pointer, approval))
        self.assertTrue(admitted(fresh, pr, (old,) + history))

    def test_renamed_pointer_without_matching_history_never_grants(self):
        issue, pr, history = fixture()
        fresh = replace(issue, labels=frozenset({"approved", "approved-grant:101"}),
                        comments=(replace(issue.comments[0], id=101),))
        self.assertFalse(admitted(fresh, pr, history))

    def test_historical_permission_loss_does_not_reset_original_pointer_epoch(self):
        issue, pr, history = fixture()
        config = replace(CONFIG, maintainer_ids=frozenset({561369, 777}))
        old = replace(issue.events[0], actor=Actor(777, "User"))
        current = replace(issue.events[0], id=5, created_at=NOW - timedelta(hours=1))
        approval = replace(issue.events[1], id=6, created_at=NOW - timedelta(minutes=30))
        changed = replace(issue, events=(old, *issue.events[1:], current, approval))
        self.assertFalse(evaluate(config, pr, {12: changed}, (), (), {561369: "admin", 777: "read"}, history).admitted)

    def test_comment_author_permission_is_independent_of_label_provenance(self):
        issue, pr, history = fixture()
        config = replace(CONFIG, maintainer_ids=frozenset({561369, 777}))
        changed = replace(issue, comments=(replace(issue.comments[0], author_id=777),))
        for permission, expected in (("write", True), ("maintain", True), ("admin", True), ("read", False), (None, False)):
            result = evaluate(config, pr, {12: changed}, (), (), {561369: "admin", 777: permission}, history)
            self.assertEqual(expected, result.admitted)

    def test_first_author_pr_consumes_even_closed_wrong_branch_and_without_issue_reference(self):
        issue, pr, history = fixture()
        earlier = Opening(999, AUTHOR.id, NOW - timedelta(hours=1), 0)
        self.assertFalse(admitted(issue, pr, (earlier,) + history))
        other_author = replace(earlier, author_id=999)
        self.assertTrue(admitted(issue, pr, (other_author,) + history))

    def test_same_pr_reopen_retains_consumption_but_new_pr_is_rejected(self):
        issue, pr, history = fixture()
        reopened = replace(pr, reopened_at=(NOW + timedelta(hours=1),))
        history += (Opening(pr.id, AUTHOR.id, reopened.reopened_at[0], 99),)
        self.assertTrue(admitted(issue, reopened, history))
        self.assertFalse(admitted(issue, replace(pr, id=1002), history))

    def test_old_creation_can_consume_on_reopening_and_history_ties_are_stable(self):
        issue, pr, history = fixture()
        old = Opening(900, AUTHOR.id, NOW - timedelta(days=1), 0)
        reopened = Opening(900, AUTHOR.id, NOW - timedelta(hours=1), 10)
        self.assertFalse(admitted(issue, pr, (old, reopened) + history))
        self.assertEqual(900, first_consumer(AUTHOR.id, NOW - timedelta(hours=2),
                         (replace(reopened, pr_id=901), reopened)))

    def test_missing_or_ambiguous_history_is_unavailable_not_an_admission_accusation(self):
        issue, pr, history = fixture()
        for invalid in (None, (), (replace(history[0], at=issue.events[1].created_at),)):
            with self.assertRaises(ValueError):
                admitted(issue, pr, invalid)

    def test_missing_or_boolean_current_pr_identity_is_unavailable(self):
        issue, pr, history = fixture()
        for field, value in (("id", None), ("id", True), ("head_repository_id", True),
                             ("head_repository_id", None), ("head_ref", None)):
            with self.assertRaises(ValueError):
                admitted(issue, replace(pr, **{field: value}), history)


if __name__ == "__main__":
    unittest.main()
