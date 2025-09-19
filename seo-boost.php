<?php
/**
 * Plugin Name: Tutor LMS Auto SEO Booster
 * Description: Auto SEO for Tutor LMS courses/lessons + lightweight on-site analytics & improved admin analytics table.
 * Version: 1.3
 * Author: Peace M. (Zyron Tech)
 * Text Domain: tutor-seo-booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tutor_SEO_Booster {
    private static $instance = null;
    private $option_key = 'tutor_seo_booster_options';
    private $db_table;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->db_table = $wpdb->prefix . 'tutor_seo_stats';

        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Frontend hooks
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_filter( 'pre_get_document_title', [ $this, 'maybe_filter_title' ], 10, 1 );
        add_action( 'wp_head', [ $this, 'maybe_output_meta_and_og' ], 1 );
        add_action( 'wp_head', [ $this, 'maybe_output_json_ld' ], 100 );
        add_filter( 'wp_sitemaps_post_types', [ $this, 'add_to_sitemaps' ] );

        // Publish hook to ping search engines
        add_action( 'transition_post_status', [ $this, 'maybe_ping_search_engines' ], 10, 3 );

        // AJAX endpoints for analytics
        add_action( 'wp_ajax_tutor_seo_track', [ $this, 'ajax_track' ] );
        add_action( 'wp_ajax_nopriv_tutor_seo_track', [ $this, 'ajax_track' ] );

        // Shortcode for frontend analytics view
        add_shortcode( 'tutor_seo_analytics', [ $this, 'shortcode_analytics' ] );

        // Admin page content
        add_action( 'admin_post_tutor_seo_reset_stats', [ $this, 'handle_reset_stats' ] );
    }

    public function activate() {
        $this->create_table();
        $this->set_default_options();
    }

    private function set_default_options() {
        $defaults = [
            'enabled' => 1,
            'post_types' => $this->detect_post_types(),
            'auto_meta' => 1,
            'jsonld' => 1,
            'opengraph' => 1,
            'canonical' => 1,
            'ping_search_engines' => 1,
            'analytics' => 1,
            'sitemap' => 1,
        ];
        add_option( $this->option_key, $defaults );
    }

    private function detect_post_types() {
        // Common Tutor LMS post types: try to detect what the site has
        $candidates = ['courses','course','tutor_course','tutor_lesson','lesson','lessons'];
        $found = [];
        foreach ( $candidates as $pt ) {
            if ( post_type_exists( $pt ) ) {
                $found[] = $pt;
            }
        }
        // fallback to 'post' if nothing detected
        if ( empty( $found ) ) {
            $found = ['post'];
        }
        return $found;
    }

    private function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $this->db_table;

        // Ensure unique constraint on post_id+stat_date so ON DUPLICATE KEY UPDATE works
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) NOT NULL,
            stat_date DATE NOT NULL,
            views BIGINT(20) NOT NULL DEFAULT 0,
            total_time BIGINT(20) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY post_date_unique (post_id, stat_date),
            KEY post_date (post_id, stat_date)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function admin_menu() {
        add_menu_page(
            'Tutor SEO',
            'Tutor SEO',
            'manage_options',
            'tutor-seo-booster',
            [ $this, 'admin_page' ],
            'dashicons-chart-area',
            56
        );
        add_submenu_page( 'tutor-seo-booster', 'Settings', 'Settings', 'manage_options', 'tutor-seo-booster-settings', [ $this, 'admin_page' ] );
    }

    public function register_settings() {
        register_setting( 'tutor_seo_group', $this->option_key );
    }

    private function get_opts() {
        $opts = get_option( $this->option_key, [] );
        if ( ! $opts ) {
            $this->set_default_options();
            $opts = get_option( $this->option_key );
        }
        return $opts;
    }

    public function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied' );
        }

        $opts = $this->get_opts();
        ?>
        <div class="wrap">
            <h1>Tutor SEO Booster</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'tutor_seo_group' ); ?>
                <?php do_settings_sections( 'tutor_seo_group' ); ?>

                <table class="form-table">
                    <tr>
                        <th>Enable plugin</th>
                        <td><input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[enabled]" value="1" <?php checked( 1, $opts['enabled'] ?? 0 ); ?> /></td>
                    </tr>

                    <tr>
                        <th>Post types to target</th>
                        <td>
                            <?php
                            // show checkboxes for currently registered post types
                            $all = get_post_types( ['public' => true], 'objects' );
                            $selected = $opts['post_types'] ?? [];
                            foreach ( $all as $pt ) {
                                $checked = in_array( $pt->name, (array) $selected ) ? 'checked' : '';
                                echo "<label style='display:inline-block;margin-right:12px'><input type='checkbox' name='{$this->option_key}[post_types][]' value='".esc_attr($pt->name)."' $checked> ".esc_html($pt->labels->singular_name)." (".esc_html($pt->name).")</label>";
                            }
                            ?>
                            <p class="description">Select the post types that should receive SEO auto-tags & analytics.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Auto meta title & description</th>
                        <td><input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[auto_meta]" value="1" <?php checked( 1, $opts['auto_meta'] ?? 0 ); ?> /></td>
                    </tr>

                    <tr>
                        <th>Course JSON-LD (schema.org)</th>
                        <td><input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[jsonld]" value="1" <?php checked( 1, $opts['jsonld'] ?? 0 ); ?> /></td>
                    </tr>

                    <tr>
                        <th>Open Graph / Twitter tags</th>
                        <td><input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[opengraph]" value="1" <?php checked( 1, $opts['opengraph'] ?? 0 ); ?> /></td>
                    </tr>

                    <tr>
                        <th>Add to WP sitemap</th>
                        <td><input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[sitemap]" value="1" <?php checked( 1, $opts['sitemap'] ?? 1 ); ?> /></td>
                    </tr>

                    <tr>
                        <th>Auto-ping search engines on publish</th>
                        <td><input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[ping_search_engines]" value="1" <?php checked( 1, $opts['ping_search_engines'] ?? 0 ); ?> /></td>
                    </tr>

                    <tr>
                        <th>On-site analytics</th>
                        <td><input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[analytics]" value="1" <?php checked( 1, $opts['analytics'] ?? 1 ); ?> /></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2>Simple Analytics (Admin)</h2>
            <?php $this->render_admin_stats(); ?>

            <h2>Shortcode</h2>
            <p>Use <code>[tutor_seo_analytics]</code> to render a simple analytics table on any page (admins see all data).</p>
        </div>
        <?php
    }

    /**
     * Renders an improved admin stats UI using WP_List_Table.
     * Supports: search, sorting, pagination, filter by post type, top N filter.
     */
    private function render_admin_stats() {
        // include WP_List_Table
        if ( ! class_exists( 'WP_List_Table' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        // handle filters (GET)
        $post_type_filter = isset( $_GET['post_type_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type_filter'] ) ) : '';
        $top_filter = isset( $_GET['top_filter'] ) ? intval( $_GET['top_filter'] ) : 0; // 0 = all
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

        $opts = $this->get_opts();
        $configured_post_types = (array) ( $opts['post_types'] ?? ['post'] );

        // Decide which post types to query
        if ( $post_type_filter && $post_type_filter !== 'all' ) {
            $query_post_types = [ $post_type_filter ];
        } else {
            $query_post_types = $configured_post_types;
        }

        // Fetch posts
        $args = [
            'post_type'      => $query_post_types,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ];
        $posts = get_posts( $args );

        // Build data array
        $data = [];
        foreach ( $posts as $p ) {
            $views = intval( get_post_meta( $p->ID, '_tutor_seo_view_count', true ) );
            $total_time = intval( get_post_meta( $p->ID, '_tutor_seo_total_time', true ) );
            $avg = $views ? round( $total_time / $views, 1 ) : 0;
            $last = get_post_meta( $p->ID, '_tutor_seo_last_view', true );
            $data[] = [
                'ID'        => $p->ID,
                'title'     => get_the_title( $p ),
                'type'      => $p->post_type,
                'views'     => $views,
                'avg_time'  => $avg,
                'last_view' => $last,
                'permalink' => get_permalink( $p->ID ),
                'edit_link' => get_edit_post_link( $p->ID ),
            ];
        }

        // If search provided, filter locally by title
        if ( $search ) {
            $s_lower = mb_strtolower( $search );
            $data = array_filter( $data, function( $row ) use ( $s_lower ) {
                return ( mb_stripos( $row['title'], $s_lower ) !== false );
            } );
            // reindex
            $data = array_values( $data );
        }

        // Apply Top N filter before feeding List Table
        if ( $top_filter > 0 ) {
            // sort by views desc, then slice
            usort( $data, function( $a, $b ) {
                return $b['views'] <=> $a['views'];
            } );
            $data = array_slice( $data, 0, $top_filter );
        }

        // Instantiate and render the list table
        $list_table = new Tutor_SEO_List_Table( $data );
        // Search form & filters
        echo '<form method="get" style="margin-bottom:12px;">';
        // Keep the page parameter so WP doesn't redirect
        $current_page = isset( $_GET['page'] ) ? esc_attr( $_GET['page'] ) : 'tutor-seo-booster-settings';
        echo '<input type="hidden" name="page" value="' . esc_attr( $current_page ) . '">';
        echo '<label style="margin-right:8px"><strong>Filter by type:</strong> </label>';
        // Post type dropdown
        $all_public = get_post_types( ['public' => true], 'objects' );
        echo '<select name="post_type_filter" style="margin-right:8px;">';
        echo '<option value="all">' . esc_html__( 'All', 'tutor-seo-booster' ) . '</option>';
        foreach ( $all_public as $pt_obj ) {
            $sel = ( $post_type_filter === $pt_obj->name ) ? 'selected' : '';
            echo '<option value="' . esc_attr( $pt_obj->name ) . '" ' . $sel . '>' . esc_html( $pt_obj->labels->singular_name ) . ' (' . esc_html( $pt_obj->name ) . ')</option>';
        }
        echo '</select>';

        // Top filter
        echo '<label style="margin-right:8px"><strong>Top:</strong> </label>';
        echo '<select name="top_filter" style="margin-right:8px;">';
        $top_options = [ 0 => 'All', 10 => 'Top 10', 25 => 'Top 25', 50 => 'Top 50' ];
        foreach ( $top_options as $k => $label ) {
            $sel = ( intval( $top_filter ) === intval( $k ) ) ? 'selected' : '';
            echo '<option value="' . esc_attr( $k ) . '" ' . $sel . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';

        // Submit
        echo '<input type="submit" class="button" value="' . esc_attr__( 'Apply', 'tutor-seo-booster' ) . '">';
        // Reset link
        $reset_url = remove_query_arg( array( 'post_type_filter', 'top_filter', 's', 'orderby', 'order', 'paged' ) );
        echo ' <a href="' . esc_url( $reset_url ) . '" class="button" style="margin-left:8px;">Reset</a>';
        echo '</form>';

        // Display list table inside GET form (so search and sorting work)
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr( $current_page ) . '">';
        $list_table->prepare_items();
        // show the search box
        $list_table->search_box( 'Search Posts', 'tutor_seo_search' );
        $list_table->display();
        echo '</form>';

        // Reset link (also show reset button)
        $reset_action = wp_nonce_url( admin_url('admin-post.php?action=tutor_seo_reset_stats'), 'tutor_seo_reset' );
        echo '<p style="margin-top:12px;"><a href="' . esc_url( $reset_action ) . '" class="button button-secondary">Reset All Stats</a></p>';
    }

    public function handle_reset_stats() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'tutor_seo_reset' ) ) {
            wp_die('Not allowed');
        }
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$this->db_table}" );
        // remove post metas
        $opts = $this->get_opts();
        $post_types = (array) ($opts['post_types'] ?? ['post']);
        $args = ['post_type' => $post_types, 'posts_per_page' => -1, 'post_status' => 'any'];
        $posts = get_posts( $args );
        foreach ( $posts as $p ) {
            delete_post_meta( $p->ID, '_tutor_seo_view_count' );
            delete_post_meta( $p->ID, '_tutor_seo_total_time' );
            delete_post_meta( $p->ID, '_tutor_seo_last_view' );
        }
        wp_redirect( admin_url('admin.php?page=tutor-seo-booster-settings') );
        exit;
    }

    public function enqueue_scripts() {
        $opts = $this->get_opts();
        if ( empty( $opts['enabled'] ) ) return;

        if ( is_singular() && $opts['analytics'] ) {
            global $post;
            if ( ! $post ) return;
            $post_types = (array) ($opts['post_types'] ?? []);
            if ( ! in_array( $post->post_type, $post_types ) ) return;

            wp_register_script( 'tutor-seo-tracker', plugins_url( 'tracker.js', __FILE__ ), [], '1.0', true );

            // we'll inline a small script if file not present
            $data = [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'tutor_seo_nonce' ),
                'post_id' => $post->ID,
                'enabled' => 1,
            ];
            wp_enqueue_script( 'tutor-seo-tracker' );
            wp_localize_script( 'tutor-seo-tracker', 'tutor_seo_tracker', $data );

            // inline JS fallback (so plugin works without separate file)
            $inline = <<<JS
(function(){
    if (!window.tutorSEOTrackerInitialized) {
        window.tutorSEOTrackerInitialized = true;
        var postId = tutor_seo_tracker.post_id;
        var nonce = tutor_seo_tracker.nonce;
        var ajaxUrl = tutor_seo_tracker.ajax_url;
        var start = Date.now();

        // notify server of page view
        function sendEvent(event, data) {
            data = data || {};
            data.action = 'tutor_seo_track';
            data.event = event;
            data.post_id = postId;
            data.nonce = nonce;
            // try sendBeacon for unload-safe payloads
            try {
                if (event === 'engagement_time' && navigator.sendBeacon) {
                    var payload = JSON.stringify(data);
                    navigator.sendBeacon(ajaxUrl, payload);
                    return;
                }
            } catch (e) {}
            // fallback to fetch
            fetch(ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                body: Object.keys(data).map(function(k){return encodeURIComponent(k)+'='+encodeURIComponent(data[k]);}).join('&'),
                keepalive: true
            }).catch(function(){});
        }

        // fire page_view on load
        sendEvent('page_view', {});

        // on unload, send engagement_time (seconds)
        function sendEngagement() {
            var diff = Math.round((Date.now() - start) / 1000);
            sendEvent('engagement_time', { seconds: diff });
        }

        window.addEventListener('beforeunload', sendEngagement);
        document.addEventListener('visibilitychange', function(){
            if (document.visibilityState === 'hidden') {
                sendEngagement();
            }
        });
    }
})();
JS;
            wp_add_inline_script( 'tutor-seo-tracker', $inline );
        } // end if singular + analytics
    }

    public function maybe_filter_title( $title ) {
        $opts = $this->get_opts();
        if ( empty( $opts['enabled'] ) || empty( $opts['auto_meta'] ) ) return $title;

        if ( is_singular() ) {
            global $post;
            if ( $post && in_array( $post->post_type, (array)$opts['post_types'] ) ) {
                // If Yoast or Rank Math set a title, don't override
                if ( get_post_meta( $post->ID, '_yoast_wpseo_title', true ) ) return $title;
                if ( get_post_meta( $post->ID, 'rank_math_title', true ) ) return $title;

                $site = get_bloginfo( 'name' );
                $custom = get_the_title( $post->ID ) . ' | ' . $site;
                return $custom;
            }
        }
        return $title;
    }

    public function maybe_output_meta_and_og() {
        $opts = $this->get_opts();
        if ( empty( $opts['enabled'] ) || empty( $opts['auto_meta'] ) ) return;

        if ( is_singular() ) {
            global $post;
            if ( ! $post ) return;
            if ( ! in_array( $post->post_type, (array)$opts['post_types'] ) ) return;

            // don't override if Yoast/RankMath meta exists
            if ( get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true ) ) return;
            if ( get_post_meta( $post->ID, 'rank_math_description', true ) ) return;

            $desc = wp_trim_words( strip_tags( $post->post_content ), 30 );
            $desc = esc_attr( $desc );
            echo "<meta name='description' content='{$desc}'>\n";

            // Open Graph / Twitter (if enabled)
            if ( ! empty( $opts['opengraph'] ) ) {
                $title = esc_attr( get_the_title( $post->ID ) );
                $url = esc_url( get_permalink( $post->ID ) );
                $img = '';
                if ( has_post_thumbnail( $post->ID ) ) {
                    $img = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'full' );
                    $img = $img ? esc_url( $img[0] ) : '';
                }
                // fallback to site icon
                if ( ! $img && function_exists('get_site_icon_url') ) {
                    $img = esc_url( get_site_icon_url() );
                }

                echo "<meta property='og:type' content='article' />\n";
                echo "<meta property='og:title' content='{$title}' />\n";
                echo "<meta property='og:description' content='{$desc}' />\n";
                echo "<meta property='og:url' content='{$url}' />\n";
                if ( $img ) echo "<meta property='og:image' content='{$img}' />\n";

                // Twitter
                echo "<meta name='twitter:card' content='summary_large_image' />\n";
                echo "<meta name='twitter:title' content='{$title}' />\n";
                echo "<meta name='twitter:description' content='{$desc}' />\n";
                if ( $img ) echo "<meta name='twitter:image' content='{$img}' />\n";
            }

            // canonical (WordPress usually emits one already)
            if ( ! empty( $opts['canonical'] ) ) {
                $canonical = esc_url( get_permalink( $post->ID ) );
                echo "<link rel='canonical' href='{$canonical}' />\n";
            }
        }
    }

    public function maybe_output_json_ld() {
        $opts = $this->get_opts();
        if ( empty( $opts['enabled'] ) || empty( $opts['jsonld'] ) ) return;

        if ( is_singular() ) {
            global $post;
            if ( ! $post ) return;
            if ( ! in_array( $post->post_type, (array)$opts['post_types'] ) ) return;

            // Build simple Course schema (minimal)
            $schema = [
                "@context" => "https://schema.org",
                "@type" => "Course",
                "name" => get_the_title( $post->ID ),
                "description" => wp_trim_words( strip_tags( $post->post_content ), 50 ),
                "url" => get_permalink( $post->ID ),
                "provider" => [
                    "@type" => "Organization",
                    "name" => get_bloginfo( 'name' ),
                    "sameAs" => home_url(),
                ],
            ];

            // If author/instructor info exists, attempt to add
            $author_id = $post->post_author;
            if ( $author_id ) {
                $schema['creator'] = [
                    "@type" => "Person",
                    "name" => get_the_author_meta( 'display_name', $author_id ),
                ];
            }

            echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>';
        }
    }

    public function add_to_sitemaps( $post_types ) {
        $opts = $this->get_opts();
        if ( empty( $opts['enabled'] ) || empty( $opts['sitemap'] ) ) return $post_types;

        $configured = (array) ($opts['post_types'] ?? []);
        foreach ( $configured as $pt ) {
            if ( post_type_exists( $pt ) ) {
                $post_types[ $pt ] = $pt;
            }
        }
        return $post_types;
    }

    public function maybe_ping_search_engines( $new_status, $old_status, $post ) {
        $opts = $this->get_opts();
        if ( empty( $opts['enabled'] ) || empty( $opts['ping_search_engines'] ) ) return;

        if ( $new_status === 'publish' && $old_status !== 'publish' && in_array( $post->post_type, (array)$opts['post_types'] ) ) {
            $sitemap = home_url( '/wp-sitemap.xml' );
            $pings = [
                'https://www.google.com/ping?sitemap=' . urlencode( $sitemap ),
                'https://www.bing.com/ping?sitemap=' . urlencode( $sitemap ),
            ];
            foreach ( $pings as $url ) {
                // lightweight async request
                wp_remote_get( $url, [ 'timeout' => 3, 'blocking' => false ] );
            }
        }
    }

    public function ajax_track() {
        // expects: event (page_view | engagement_time), post_id, nonce, seconds (for engagement_time)
        if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash($_REQUEST['nonce']) ), 'tutor_seo_nonce' ) ) {
            wp_send_json_error( 'invalid_nonce', 403 );
        }

        $event = sanitize_text_field( wp_unslash( $_REQUEST['event'] ?? '' ) );
        $post_id = intval( $_REQUEST['post_id'] ?? 0 );
        global $wpdb;

        if ( ! $post_id ) {
            wp_send_json_error( 'no_post', 400 );
        }

        $table = $this->db_table;
        $today = date( 'Y-m-d' );

        if ( $event === 'page_view' ) {
            // increment today's views
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO $table (post_id, stat_date, views, total_time) VALUES (%d, %s, 1, 0) ON DUPLICATE KEY UPDATE views = views + 1",
                $post_id, $today
            ) );

            // update post meta totals
            $views = intval( get_post_meta( $post_id, '_tutor_seo_view_count', true ) );
            update_post_meta( $post_id, '_tutor_seo_view_count', $views + 1 );
            update_post_meta( $post_id, '_tutor_seo_last_view', current_time('mysql') );

            wp_send_json_success( 'ok' );
        }

        if ( $event === 'engagement_time' ) {
            // seconds field expected
            $seconds = intval( $_REQUEST['seconds'] ?? 0 );
            if ( $seconds <= 0 ) {
                wp_send_json_error( 'no_seconds', 400 );
            }

            // add to today's total_time
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO $table (post_id, stat_date, views, total_time) VALUES (%d, %s, 0, %d) ON DUPLICATE KEY UPDATE total_time = total_time + %d",
                $post_id, $today, $seconds, $seconds
            ) );

            // update post meta totals
            $total_time = intval( get_post_meta( $post_id, '_tutor_seo_total_time', true ) );
            update_post_meta( $post_id, '_tutor_seo_total_time', $total_time + $seconds );

            wp_send_json_success( 'ok' );
        }

        wp_send_json_error( 'unknown_event', 400 );
    }

    public function shortcode_analytics( $atts ) {
        $opts = $this->get_opts();
        if ( empty( $opts['enabled'] ) ) return '';

        $atts = shortcode_atts( [ 'user_id' => 0 ], $atts, 'tutor_seo_analytics' );
        $user_id = intval( $atts['user_id'] );

        ob_start();

        // require login for privacy - only allow admins or post authors to view
        if ( ! is_user_logged_in() ) {
            echo '<p>Please log in to view analytics.</p>';
            return ob_get_clean();
        }

        $user = wp_get_current_user();
        $is_admin = user_can( $user, 'manage_options' );

        global $wpdb;
        $post_types = (array) ($opts['post_types'] ?? ['post']);

        // fetch posts - if not admin, show only posts authored by current user
        $args = [
            'post_type' => $post_types,
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ];
        if ( ! $is_admin ) {
            $args['author'] = $user->ID;
        } elseif ( $user_id ) {
            $args['author'] = $user_id;
        }

        $posts = get_posts( $args );
        echo '<table style="width:100%;border-collapse:collapse"><thead><th style="text-align:left">Post</th><th>Views</th><th>Avg Time (s)</th></thead><tbody>';
        foreach ( $posts as $p ) {
            $views = intval( get_post_meta( $p->ID, '_tutor_seo_view_count', true ) );
            $total_time = intval( get_post_meta( $p->ID, '_tutor_seo_total_time', true ) );
            $avg = $views ? round( $total_time / $views, 1 ) : 0;
            echo '<tr><td><a href="'.esc_url( get_permalink($p->ID) ).'" target="_blank">'.esc_html( get_the_title($p->ID) ).'</a></td><td>'.esc_html($views).'</td><td>'.esc_html($avg).'</td></tr>';
        }
        echo '</tbody></table>';
        return ob_get_clean();
    }
}

Tutor_SEO_Booster::instance();

/**
 * WP_List_Table subclass for displaying analytics
 */
if ( ! class_exists( 'Tutor_SEO_List_Table' ) ) {
    if ( ! class_exists( 'WP_List_Table' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
    }

    class Tutor_SEO_List_Table extends WP_List_Table {
        private $data;

        public function __construct( $data = [] ) {
            parent::__construct( [
                'singular' => 'seo_stat',
                'plural'   => 'seo_stats',
                'ajax'     => false,
            ] );
            $this->data = $data;
        }

        public function get_columns() {
            return [
                'title'     => __( 'Post', 'tutor-seo-booster' ),
                'type'      => __( 'Type', 'tutor-seo-booster' ),
                'views'     => __( 'Total Views', 'tutor-seo-booster' ),
                'avg_time'  => __( 'Avg Time (s)', 'tutor-seo-booster' ),
                'last_view' => __( 'Last Viewed', 'tutor-seo-booster' ),
            ];
        }

        public function get_sortable_columns() {
            return [
                'title'     => [ 'title', false ],
                'type'      => [ 'type', false ],
                'views'     => [ 'views', true ],
                'avg_time'  => [ 'avg_time', false ],
                'last_view' => [ 'last_view', false ],
            ];
        }

        public function prepare_items() {
            $columns  = $this->get_columns();
            $hidden   = [];
            $sortable = $this->get_sortable_columns();
            $this->_column_headers = [ $columns, $hidden, $sortable ];

            $data = $this->data;

            // handle search (WP_List_Table search will pass 's')
            $s = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
            if ( $s ) {
                $s_lower = mb_strtolower( $s );
                $data = array_filter( $data, function( $row ) use ( $s_lower ) {
                    return ( mb_stripos( $row['title'], $s_lower ) !== false );
                } );
                $data = array_values( $data );
            }

            // handle ordering
            $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'views';
            $order = isset( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'desc';

            usort( $data, function( $a, $b ) use ( $orderby, $order ) {
                if ( in_array( $orderby, ['views', 'avg_time'] ) ) {
                    $cmp = $a[$orderby] <=> $b[$orderby];
                } elseif ( $orderby === 'last_view' ) {
                    $ta = $a['last_view'] ? strtotime( $a['last_view'] ) : 0;
                    $tb = $b['last_view'] ? strtotime( $b['last_view'] ) : 0;
                    $cmp = $ta <=> $tb;
                } else {
                    $cmp = strcasecmp( $a['title'], $b['title'] );
                }
                return ( $order === 'asc' ) ? $cmp : -$cmp;
            } );

            // pagination
            $per_page = 20;
            $current_page = $this->get_pagenum();
            $total_items = count( $data );

            $this->items = array_slice( $data, ( $current_page - 1 ) * $per_page, $per_page );
            $this->set_pagination_args( [
                'total_items' => $total_items,
                'per_page'    => $per_page,
            ] );
        }

        // Default column rendering
        public function column_default( $item, $column_name ) {
            switch ( $column_name ) {
                case 'type':
                    return esc_html( $item['type'] );
                case 'views':
                    return number_format_i18n( $item['views'] );
                case 'avg_time':
                    return esc_html( $item['avg_time'] );
                case 'last_view':
                    return esc_html( $item['last_view'] ?: '-' );
                default:
                    return print_r( $item, true );
            }
        }

        // Title column with edit & view links
        public function column_title( $item ) {
            $title = esc_html( $item['title'] );
            $edit = $item['edit_link'] ? '<a href="' . esc_url( $item['edit_link'] ) . '">' . __( 'Edit', 'tutor-seo-booster' ) . '</a>' : '';
            $view = $item['permalink'] ? '<a href="' . esc_url( $item['permalink'] ) . '" target="_blank">' . __( 'View', 'tutor-seo-booster' ) . '</a>' : '';
            $actions = array_filter( [ 'edit' => $edit, 'view' => $view ] );
            $action_html = '';
            if ( $actions ) {
                $pairs = [];
                foreach ( $actions as $k => $v ) {
                    $pairs[] = $v;
                }
                $action_html = '<div class="row-actions">' . implode( ' | ', $pairs ) . '</div>';
            }
            return '<strong>' . $title . '</strong>' . $action_html;
        }

        // Map column 'title' to column_title
        public function column_title_cb( $item ) {
            return $this->column_title( $item );
        }

        // Override column rendering to hook title col
        public function get_sortable_columns_map() {
            return $this->get_sortable_columns();
        }
    }
}
