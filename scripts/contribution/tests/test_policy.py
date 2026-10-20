"""Deterministic policy tests, with no GitHub or credential access."""

import json
from dataclasses import replace
from datetime import datetime, timedelta, timezone
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from policy import (
    Actor, Issue, IssueReference, LabelEvent, PolicyConfig, PullRequest, Review,
    evaluate, parse_closing_references,
)

ROOT = Path(__file__).resolve().parents[3]
NOW = datetime(2026, 10, 20, tzinfo=timezone.utc)
OWNER = Actor(561369, "User")
MAINTAINER = Actor(777, "User")
AUTHOR = Actor(888, "User")
BOT = Actor(777, "Bot")
REPO = "davidlig/ares-irc-services"
CONFIG = PolicyConfig(1167938167, REPO, 561369, frozenset({561369, 777}))


def event(actor=OWNER, when=NOW - timedelta(days=1), action="labeled", identifier=1):
    return LabelEvent(identifier, action, "approved", actor, when)


def issue(number=12, **changes):
    return replace(Issue(number, 1167938167, frozenset({"approved"}), False, (event(),)), **changes)


def pull(**changes):
    return replace(PullRequest(AUTHOR, "head", NOW, (), "Fixes #12"), **changes)


def review(actor=OWNER, state="APPROVED", sha="head", identifier=1, when=NOW):
    return Review(identifier, actor, state, sha, when)


def decision(pr=None, issues=None, reviews=None, links=(), permissions=None):
    return evaluate(
        CONFIG, pr or pull(), issues if issues is not None else {12: issue()},
        reviews if reviews is not None else (review(),), links,
        permissions if permissions is not None else {561369: "admin", 777: "write", 888: "write"},
    )


class ReferenceTests(unittest.TestCase):
    def parse(self, text):
        return parse_closing_references(text, REPO)

    def test_every_keyword_case_and_colon(self):
        for keyword in ("close", "closes", "closed", "fix", "fixes", "fixed", "resolve", "resolves", "resolved"):
            for separator in (" ", ": "):
                with self.subTest(keyword=keyword, separator=separator):
                    parsed = self.parse(keyword.upper() + separator + "#12")
                    self.assertEqual((IssueReference(REPO, 12),), parsed.references)
                    self.assertEqual((), parsed.errors)

    def test_all_forms_lists_repeats_and_multiple_declarations(self):
        parsed = self.parse("Fixes #12, #13 and DAVIDLIG/ares-irc-services#14; #12\n"
                            "Resolved: https://github.com/davidlig/ares-irc-services/issues/15.\n"
                            "Closes #16\n- #17\n- #18")
        self.assertEqual(tuple(range(12, 19)), tuple(ref.number for ref in parsed.references))
        self.assertEqual((), parsed.errors)

    def test_plain_mentions_and_explanatory_prose_do_not_authorize(self):
        for text in ("See #12", "This fixes the parser described in #12", "Issue #12 is approved"):
            self.assertEqual((), self.parse(text).references)

    def test_markdown_examples_do_not_authorize(self):
        body = ("<!-- Fixes #1 -->\n```text\nFixes #2\n```\n~~~\nFixes #3\n~~~\n"
                "Example `Fixes #4`\n    Fixes #5\nFixes #12")
        self.assertEqual((IssueReference(REPO, 12),), self.parse(body).references)

    def test_removing_markdown_never_manufactures_closing_keywords(self):
        for text in ("Fi`example`xes #12", "Fi<!-- example -->xes #12"):
            self.assertEqual((), self.parse(text).references)

    def test_malformed_and_foreign_second_references_fail(self):
        for bad in ("#0", "#wat", "#13oops", "other/repo#13", "https://github.com/other/repo/issues/13",
                    "https://github.com/davidlig/ares-irc-services/pull/13",
                    "https://github.com/davidlig/ares-irc-services/issues/nope",
                    "https://example.org/davidlig/ares-irc-services/issues/13", "garbage"):
            for body in (f"Fixes #12, {bad}", f"Fixes #12\nCloses: {bad}"):
                with self.subTest(body=body):
                    self.assertTrue(self.parse(body).errors)

    def test_oxford_comma_and_ordered_list_references(self):
        for body in ("Fixes #12, #13, and #14", "Fixes:\n1. #12\n2. #13\n3. #14"):
            parsed = self.parse(body)
            self.assertEqual((12, 13, 14), tuple(ref.number for ref in parsed.references))
            self.assertEqual((), parsed.errors)

    def test_bulleted_list_can_start_after_colon(self):
        parsed = self.parse("Fixes:\n- #12\n- #13")
        self.assertEqual((12, 13), tuple(ref.number for ref in parsed.references))
        self.assertEqual((), parsed.errors)

    def test_empty_or_punctuated_bad_declarations_fail_alongside_valid_one(self):
        for bad in ("Fixes: , #wat", "Closes:", "Fixes: ; #13", "Fixes #12, closes nonsense"):
            self.assertTrue(self.parse("Resolves #12\n" + bad).errors)

    def test_malformed_first_declaration_cannot_be_hidden_by_valid_later_one(self):
        self.assertTrue(self.parse("Fixes: invalid\nCloses #12").errors)

    def test_reference_section_ends_before_headings_and_unrelated_checklists(self):
        for suffix in ("\n\n## Tests\nunit tests", "\n## Tests\nunit tests",
                       "\n\n1. See #13 for background", "\n- [ ] Test #13 manually"):
            parsed = self.parse("Fixes #12" + suffix)
            self.assertEqual((IssueReference(REPO, 12),), parsed.references)
            self.assertEqual((), parsed.errors)

    def test_period_ends_list_and_other_mentions_stay_explanatory(self):
        parsed = self.parse("Fixes #12. See #13 for background; this fixes parsing too.")
        self.assertEqual((IssueReference(REPO, 12),), parsed.references)
        self.assertEqual((), parsed.errors)


class AdmissionTests(unittest.TestCase):
    def test_approved_issue_and_current_review_admit(self):
        result = decision()
        self.assertTrue(result.admitted)
        self.assertTrue(result.review_satisfied)
        self.assertEqual((12,), result.issue_numbers)
        self.assertEqual((), result.admission_errors)

    def test_all_body_and_manual_links_are_validated_and_deduplicated(self):
        result = decision(pull(body="Fixes #12, #13"), {12: issue(), 13: issue(13), 14: issue(14)},
                          links=(IssueReference(REPO, 13), IssueReference(REPO, 14)))
        self.assertTrue(result.admitted)
        self.assertEqual((12, 13, 14), result.issue_numbers)
        for links in ((IssueReference("other/repo", 12),), (IssueReference(REPO, 99),)):
            with self.subTest(links=links):
                self.assertFalse(decision(links=links).admitted)

    def test_no_body_closing_reference_even_with_valid_manual_link(self):
        for body in ("See #12", "<!-- Fixes #12 -->", "```\nFixes #12\n```", ""):
            self.assertFalse(decision(pull(body=body), links=(IssueReference(REPO, 12),)).admitted)

    def test_api_link_cannot_hide_in_code_or_comments(self):
        for extra in ("<!-- Fixes #13 -->", "`Fixes #13`"):
            result = decision(pull(body="Fixes #12\n" + extra), {12: issue(), 13: issue(13, labels=frozenset())},
                              links=(IssueReference(REPO, 13),))
            self.assertFalse(result.admitted)

    def test_invalid_second_reference_is_not_dropped(self):
        for body in ("Fixes #12, #wat", "Fixes #12, other/repo#13", "Fixes #12\nCloses: nonsense"):
            self.assertFalse(decision(pull(body=body)).admitted)

    def test_issue_not_pull_request_and_canonical_repository_required(self):
        for bad in (issue(is_pull_request=True), issue(repository_id=17)):
            self.assertFalse(decision(issues={12: bad}).admitted)

    def test_missing_or_mismatched_issue_snapshot_fails(self):
        self.assertFalse(decision(issues={}).admitted)
        self.assertFalse(decision(issues={12: issue(13)}).admitted)

    def test_exact_label_not_legacy_alias_or_case_variant(self):
        for labels in (frozenset(), frozenset({"status:approved"}), frozenset({"Approved"})):
            self.assertFalse(decision(issues={12: issue(labels=labels)}).admitted)

    def test_approval_requires_human_allowlist_and_current_write_permission(self):
        for actor in (AUTHOR, BOT, Actor(123, "User"), Actor(561369, "Organization")):
            self.assertFalse(decision(issues={12: issue(events=(event(actor),))}).admitted)
        for permission in ("read", "triage", "none", None):
            self.assertFalse(decision(permissions={561369: permission}).admitted)
        for permission in ("write", "maintain", "admin"):
            self.assertTrue(decision(permissions={561369: permission}).admitted)

    def test_label_history_latest_effective_event_wins_not_api_order(self):
        unauthorized = event(AUTHOR, NOW - timedelta(hours=1), identifier=3)
        removed = event(when=NOW - timedelta(hours=2), action="unlabeled", identifier=2)
        self.assertFalse(decision(issues={12: issue(events=(unauthorized, event(), removed))}).admitted)
        self.assertFalse(decision(issues={12: issue(events=(event(), removed))}).admitted)
        unrelated = replace(unauthorized, label="other")
        self.assertTrue(decision(issues={12: issue(events=(event(), unrelated))}).admitted)

    def test_label_timestamp_ties_use_stable_event_id(self):
        untrusted = event(AUTHOR, identifier=2)
        self.assertFalse(decision(issues={12: issue(events=(untrusted, event()))}).admitted)

    def test_missing_provenance_fails(self):
        self.assertFalse(decision(issues={12: issue(events=())}).admitted)

    def test_latest_label_reapplication_must_precede_current_opening(self):
        later = event(when=NOW + timedelta(hours=1), identifier=2)
        updated = issue(events=(event(), later))
        self.assertFalse(decision(issues={12: updated}).admitted)
        reopened = pull(reopened_at=(NOW + timedelta(hours=2),))
        self.assertTrue(decision(reopened, {12: updated}).admitted)
        self.assertFalse(decision(pull(reopened_at=(NOW - timedelta(hours=2),)), {12: updated}).admitted)

    def test_equal_timestamps_are_not_prior_approval(self):
        self.assertFalse(decision(issues={12: issue(events=(event(when=NOW),))}).admitted)

    def test_latest_reopening_is_selected_without_order_assumptions(self):
        pr = pull(reopened_at=(NOW + timedelta(hours=2), NOW + timedelta(hours=1)))
        self.assertTrue(decision(pr, {12: issue(events=(event(when=NOW + timedelta(minutes=30)),))}).admitted)

    def test_closed_issue_state_is_not_an_admission_requirement(self):
        # State is deliberately absent: approval, not open/closed state, authorizes work.
        self.assertTrue(decision().admitted)

    def test_issue_scope_edit_after_approval_requires_reapproval(self):
        edited = NOW - timedelta(hours=12)
        snapshot = issue(scope_changed_at=edited)
        self.assertFalse(decision(issues={12: snapshot}).admitted)
        reapproved = replace(snapshot, events=(event(when=NOW - timedelta(hours=6)),))
        self.assertTrue(decision(issues={12: reapproved}).admitted)
        self.assertFalse(decision(issues={12: replace(snapshot, events=(event(when=edited),))}).admitted)

    def test_owner_author_exemption_never_bypasses_issue_approval(self):
        result = decision(pull(author=OWNER), reviews=())
        self.assertTrue(result.review_satisfied)
        self.assertFalse(decision(pull(author=OWNER), {12: issue(labels=frozenset())}, reviews=()).admitted)


class ReviewTests(unittest.TestCase):
    def test_missing_review_blocks_merge_not_admission(self):
        result = decision(reviews=())
        self.assertTrue(result.admitted)
        self.assertFalse(result.review_satisfied)
        self.assertTrue(result.review_errors)

    def test_head_sha_must_match_even_after_empty_commit(self):
        self.assertFalse(decision(pull(head_sha="empty-commit")).review_satisfied)

    def test_reviewer_must_be_allowlisted_currently_write_human_and_not_author(self):
        for actor in (AUTHOR, BOT, Actor(123, "User")):
            self.assertFalse(decision(reviews=(review(actor),)).review_satisfied)
        self.assertFalse(decision(permissions={561369: "triage"}).review_satisfied)
        self.assertFalse(decision(pull(author=MAINTAINER), reviews=(review(MAINTAINER),)).review_satisfied)

    def test_current_permission_equivalents_for_independent_reviewer(self):
        for permission in ("write", "maintain", "admin"):
            self.assertTrue(decision(permissions={561369: permission}).review_satisfied)
        self.assertFalse(decision(permissions={}).review_satisfied)

    def test_comment_does_not_erase_approval(self):
        reviews = (review(), review(state="COMMENTED", identifier=2, when=NOW + timedelta(minutes=1)))
        self.assertTrue(decision(reviews=reviews).review_satisfied)

    def test_comment_does_not_erase_changes_requested(self):
        reviews = (review(), review(state="CHANGES_REQUESTED", identifier=2, when=NOW + timedelta(minutes=1)),
                   review(state="COMMENTED", identifier=3, when=NOW + timedelta(minutes=2)))
        self.assertFalse(decision(reviews=reviews).review_satisfied)

    def test_authoritative_review_state_reduction_and_dismissal(self):
        for state in ("DISMISSED", "CHANGES_REQUESTED"):
            later = review(state=state, identifier=2, when=NOW + timedelta(minutes=1))
            self.assertFalse(decision(reviews=(later, review())).review_satisfied)
            newest = review(identifier=3, when=NOW + timedelta(minutes=2))
            self.assertTrue(decision(reviews=(newest, later, review())).review_satisfied)

    def test_active_changes_request_from_other_maintainer_blocks(self):
        self.assertFalse(decision(reviews=(review(), review(MAINTAINER, "CHANGES_REQUESTED", identifier=2))).review_satisfied)

    def test_only_stable_owner_id_gets_author_exemption(self):
        self.assertTrue(decision(pull(author=OWNER), reviews=()).review_satisfied)
        self.assertFalse(decision(pull(author=Actor(123, "User")), reviews=()).review_satisfied)
        self.assertFalse(decision(pull(author=Actor(561369, "Bot")), reviews=()).review_satisfied)

    def test_timestamp_ties_reduce_by_stable_review_id(self):
        self.assertFalse(decision(reviews=(review(), review(state="DISMISSED", identifier=2))).review_satisfied)


class ConfigurationTests(unittest.TestCase):
    def test_checked_in_trust_is_numeric_and_pinned(self):
        raw = json.loads((ROOT / ".github/contribution-policy.json").read_text())
        config = PolicyConfig.from_mapping(raw)
        self.assertEqual(1167938167, config.repository_id)
        self.assertEqual(REPO, config.repository)
        self.assertEqual(561369, config.owner_id)
        self.assertEqual(frozenset({561369}), config.maintainer_ids)
        self.assertEqual("approved", config.approval_label)
        self.assertEqual("Contribution policy", config.check_name)

    def test_config_rejects_mutable_names_alias_label_and_invalid_ids(self):
        raw = {"repository_id": 1167938167, "repository": REPO, "owner_id": 561369,
               "maintainer_ids": [561369], "approval_label": "approved", "check_name": "Contribution policy"}
        for key, value in (("maintainer_ids", ["davidlig"]), ("maintainer_ids", [True]),
                           ("maintainer_ids", []), ("repository_id", 0), ("owner_id", "561369"),
                           ("approval_label", "status:approved"), ("check_name", "other")):
            with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                PolicyConfig.from_mapping({**raw, key: value})


if __name__ == "__main__":
    unittest.main()
