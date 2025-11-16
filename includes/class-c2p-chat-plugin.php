<?php
/**
 * Core plugin functionality.
 *
 * @package C2P_Chat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
class C2P_Chat_Plugin {
    /**
     * Name of the custom database table without prefix.
     */
    const TABLE = 'c2p_chat_messages';

    /**
     * Option used to store the db version.
     */
    const OPTION_DB_VERSION = 'c2p_chat_db_version';

    /**
     * Holds the singleton instance.
     *
     * @var C2P_Chat_Plugin|null
     */
    private static $instance = null;

    /**
     * Cached table name with prefix.
     *
     * @var string
     */
    private $table_name;

    /**
     * Returns the singleton instance.
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Ctor.
     */
    private function __construct() {
        global $wpdb;

        $this->table_name = $wpdb->prefix . self::TABLE;

        register_activation_hook( C2P_CHAT_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( C2P_CHAT_FILE, array( $this, 'deactivate' ) );

        add_shortcode( 'c2p_competition_chat', array( $this, 'render_competition_chat' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
        add_action( 'wp_ajax_c2p_chat_send_message', array( $this, 'handle_send_message' ) );
        add_action( 'wp_ajax_c2p_chat_fetch_messages', array( $this, 'handle_fetch_messages' ) );
        add_action( 'c2p_chat_cleanup_event', array( $this, 'purge_expired_messages' ) );
        add_action( 'updated_post_meta', array( $this, 'maybe_purge_messages_on_score_reported' ), 10, 4 );
        add_action( 'added_post_meta', array( $this, 'maybe_purge_messages_on_score_reported' ), 10, 4 );
    }

    /**
     * Plugin activation callback.
     */
    public function activate() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = sprintf(
            'CREATE TABLE %1$s (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                competition_id bigint(20) unsigned NOT NULL,
                match_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL,
                message text NOT NULL,
                created_at datetime NOT NULL,
                expires_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY match_id (match_id),
                KEY competition_id (competition_id)
            ) %2$s;'
            , $this->table_name
            , $charset_collate
        );

        dbDelta( $sql );

        update_option( self::OPTION_DB_VERSION, C2P_CHAT_VERSION );

        if ( ! wp_next_scheduled( 'c2p_chat_cleanup_event' ) ) {
            wp_schedule_event( time(), 'hourly', 'c2p_chat_cleanup_event' );
        }
    }

    /**
     * Plugin deactivation callback.
     */
    public function deactivate() {
        wp_clear_scheduled_hook( 'c2p_chat_cleanup_event' );
    }

    /**
     * Registers assets when the shortcode is present.
     */
    public function maybe_enqueue_assets() {
        if ( ! is_singular() ) {
            return;
        }

        $post = get_post();
        if ( ! $post ) {
            return;
        }

        if ( has_shortcode( $post->post_content, 'c2p_competition_chat' ) ) {
            $this->enqueue_assets();
        }
    }

    /**
     * Enqueues frontend assets.
     */
    private function enqueue_assets() {
        wp_enqueue_style(
            'c2p-chat',
            C2P_CHAT_URL . 'assets/css/c2p-chat.css',
            array(),
            C2P_CHAT_VERSION
        );

        wp_enqueue_script(
            'c2p-chat',
            C2P_CHAT_URL . 'assets/js/c2p-chat.js',
            array( 'jquery' ),
            C2P_CHAT_VERSION,
            true
        );

        wp_localize_script(
            'c2p-chat',
            'c2pChatSettings',
            array(
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'c2p-chat' ),
                'pollInterval' => apply_filters( 'c2p_chat_poll_interval', 5000 ),
            )
        );
    }

    /**
     * Renders the competition chat shortcode.
     *
     * @param array  $atts Shortcode atts.
     * @param string $content Content.
     */
    public function render_competition_chat( $atts, $content = '' ) {
        $atts = shortcode_atts(
            array(
                'competition_id' => 0,
                'title'          => __( 'Match chat', 'c2p-chat' ),
            ),
            $atts,
            'c2p_competition_chat'
        );

        $competition_id = absint( $atts['competition_id'] );
        if ( ! $competition_id ) {
            $competition_id = get_the_ID();
        }

        if ( ! $competition_id ) {
            return '';
        }

        $current_user_id = get_current_user_id();
        $matches         = $this->get_active_matches_for_competition( $competition_id, $current_user_id );

        if ( empty( $matches ) ) {
            return sprintf(
                '<div class="c2p-chat c2p-chat--empty">%s</div>',
                esc_html__( 'There is no active match you can chat in right now.', 'c2p-chat' )
            );
        }

        $selected_match = $matches[0];

        ob_start();
        ?>
        <div class="c2p-chat" data-competition-id="<?php echo esc_attr( $competition_id ); ?>">
            <div class="c2p-chat__header">
                <strong><?php echo esc_html( $atts['title'] ); ?></strong>
                <?php if ( count( $matches ) > 1 ) : ?>
                    <label class="c2p-chat__match-label" for="c2p-chat-match-selector">
                        <?php esc_html_e( 'Match', 'c2p-chat' ); ?>
                    </label>
                    <select id="c2p-chat-match-selector" class="c2p-chat__match-selector">
                        <?php foreach ( $matches as $match ) : ?>
                            <option value="<?php echo esc_attr( $match->ID ); ?>">
                                <?php echo esc_html( get_the_title( $match ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <span class="c2p-chat__match-name"><?php echo esc_html( get_the_title( $selected_match ) ); ?></span>
                <?php endif; ?>
            </div>
            <div class="c2p-chat__messages" data-match-id="<?php echo esc_attr( $selected_match->ID ); ?>" aria-live="polite"></div>
            <form class="c2p-chat__form" autocomplete="off">
                <label for="c2p-chat-input" class="screen-reader-text"><?php esc_html_e( 'Send a message', 'c2p-chat' ); ?></label>
                <input type="text" id="c2p-chat-input" class="c2p-chat__input" placeholder="<?php esc_attr_e( 'Write a message…', 'c2p-chat' ); ?>" />
                <button type="submit" class="c2p-chat__button"><?php esc_html_e( 'Send', 'c2p-chat' ); ?></button>
            </form>
            <p class="c2p-chat__hint">
                <?php esc_html_e( 'Messages are automatically removed once the match result is submitted.', 'c2p-chat' ); ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Handles storing chat messages.
     */
    public function handle_send_message() {
        check_ajax_referer( 'c2p-chat', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You need to be logged in to chat.', 'c2p-chat' ) ), 403 );
        }

        $match_id       = isset( $_POST['match_id'] ) ? absint( $_POST['match_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $message        = isset( $_POST['message'] ) ? wp_unslash( (string) $_POST['message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if ( ! $match_id || ! $competition_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid match.', 'c2p-chat' ) ), 400 );
        }

        $message = trim( wp_strip_all_tags( $message ) );

        if ( '' === $message ) {
            wp_send_json_error( array( 'message' => __( 'Please type a message.', 'c2p-chat' ) ), 400 );
        }

        if ( ! $this->is_match_currently_active( $match_id ) ) {
            wp_send_json_error( array( 'message' => __( 'This match is no longer active.', 'c2p-chat' ) ), 400 );
        }

        $current_user_id = get_current_user_id();

        if ( ! $this->is_user_part_of_match( $current_user_id, $match_id ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not a participant in this match.', 'c2p-chat' ) ), 403 );
        }

        global $wpdb;

        $ttl         = apply_filters( 'c2p_chat_message_ttl', 6 * HOUR_IN_SECONDS, $match_id );
        $created_at  = gmdate( 'Y-m-d H:i:s' );
        $expires_at  = gmdate( 'Y-m-d H:i:s', time() + $ttl );
        $inserted    = $wpdb->insert(
            $this->table_name,
            array(
                'competition_id' => $competition_id,
                'match_id'       => $match_id,
                'user_id'        => $current_user_id,
                'message'        => $message,
                'created_at'     => $created_at,
                'expires_at'     => $expires_at,
            ),
            array( '%d', '%d', '%d', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => __( 'Message could not be saved.', 'c2p-chat' ) ), 500 );
        }

        $message_data = array(
            'id'         => $wpdb->insert_id,
            'user_name'  => $this->get_user_display_name( $current_user_id ),
            'message'    => $message,
            'created_at' => $created_at,
        );

        wp_send_json_success( array( 'message' => $message_data ) );
    }

    /**
     * Handles fetching messages.
     */
    public function handle_fetch_messages() {
        check_ajax_referer( 'c2p-chat', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You need to be logged in to chat.', 'c2p-chat' ) ), 403 );
        }

        $match_id       = isset( $_GET['match_id'] ) ? absint( $_GET['match_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $after_id       = isset( $_GET['after_id'] ) ? absint( $_GET['after_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

        if ( ! $match_id || ! $competition_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid match.', 'c2p-chat' ) ), 400 );
        }

        if ( ! $this->is_match_currently_active( $match_id ) ) {
            $this->purge_match_messages( $match_id );
            wp_send_json_error( array( 'message' => __( 'This match is no longer active.', 'c2p-chat' ) ), 400 );
        }

        $current_user_id = get_current_user_id();

        if ( ! $this->is_user_part_of_match( $current_user_id, $match_id ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not a participant in this match.', 'c2p-chat' ) ), 403 );
        }

        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_id, message, created_at FROM {$this->table_name} WHERE match_id = %d AND competition_id = %d AND id > %d ORDER BY id ASC",
                $match_id,
                $competition_id,
                $after_id
            )
        );

        $messages = array();
        foreach ( $results as $row ) {
            $messages[] = array(
                'id'         => (int) $row->id,
                'user_name'  => $this->get_user_display_name( (int) $row->user_id ),
                'message'    => $row->message,
                'created_at' => $row->created_at,
            );
        }

        wp_send_json_success( array( 'messages' => $messages ) );
    }

    /**
     * Returns the display name for a user id.
     */
    private function get_user_display_name( $user_id ) {
        $user = get_user_by( 'id', $user_id );

        return $user ? $user->display_name : __( 'Unknown player', 'c2p-chat' );
    }

    /**
     * Returns active matches for a competition.
     *
     * @param int $competition_id Competition id.
     * @param int $current_user_id Current user id.
     *
     * @return WP_Post[]
     */
    private function get_active_matches_for_competition( $competition_id, $current_user_id ) {
        $matches = get_posts(
            array(
                'post_type'      => 'match',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'meta_query'     => array(
                    array(
                        'key'   => '_c2p_competition_id',
                        'value' => $competition_id,
                    ),
                ),
            )
        );

        $matches = apply_filters( 'c2p_chat_active_matches', $matches, $competition_id );

        $accessible = array();

        foreach ( $matches as $match ) {
            if ( ! $this->is_match_currently_active( $match->ID ) ) {
                continue;
            }

            if ( ! $current_user_id || ! $this->is_user_part_of_match( $current_user_id, $match->ID ) ) {
                continue;
            }

            $accessible[] = $match;
        }

        return $accessible;
    }

    /**
     * Determines whether a match is in-progress.
     */
    private function is_match_currently_active( $match_id ) {
        $is_active = (bool) get_post_meta( $match_id, '_c2p_match_started', true ) && ! (bool) get_post_meta( $match_id, '_c2p_match_score_reported', true );

        return (bool) apply_filters( 'c2p_chat_is_match_active', $is_active, $match_id );
    }

    /**
     * Checks whether a user belongs to a match.
     */
    private function is_user_part_of_match( $user_id, $match_id ) {
        if ( ! $user_id ) {
            return false;
        }

        $participants = $this->get_match_participants( $match_id );

        return in_array( (int) $user_id, $participants, true );
    }

    /**
     * Returns user ids for a match.
     */
    private function get_match_participants( $match_id ) {
        $participants = array();

        $modus = (int) get_post_meta( $match_id, '_c2p_match_modus', true );

        if ( ! $modus ) {
            $competition_id = (int) get_post_meta( $match_id, '_c2p_competition_id', true );
            if ( $competition_id ) {
                $modus = (int) get_post_meta( $competition_id, '_c2p_competition_modus', true );
            }
        }

        if ( $modus > 1 ) {
            $team_ids = (array) get_post_meta( $match_id, '_c2p_match_teams', true );
            foreach ( $team_ids as $team_id ) {
                $team_id = absint( $team_id );
                if ( ! $team_id ) {
                    continue;
                }
                $team_members = (array) get_post_meta( $team_id, '_c2p_team_members', true );
                foreach ( $team_members as $member_id ) {
                    $member_id = absint( $member_id );
                    if ( $member_id ) {
                        $participants[] = $member_id;
                    }
                }
            }
        } else {
            $players = (array) get_post_meta( $match_id, '_c2p_match_players', true );
            foreach ( $players as $player_id ) {
                $player_id = absint( $player_id );
                if ( $player_id ) {
                    $participants[] = $player_id;
                }
            }
        }

        $participants = array_values( array_unique( array_filter( $participants ) ) );

        return (array) apply_filters( 'c2p_chat_match_participants', $participants, $match_id );
    }

    /**
     * Removes expired messages from the table.
     */
    public function purge_expired_messages() {
        global $wpdb;

        $now = gmdate( 'Y-m-d H:i:s' );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE expires_at <= %s",
                $now
            )
        );
    }

    /**
     * Removes all messages for a specific match.
     */
    private function purge_match_messages( $match_id ) {
        global $wpdb;

        $wpdb->delete( $this->table_name, array( 'match_id' => $match_id ), array( '%d' ) );
    }

    /**
     * Deletes chat messages once a score was reported.
     *
     * @param int    $meta_id    Meta id.
     * @param int    $object_id  Post id.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Meta value.
     */
    public function maybe_purge_messages_on_score_reported( $meta_id, $object_id, $meta_key, $meta_value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        if ( '_c2p_match_score_reported' !== $meta_key ) {
            return;
        }

        if ( empty( $meta_value ) ) {
            return;
        }

        if ( 'match' !== get_post_type( $object_id ) ) {
            return;
        }

        $this->purge_match_messages( (int) $object_id );
    }
}
