# C2P Competition Chat

This plugin adds a lightweight, ephemeral chat widget to competition pages so that players (or teams) can coordinate once a match starts. Use the `[c2p_competition_chat]` shortcode inside a competition template (or directly in the post content) to render the interface.

## How it works

* Every competition pulls the `match` posts that are related via the `_c2p_competition_id` meta key.
* A match is considered **active** when `_c2p_match_started` is truthy and `_c2p_match_score_reported` is falsy. Filters allow you to change this logic.
* Participants are resolved either from `_c2p_match_players` (modus = 1) or, for team play, from `_c2p_match_teams` ➜ `_c2p_team_members`.
* Messages are stored inside the custom table `{prefix}c2p_chat_messages`, scoped per match, and automatically purged once a score is reported or the TTL (defaults to 6 hours) expires.

## Shortcode parameters

| Attribute | Description |
|-----------|-------------|
| `competition_id` | Override the detected competition/post ID. |
| `title` | Custom text for the widget header. |

## Filters

* `c2p_chat_active_matches` – replace the match collection for a competition.
* `c2p_chat_is_match_active` – tweak how the plugin decides when a match is active.
* `c2p_chat_match_participants` – customize how participant user IDs are resolved.
* `c2p_chat_message_ttl` – change the automatic expiry time for messages (default 6 hours).
* `c2p_chat_poll_interval` – adjust the JS polling interval in milliseconds (default 5000).

## Front-end behavior

The chat UI renders on the competition page, automatically restricts access to participants of the active match, and polls the server for new messages while the match is running. Messages disappear when a score is reported or when the TTL expires.
