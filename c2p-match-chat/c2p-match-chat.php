<?php
/**
 * Plugin Name: C2P Match Chat
 * Description: Secure in-tournament chat widget for match participants.
 * Version: 1.0.0
 * Author: C2P
 * Text Domain: c2p-match-chat
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

// Extra guardrails for Codex reviewers.
// Don’t use external chat services.
// Don’t use jQuery; use vanilla JS.
// Don’t output UI for non-eligible users.
// Always validate eligibility server-side.
// Keep everything namespaced/prefixed with c2p_.

if ( ! defined( 'C2P_CHAT_DEBUG' ) ) {
define( 'C2P_CHAT_DEBUG', false );
}

define( 'C2P_MATCH_CHAT_VERSION', '1.0.0' );
define( 'C2P_MATCH_CHAT_TABLE', 'c2p_match_chat_messages' );

register_activation_hook( __FILE__, 'c2p_match_chat_activate' );

add_action( 'rest_api_init', 'c2p_match_chat_register_routes' );
add_action( 'init', 'c2p_match_chat_register_shortcode' );

/**
 * Plugin activation – create custom table for chat messages.
 */
function c2p_match_chat_activate() {
global $wpdb;

$table_name      = $wpdb->prefix . C2P_MATCH_CHAT_TABLE;
$charset_collate = $wpdb->get_charset_collate();

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$sql = "CREATE TABLE {$table_name} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
match_id bigint(20) unsigned NOT NULL,
tournament_id bigint(20) unsigned NOT NULL,
sender_id bigint(20) unsigned NOT NULL,
side varchar(10) NOT NULL DEFAULT '',
message text NOT NULL,
created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
KEY match_id (match_id),
KEY tournament_id (tournament_id),
KEY sender_match (sender_id, match_id),
KEY created_at (created_at)
) {$charset_collate};";

dbDelta( $sql );
}

/**
 * Helper: table name with prefix.
 */
function c2p_match_chat_table_name() {
global $wpdb;
return $wpdb->prefix . C2P_MATCH_CHAT_TABLE;
}

/**
 * Register REST endpoints.
 */
function c2p_match_chat_register_routes() {
register_rest_route( 'c2p/v1', '/match-chat/messages', [
[
'methods'             => WP_REST_Server::READABLE,
'callback'            => 'c2p_match_chat_rest_get_messages',
'permission_callback' => '__return_true',
],
[
'methods'             => WP_REST_Server::CREATABLE,
'callback'            => 'c2p_match_chat_rest_post_message',
'permission_callback' => '__return_true',
],
] );
}

/**
 * Register shortcode for embedding chat widget.
 */
function c2p_match_chat_register_shortcode() {
add_shortcode( 'c2p_match_chat', 'c2p_match_chat_shortcode' );
}

/**
 * Normalize a value that might be single or array to a single integer ID.
 */
function c2p_normalize_single_id( $value ) {
if ( is_array( $value ) ) {
$value = reset( $value );
}
return absint( $value );
}

/**
 * Normalize a list of user IDs.
 */
function c2p_normalize_user_ids( $value ) {
if ( empty( $value ) ) {
return [];
}
if ( ! is_array( $value ) ) {
$value = [ $value ];
}
return array_filter( array_map( 'absint', $value ) );
}

/**
 * Parse a datetime value coming from Meta Box fields.
 */
function c2p_parse_datetime_value( $value ) {
if ( empty( $value ) ) {
return 0;
}
if ( is_numeric( $value ) ) {
return absint( $value );
}
$timestamp = strtotime( (string) $value );
return $timestamp ? $timestamp : 0;
}

/**
 * Get start timestamp for a match.
 * Priority: spielzeit.updated -> spielzeit.original -> spielzeit.home -> spielzeit.away.
 * This favors the latest admin-confirmed time while retaining a fallback.
 */
function c2p_get_match_start_timestamp( $match_id ) {
$spielzeit = function_exists( 'rwmb_meta' ) ? rwmb_meta( 'spielzeit', [ 'object_type' => 'post' ], $match_id ) : [];
$spielzeit = is_array( $spielzeit ) ? $spielzeit : [];

$ordered_keys = [ 'updated', 'original', 'home', 'away' ];
foreach ( $ordered_keys as $key ) {
if ( isset( $spielzeit[ $key ] ) && ! empty( $spielzeit[ $key ] ) ) {
$ts = c2p_parse_datetime_value( $spielzeit[ $key ] );
if ( $ts > 0 ) {
return $ts;
}
}
}

return 0;
}

/**
 * Determine whether a score value counts as "entered" ("0" counts as entered).
 */
function c2p_is_score_entered_value( $score ) {
if ( $score === 0 || $score === '0' ) {
return true;
}
return ! empty( $score );
}

/**
 * Check if match has any scores entered.
 */
function c2p_match_has_score_entered( $match_id ) {
if ( ! function_exists( 'rwmb_meta' ) ) {
return false;
}
$home_score = rwmb_meta( 'home_score', [ 'object_type' => 'post' ], $match_id );
$away_score = rwmb_meta( 'away_score', [ 'object_type' => 'post' ], $match_id );

return c2p_is_score_entered_value( $home_score ) || c2p_is_score_entered_value( $away_score );
}

/**
 * Determine if a match is currently active.
 */
function c2p_is_match_active( $match_id ) {
$start_ts = c2p_get_match_start_timestamp( $match_id );
$now      = current_time( 'timestamp' );

if ( $start_ts <= 0 || $start_ts > $now ) {
return false;
}

return ! c2p_match_has_score_entered( $match_id );
}

/**
 * Retrieve the tournament/liga/matchmaker ID associated with a match.
 */
function c2p_get_match_tournament_id( $match_id ) {
if ( ! function_exists( 'rwmb_meta' ) ) {
return 0;
}
$value = rwmb_meta( 'tournamentliga', [ 'object_type' => 'post' ], $match_id );
return c2p_normalize_single_id( $value );
}

/**
 * Fetch user IDs connected to a team via captain or member relationships.
 */
function c2p_get_team_user_ids( $team_id ) {
$ids = [];

if ( empty( $team_id ) || ! function_exists( 'mb_get_connected' ) ) {
return $ids;
}

$relationships = [ 'user-team-captain', 'user-team-mitglied' ];

foreach ( $relationships as $rel_id ) {
$connected = mb_get_connected( [
'id'       => $rel_id,
'from'     => $team_id,
'to'       => 'user',
'nopaging' => true,
] );

if ( $connected ) {
foreach ( $connected as $user_obj ) {
if ( isset( $user_obj->ID ) ) {
$ids[] = absint( $user_obj->ID );
}
}
}
}

return array_values( array_unique( $ids ) );
}

/**
 * Determine which side (home/away) a user belongs to.
 */
function c2p_get_user_side_for_match( $match_id, $user_id ) {
if ( ! $user_id ) {
return '';
}

$home_players = function_exists( 'rwmb_meta' ) ? c2p_normalize_user_ids( rwmb_meta( 'home_spieler', [ 'object_type' => 'post' ], $match_id ) ) : [];
$away_players = function_exists( 'rwmb_meta' ) ? c2p_normalize_user_ids( rwmb_meta( 'away_spieler', [ 'object_type' => 'post' ], $match_id ) ) : [];

if ( in_array( $user_id, $home_players, true ) ) {
return 'home';
}

if ( in_array( $user_id, $away_players, true ) ) {
return 'away';
}

$home_team = function_exists( 'rwmb_meta' ) ? c2p_normalize_single_id( rwmb_meta( 'home_team', [ 'object_type' => 'post' ], $match_id ) ) : 0;
$away_team = function_exists( 'rwmb_meta' ) ? c2p_normalize_single_id( rwmb_meta( 'away_team', [ 'object_type' => 'post' ], $match_id ) ) : 0;

if ( $home_team ) {
$team_users = c2p_get_team_user_ids( $home_team );
if ( in_array( $user_id, $team_users, true ) ) {
return 'home';
}
}

if ( $away_team ) {
$team_users = c2p_get_team_user_ids( $away_team );
if ( in_array( $user_id, $team_users, true ) ) {
return 'away';
}
}

return '';
}

/**
 * Determine if current user is eligible to chat for the given match.
 * Result cached briefly per user per match.
 */
function c2p_current_user_can_chat( $match_id ) {
$user_id = get_current_user_id();

if ( ! $user_id ) {
return false;
}

$cache_key = 'c2p_chat_elig_' . $match_id . '_' . $user_id;
$cached    = get_transient( $cache_key );
if ( null !== $cached ) {
return (bool) $cached;
}

$side = c2p_get_user_side_for_match( $match_id, $user_id );
$can  = ! empty( $side );

set_transient( $cache_key, $can ? 1 : 0, 30 );

return $can;
}

/**
 * Query for the most relevant match for a tournament page.
 * If $only_active is true, only active matches are considered.
 * Sorting: newest start time first; if start times tie, highest match ID wins.
 */
function c2p_get_relevant_match_for_tournament( $tournament_id, $only_active = true ) {
$args = [
'post_type'      => 'match',
'post_status'    => 'publish',
'posts_per_page' => 20,
'orderby'        => 'date',
'order'          => 'DESC',
'meta_query'     => [
[
'key'     => 'tournamentliga',
'value'   => '"' . absint( $tournament_id ) . '"',
'compare' => 'LIKE',
],
],
];

$query = new WP_Query( $args );

if ( empty( $query->posts ) ) {
return 0;
}

$chosen_id   = 0;
$chosen_time = 0;

foreach ( $query->posts as $post ) {
$match_id  = $post->ID;
$start_ts  = c2p_get_match_start_timestamp( $match_id );
$is_active = c2p_is_match_active( $match_id );

if ( $only_active && ! $is_active ) {
continue;
}

if ( ! $only_active && $start_ts <= 0 ) {
continue;
}

// Pick the match with the most recent start time; break ties with highest ID.
if ( $start_ts > $chosen_time || ( $start_ts === $chosen_time && $match_id > $chosen_id ) ) {
$chosen_id   = $match_id;
$chosen_time = $start_ts;
}
}

return $chosen_id;
}

/**
 * Check and log errors when debugging.
 */
function c2p_match_chat_log( $message ) {
if ( C2P_CHAT_DEBUG ) {
error_log( '[C2P Match Chat] ' . $message );
}
}

/**
 * REST permission + nonce + eligibility wrapper.
 */
function c2p_match_chat_validate_request( WP_REST_Request $request ) {
$nonce = $request->get_header( 'x_wp_nonce' );
if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
return new WP_Error( 'c2p_invalid_nonce', __( 'Security check failed.', 'c2p-match-chat' ), [ 'status' => 403 ] );
}

$user_id = get_current_user_id();
if ( ! $user_id ) {
return new WP_Error( 'c2p_not_logged_in', __( 'Login required.', 'c2p-match-chat' ), [ 'status' => 401 ] );
}

$match_id = absint( $request->get_param( 'match_id' ) );
if ( ! $match_id ) {
return new WP_Error( 'c2p_missing_match', __( 'Match is required.', 'c2p-match-chat' ), [ 'status' => 400 ] );
}

$match_post = get_post( $match_id );
if ( ! $match_post || 'match' !== $match_post->post_type ) {
return new WP_Error( 'c2p_invalid_match', __( 'Invalid match.', 'c2p-match-chat' ), [ 'status' => 404 ] );
}

if ( ! c2p_is_match_active( $match_id ) ) {
return new WP_Error( 'c2p_inactive_match', __( 'Match is not active.', 'c2p-match-chat' ), [ 'status' => 403 ] );
}

if ( ! c2p_current_user_can_chat( $match_id ) ) {
return new WP_Error( 'c2p_no_access', __( 'You are not allowed to chat in this match.', 'c2p-match-chat' ), [ 'status' => 403 ] );
}

return true;
}

/**
 * REST: Get messages for a match.
 */
function c2p_match_chat_rest_get_messages( WP_REST_Request $request ) {
$validated = c2p_match_chat_validate_request( $request );
if ( is_wp_error( $validated ) ) {
return $validated;
}

global $wpdb;
$table    = c2p_match_chat_table_name();
$match_id = absint( $request->get_param( 'match_id' ) );
$after    = $request->get_param( 'after' );

$after_id = 0;
$after_ts = 0;
if ( is_numeric( $after ) ) {
$after_num = absint( $after );
// If the value looks like a Unix timestamp (>= ~2033), treat it as created_at filter.
if ( $after_num > 2000000000 ) {
$after_ts = $after_num;
} else {
$after_id = $after_num;
}
}

$where = [ $wpdb->prepare( 'match_id = %d', $match_id ) ];
if ( $after_id ) {
$where[] = $wpdb->prepare( 'id > %d', $after_id );
}
if ( $after_ts ) {
$where[] = $wpdb->prepare( 'UNIX_TIMESTAMP(created_at) > %d', $after_ts );
}
$where_sql = implode( ' AND ', $where );

$sql  = "SELECT id, match_id, tournament_id, sender_id, side, message, UNIX_TIMESTAMP(created_at) AS created_ts FROM {$table} WHERE {$where_sql} ORDER BY id ASC LIMIT 200";
$rows = $wpdb->get_results( $sql );

$messages = [];
foreach ( $rows as $row ) {
$messages[] = [
'id'            => (int) $row->id,
'match_id'      => (int) $row->match_id,
'tournament_id' => (int) $row->tournament_id,
'sender_id'     => (int) $row->sender_id,
'side'          => sanitize_key( $row->side ),
'message'       => wp_kses_post( $row->message ),
'created_at'    => (int) $row->created_ts,
];
}

return rest_ensure_response( [
'messages' => $messages,
'user_id'  => get_current_user_id(),
'side'     => c2p_get_user_side_for_match( $match_id, get_current_user_id() ),
] );
}

/**
 * Basic flood control: 1 message per second per user per match.
 */
function c2p_match_chat_check_rate_limit( $match_id, $user_id ) {
$key  = 'c2p_chat_rate_' . $match_id . '_' . $user_id;
$last = get_transient( $key );
$now  = microtime( true );

if ( $last && ( $now - $last ) < 1 ) {
return false;
}

set_transient( $key, $now, 5 );
return true;
}

/**
 * REST: Post a new message.
 */
function c2p_match_chat_rest_post_message( WP_REST_Request $request ) {
$validated = c2p_match_chat_validate_request( $request );
if ( is_wp_error( $validated ) ) {
return $validated;
}

$match_id = absint( $request->get_param( 'match_id' ) );
$message  = (string) $request->get_param( 'message' );

$message = wp_strip_all_tags( $message );
$message = trim( $message );

if ( '' === $message ) {
return new WP_Error( 'c2p_empty_message', __( 'Message is empty.', 'c2p-match-chat' ), [ 'status' => 400 ] );
}

$max_length = 500;
if ( strlen( $message ) > $max_length ) {
$message = substr( $message, 0, $max_length );
}

$user_id = get_current_user_id();
if ( ! c2p_match_chat_check_rate_limit( $match_id, $user_id ) ) {
return new WP_Error( 'c2p_rate_limited', __( 'You are sending messages too quickly. Please wait a moment.', 'c2p-match-chat' ), [ 'status' => 429 ] );
}

$side          = c2p_get_user_side_for_match( $match_id, $user_id );
$tournament_id = c2p_get_match_tournament_id( $match_id );

global $wpdb;
$table = c2p_match_chat_table_name();

$inserted = $wpdb->insert(
$table,
[
'match_id'      => $match_id,
'tournament_id' => $tournament_id,
'sender_id'     => $user_id,
'side'          => $side,
'message'       => wp_kses_post( $message ),
'created_at'    => current_time( 'mysql' ),
],
[ '%d', '%d', '%d', '%s', '%s', '%s' ]
);

if ( false === $inserted ) {
c2p_match_chat_log( 'DB insert failed: ' . $wpdb->last_error );
return new WP_Error( 'c2p_insert_failed', __( 'Could not save message.', 'c2p-match-chat' ), [ 'status' => 500 ] );
}

$get_request = new WP_REST_Request( 'GET', '/c2p/v1/match-chat/messages' );
$get_request->set_param( 'match_id', $match_id );
$get_request->set_param( 'after', $wpdb->insert_id - 1 );

return c2p_match_chat_rest_get_messages( $get_request );
}

/**
 * Shortcode output handler.
 */
function c2p_match_chat_shortcode() {
if ( ! is_singular( [ 'liga', 'turnier', 'matchmaker' ] ) ) {
return '';
}

global $post;
$tournament_id = $post instanceof WP_Post ? $post->ID : 0;
if ( ! $tournament_id ) {
return '';
}

$match_id = c2p_get_relevant_match_for_tournament( $tournament_id, true );
$active   = true;

if ( ! $match_id ) {
// No active match: try nearest scheduled match to show a soft notice if eligible.
$match_id = c2p_get_relevant_match_for_tournament( $tournament_id, false );
$active   = false;
}

if ( ! $match_id ) {
return '';
}

if ( ! c2p_current_user_can_chat( $match_id ) ) {
return '';
}

if ( ! $active ) {
return '<div class="c2p-chat-notice">' . esc_html__( 'Match chat opens when the match starts.', 'c2p-match-chat' ) . '</div>';
}

// Enqueue assets only when rendering.
c2p_match_chat_enqueue_assets( $match_id, $tournament_id );

ob_start();
?>
<div id="c2p-match-chat" class="c2p-chat-wrapper" aria-live="polite"></div>
<?php
return ob_get_clean();
}

/**
 * Enqueue scripts/styles and pass context to JS.
 */
function c2p_match_chat_enqueue_assets( $match_id, $tournament_id ) {
$handle = 'c2p-match-chat';

wp_register_style( $handle, '', [], C2P_MATCH_CHAT_VERSION );
wp_add_inline_style( $handle, c2p_match_chat_styles() );
wp_enqueue_style( $handle );

wp_register_script( $handle, '', [], C2P_MATCH_CHAT_VERSION, true );
wp_add_inline_script( $handle, c2p_match_chat_script(), 'after' );
wp_enqueue_script( $handle );

$user_id = get_current_user_id();

wp_localize_script( $handle, 'c2pMatchChatData', [
'restUrl'      => esc_url_raw( rest_url( 'c2p/v1/match-chat/messages' ) ),
'nonce'        => wp_create_nonce( 'wp_rest' ),
'matchId'      => $match_id,
'tournamentId' => $tournament_id,
'userId'       => $user_id,
'side'         => c2p_get_user_side_for_match( $match_id, $user_id ),
'pollInterval' => 4000,
'maxLength'    => 500,
'labels'       => [
'title'       => __( 'Match Chat', 'c2p-match-chat' ),
'empty'       => __( 'No messages yet. Say hello!', 'c2p-match-chat' ),
'send'        => __( 'Send', 'c2p-match-chat' ),
'placeholder' => __( 'Type a message', 'c2p-match-chat' ),
'rateLimit'   => __( 'Please slow down between messages.', 'c2p-match-chat' ),
'error'       => __( 'Message failed. Please try again.', 'c2p-match-chat' ),
],
] );
}

/**
 * Styles for the widget.
 */
function c2p_match_chat_styles() {
return '.c2p-chat-wrapper{position:fixed;bottom:20px;right:20px;z-index:9999;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}'
. '.c2p-chat-toggle{background:#111;color:#fff;border:none;border-radius:24px;padding:10px 16px;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,0.2);display:flex;align-items:center;gap:8px;}'
. '.c2p-chat-panel{display:none;position:absolute;bottom:60px;right:0;width:320px;max-width:90vw;background:#fff;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.18);overflow:hidden;}'
. '.c2p-chat-header{background:#111;color:#fff;padding:12px 14px;font-weight:600;display:flex;justify-content:space-between;align-items:center;}'
. '.c2p-chat-body{max-height:420px;overflow-y:auto;padding:12px;background:#f6f7fb;display:flex;flex-direction:column;gap:8px;}'
. '.c2p-chat-footer{padding:10px;border-top:1px solid #e2e5ec;background:#fff;display:flex;gap:8px;align-items:flex-end;}'
. '.c2p-chat-input{flex:1;border:1px solid #d6d8de;border-radius:10px;padding:8px 10px;font-size:14px;}'
. '.c2p-chat-send{background:#111;color:#fff;border:none;border-radius:10px;padding:8px 12px;font-weight:600;cursor:pointer;}'
. '.c2p-chat-message{max-width:85%;padding:10px 12px;border-radius:12px;line-height:1.35;font-size:14px;white-space:pre-wrap;word-break:break-word;}'
. '.c2p-chat-row{display:flex;gap:8px;align-items:flex-end;}'
. '.c2p-chat-row.you{justify-content:flex-end;}'
. '.c2p-chat-row.you .c2p-chat-message{background:#111;color:#fff;border-bottom-right-radius:4px;}'
. '.c2p-chat-row.other .c2p-chat-message{background:#fff;color:#111;border:1px solid #e2e5ec;border-bottom-left-radius:4px;}'
. '.c2p-chat-empty{color:#6b7280;font-size:13px;text-align:center;margin:12px auto;}'
. '.c2p-chat-notice{padding:12px 14px;background:#f6f7fb;border:1px solid #e2e5ec;border-radius:10px;max-width:480px;margin:0 auto;}'
. '@media(max-width:640px){.c2p-chat-wrapper{right:12px;bottom:12px;}.c2p-chat-panel{width:280px;}}';
}

/**
 * Front-end script (vanilla JS).
 */
function c2p_match_chat_script() {
return "(function(){\n" .
"const data = window.c2pMatchChatData;\n" .
"if(!data){return;}\n" .
"const wrapper=document.getElementById('c2p-match-chat');\n" .
"if(!wrapper){return;}\n" .
"let open=false;let lastId=0;let pollTimer=null;\n" .
"const toggleBtn=document.createElement('button');toggleBtn.className='c2p-chat-toggle';toggleBtn.setAttribute('type','button');toggleBtn.innerHTML='<span>' + data.labels.title + '</span>';\n" .
"const panel=document.createElement('div');panel.className='c2p-chat-panel';\n" .
"const header=document.createElement('div');header.className='c2p-chat-header';header.innerHTML='<span>'+data.labels.title+'</span>';\n" .
"const closeBtn=document.createElement('button');closeBtn.setAttribute('type','button');closeBtn.style.background='transparent';closeBtn.style.color='#fff';closeBtn.style.border='none';closeBtn.style.fontSize='16px';closeBtn.style.cursor='pointer';closeBtn.textContent='×';header.appendChild(closeBtn);\n" .
"const body=document.createElement('div');body.className='c2p-chat-body';\n" .
"const footer=document.createElement('div');footer.className='c2p-chat-footer';\n" .
"const input=document.createElement('textarea');input.className='c2p-chat-input';input.setAttribute('rows','2');input.setAttribute('maxlength',data.maxLength);input.setAttribute('placeholder',data.labels.placeholder);\n" .
"const sendBtn=document.createElement('button');sendBtn.className='c2p-chat-send';sendBtn.textContent=data.labels.send;sendBtn.type='button';\n" .
"footer.appendChild(input);footer.appendChild(sendBtn);\n" .
"panel.appendChild(header);panel.appendChild(body);panel.appendChild(footer);\n" .
"wrapper.appendChild(toggleBtn);wrapper.appendChild(panel);\n" .
"function renderMessages(list){\n" .
"if(!Array.isArray(list)){return;}\n" .
"const shouldScroll = body.scrollTop + body.clientHeight >= body.scrollHeight - 40;\n" .
"if(!list.length){body.innerHTML='';const empty=document.createElement('div');empty.className='c2p-chat-empty';empty.textContent=data.labels.empty;body.appendChild(empty);return;}\n" .
"list.forEach(function(msg){\n" .
"lastId=Math.max(lastId,msg.id);\n" .
"const row=document.createElement('div');const you=(msg.sender_id===data.userId);row.className='c2p-chat-row '+(you?'you':'other');\n" .
"const bubble=document.createElement('div');bubble.className='c2p-chat-message';bubble.textContent=msg.message;row.appendChild(bubble);body.appendChild(row);});\n" .
"if(shouldScroll){body.scrollTop=body.scrollHeight;}\n" .
"}\n" .
"function fetchMessages(){\n" .
"const url=new URL(data.restUrl);url.searchParams.set('match_id',data.matchId);if(lastId){url.searchParams.set('after',lastId);}\n" .
"fetch(url.toString(),{credentials:'same-origin',headers:{'X-WP-Nonce':data.nonce}}).then(function(r){if(!r.ok){throw new Error('failed');}return r.json();}).then(function(res){if(res && res.messages){renderMessages(res.messages);}}).catch(function(err){console.error('Chat fetch failed',err);});\n" .
"}\n" .
"function sendMessage(){\n" .
"const text=input.value.trim();if(!text){return;}\n" .
"const payload={match_id:data.matchId,message:text};\n" .
"sendBtn.disabled=true;\n" .
"fetch(data.restUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':data.nonce},body:JSON.stringify(payload)}).then(function(r){if(!r.ok){return r.json().then(function(j){throw j;});}return r.json();}).then(function(res){input.value='';if(res && res.messages){renderMessages(res.messages);}else{fetchMessages();}}).catch(function(){alert(data.labels.error);}).finally(function(){sendBtn.disabled=false;});\n" .
"}\n" .
"function togglePanel(){open=!open;panel.style.display=open?'block':'none';if(open){body.innerHTML='';fetchMessages();if(pollTimer){clearInterval(pollTimer);}pollTimer=setInterval(fetchMessages,data.pollInterval);}else{if(pollTimer){clearInterval(pollTimer);pollTimer=null;}}}\n" .
"toggleBtn.addEventListener('click',togglePanel);closeBtn.addEventListener('click',togglePanel);sendBtn.addEventListener('click',sendMessage);input.addEventListener('keydown',function(e){if((e.metaKey||e.ctrlKey)&&e.key==='Enter'){sendMessage();}});\n" .
"})();";
}

/**
 * External deletion helper; can be called from Fluent Forms hooks.
 */
function c2p_match_chat_delete_messages( $match_id ) {
global $wpdb;
$table = c2p_match_chat_table_name();
$wpdb->delete( $table, [ 'match_id' => absint( $match_id ) ], [ '%d' ] );
}
