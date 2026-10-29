# Self-only UNBAN

`UNBAN <#channel>` removes every ordinary channel ban that matches the current requester under the active IRCd's matching rules. It never accepts another nickname or mask.

The requester must be identified and meet the channel's configurable UNBAN threshold (default 200). The identified founder retains implicit access. Neither channel membership nor operator status is required. The original stored matching masks are removed; unrelated bans, extended bans, exceptions and AKICK are unchanged. No matching bans means an informative reply without channel mutation. Executing UNBAN does not add channel HISTORY; LEVELS configuration retains its existing HISTORY policy.

## Protocol matching references

Matching lives inside each protocol adapter. UnrealStandalone and UnrealUdb remain independent implementations. A dedicated IRC query exposes only original matching masks and channel timestamp to the ChanServ outbound adapter; shared user/channel projections and AKICK policy are unchanged.

- UnrealIRCd 6.2.7 release commit: https://github.com/unrealircd/unrealircd/commit/7a3f9df5ca0425fa83afd809432f7789196d8c1a
- Ordinary masks: `src/channel.c` (`ban_check_mask`), `src/modules/tkl.c` (`_match_user`, MATCH_CHECK_ALL), `src/match.c`.
- InspIRCd v4: https://github.com/inspircd/inspircd/blob/insp4/src/channels.cpp (`Channel::CheckBan`), `src/wildcard.cpp`, `src/cidr.cpp`, `src/socket.cpp`.

IRC globs interpret only `*` and `?`, not shell character classes or escaping. InspIRCd uses the negotiated casemapping, both real/displayed idents, real/displayed hosts and IP. Unreal uses the current username, real/visible/cloaked hosts and IP. Both support IPv4/IPv6 literals, wildcard IPs and CIDR with their own prefix rules: Unreal rejects /0 and overwide prefixes; InspIRCd permits /0 and clamps overwide prefixes. Extended bans are excluded before ordinary evaluation, including wrappers around ordinary masks.

An unavailable user, incomplete IP identity or unsupported protocol capability is an evaluation error, not an empty match set. No mode changes are emitted in that case. A registered channel absent from the live network is reported separately.
