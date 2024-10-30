<?php
/**
 * Media Search Plus, fork of Media Search Enhanced.
 *
 * Search through all fields in Media Library.
 *
 * @package   Media_Search_Plus
 * @author    PhilLehmann <sayhi@phil.to>
 * @license   GPL-2.0+
 * @link      https://github.com/PhilLehmann/media-search-plus
 * @copyright 2022 PhilLehmann
 *
 * @wordpress-plugin
 * Plugin Name:       Media Search Plus
 * Plugin URI:        https://github.com/PhilLehmann/media-search-plus
 * Description:       Search through all fields in Media Library.
 * Version:           0.8.2
 * Author:            PhilLehmann
 * Author URI:        http://phil.to
 * Text Domain:       media-search-plus
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/PhilLehmann/media-search-plus
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die();
}

/**
 * Plugin class.
 *
 * @package Media_Search_Plus
 * @author  PhilLehmann <sayhi@phil.to>
 */
class Media_Search_Plus {

	/**
	 * Instance of this class.
	 *
	 * @since    0.0.1
	 * @var      object
	 */
	protected static $instance = null;

	/**
	 * Initialize the plugin by setting localization and loading public scripts
	 * and styles.
	 *
	 * @since     0.0.1
	 */
	private function __construct() {

		// Media Search filters
		add_filter('posts_clauses', array($this, 'posts_clauses'), 20);

		// Add a media search form shortcode
		add_shortcode('msp-search-form', array($this, 'search_form'));

		// Hook the image into the_excerpt
		add_filter('the_excerpt', array($this, 'get_the_image'));

		// Change the permalinks at media search results page
		add_filter('attachment_link', array($this, 'get_the_url'), 10, 2);

		// Filter the search form on search page to add post_type hidden field
		add_filter('get_search_form', array($this, 'search_form_on_search'));
	}

	/**
	 * Return an instance of this class.
	 *
	 * @since     0.0.1
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if (null == self::$instance) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Set query clauses in the SQL statement
	 *
	 * @return array
	 * @since    0.6.0
	 */
	public static function posts_clauses($pieces) {
		global $wp_query, $wpdb;

		$requestQuery = isset($_REQUEST['query']) ? sanitize_text_field($_REQUEST['query']) : null;
		$requestAction = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : null;

		$vars = $wp_query->query_vars;
		if(empty($vars)) {
			$vars = $requestQuery == null ? array() : $requestQuery;
		}

		// Rewrite the where clause
		if(!empty($vars['s']) && (($requestAction == 'query-attachments') || $vars['post_type'] == 'attachment')) {
			$pieces['where'] = " AND $wpdb->posts.post_type = 'attachment' AND ($wpdb->posts.post_status = 'inherit' OR $wpdb->posts.post_status = 'private')";

			if(class_exists('WPML_Media')) {
				global $sitepress;
				//get current language
				$lang = $sitepress->get_current_language();
				$pieces['where'] .= $wpdb->prepare(" AND t.element_type='post_attachment' AND t.language_code = %s", $lang);
			}

			if(!empty($vars['post_parent'])) {
				$pieces['where'] .= $wpdb->prepare(" AND $wpdb->posts.post_parent = %d", $vars['post_parent']);
			} elseif(isset($vars['post_parent']) && 0 === $vars['post_parent']) {
				// Get unattached attachments
				$pieces['where'] .= " AND $wpdb->posts.post_parent = 0";
			}

			if(!empty($vars['post_mime_type'])) {
				// Use esc_like to escape slash
				$like = '%' . $wpdb->esc_like($vars['post_mime_type']) . '%';
				$pieces['where'] .= $wpdb->prepare(" AND $wpdb->posts.post_mime_type LIKE %s", $like);
			}

			if(!empty($vars['m'])) {
				$year = substr($vars['m'], 0, 4);
				$monthnum = substr($vars['m'], 4);
				$pieces['where'] .= $wpdb->prepare(" AND YEAR($wpdb->posts.post_date) = %d AND MONTH($wpdb->posts.post_date) = %d", $year, $monthnum);
			} else {
				if(!empty($vars['year']) && 'false' != $vars['year']) {
					$pieces['where'] .= $wpdb->prepare(" AND YEAR($wpdb->posts.post_date) = %d", $vars['year']);
				}

				if(!empty($vars['monthnum']) && 'false' != $vars['monthnum']) {
					$pieces['where'] .= $wpdb->prepare(" AND MONTH($wpdb->posts.post_date) = %d", $vars['monthnum']);
				}
			}

			// search for keyword "s"
			$like = '%' . $wpdb->esc_like($vars['s']) . '%';
			$pieces['where'] .= $wpdb->prepare(" AND ( ($wpdb->posts.ID LIKE %s) OR ($wpdb->posts.post_title LIKE %s) OR ($wpdb->posts.guid LIKE %s) OR ($wpdb->posts.post_content LIKE %s) OR ($wpdb->posts.post_excerpt LIKE %s)", $like, $like, $like, $like, $like);
			$pieces['where'] .= $wpdb->prepare(" OR (wpmspm.meta_key = '_wp_attachment_image_alt' AND wpmspm.meta_value LIKE %s)", $like);
			$pieces['where'] .= $wpdb->prepare(" OR (wpmspm.meta_key = '_wp_attached_file' AND wpmspm.meta_value LIKE %s)", $like);

			// Get taxes for attachements
			$taxes = get_object_taxonomies('attachment');
			if(!empty($taxes)) {
				$pieces['where'] .= $wpdb->prepare(" OR (tter.slug LIKE %s) OR (ttax.description LIKE %s) OR (tter.name LIKE %s)", $like, $like, $like);
			}

			$pieces['where'] .= " )";

			$pieces['join'] .= " LEFT JOIN $wpdb->postmeta AS wpmspm ON $wpdb->posts.ID = wpmspm.post_id";

			// Get taxes for attachements
			$taxes = get_object_taxonomies('attachment');
			if(!empty($taxes)) {
				$on = array();
				foreach($taxes as $tax) {
					$on[] = "ttax.taxonomy = '$tax'";
				}
				$on = '( ' . implode(' OR ', $on) . ' )';

				$pieces['join'] .= " LEFT JOIN $wpdb->term_relationships AS trel ON ($wpdb->posts.ID = trel.object_id) LEFT JOIN $wpdb->term_taxonomy AS ttax ON (" . $on . " AND trel.term_taxonomy_id = ttax.term_taxonomy_id) LEFT JOIN $wpdb->terms AS tter ON (ttax.term_id = tter.term_id) ";
			}

			$pieces['distinct'] = 'DISTINCT';

			$pieces['orderby'] = "$wpdb->posts.post_date DESC";
		}

		return $pieces;
	}

	/**
	 * Create media search form
	 *
	 * @return string Media search form
	 *
	 * @since 0.5.0
	 */
	public function search_form($form = '') {

		$domain = $this->plugin_slug;
		$s = sanitize_text_field(get_query_var('s'));

		$placeholder = (empty($s)) ? apply_filters('msp_search_form_placeholder', 'Search Media...') : $s;

		if(empty($form)) {
			$form = get_search_form(false);
		}

		$form = preg_replace("/(form.*class=\")(.\S*)\"/", '$1$2 ' . apply_filters('msp_search_form_class', 'msp-search-form') . '"', $form);
		$form = preg_replace("/placeholder=\"(.\S)*\"/", 'placeholder="' . esc_attr($placeholder) . '"', $form);
		$form = str_replace('</form>', '<input type="hidden" name="post_type" value="attachment" /></form>', $form);

		$result = apply_filters('msp_search_form', $form);

		return $result;
	}

	/**
	 * Get the attachment image and hook into the_excerpt
	 *
	 * @param  string $excerpt The excerpt HTML
	 * @return string          The hooked excerpt HTML
	 *
	 * @since  0.5.2
	 */
	public function get_the_image($excerpt) {
		global $post;

		if(!is_admin() && is_search() && $post->post_type == 'attachment') {
			$params = array(
				'attachment_id' => $post->ID,
				'size' => 'thumbnail',
				'icon' => false,
				'attr' => array()
			);
			$params = apply_filters('msp_get_attachment_image_params', $params);
			extract($params);

			$html = '';
			$clickable = apply_filters('msp_is_image_clickable', true);
			if($clickable) {
				$html .= '<a href="' . get_attachment_link($attachment_id) . '"';
				$attr = apply_filters('wp_get_attachment_image_attributes', $attr, $post, $size);
				$attr = array_map('esc_attr', $attr);
				foreach($attr as $name => $value) {
					$html .= " $name=" . '"' . $value . '"';
				}
				$html .= '>';
			}

			$html .= wp_get_attachment_image($attachment_id, $size, $icon, $attr);

			if($clickable)
				$html .= '</a>';

			$excerpt .= $html;
		}

		return $excerpt;

	}

	/**
	 * Add filter to hook into the attachment URL
	 *
	 * @param  string $link    The attachment's permalink.
	 * @param  int $post_id Attachment ID.
	 * @return string          The attachment's permalink.
	 *
	 * @since 0.5.4
	 */
	public function get_the_url($link, $post_id) {

		if(!is_admin() && is_search()) {
			$link = apply_filters('msp_get_attachment_url', $link, $post_id);
		}

		return $link;
	}

	/**
	 * Filter the search form on search page to add post_type hidden field
	 *
	 * @param  string $form The search form.
	 * @return string The filtered search form
	 *
	 * @since 0.7.2
	 */
	public function search_form_on_search($form) {

		if (is_search() && is_main_query() && isset($_GET['post_type']) && $_GET['post_type'] == 'attachment') {
			$form = $this->search_form($form);
		}

		return $form;
	}

}

add_action('plugins_loaded', array('Media_Search_Plus', 'get_instance'));