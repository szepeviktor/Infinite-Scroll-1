<?php
/**
 * Infinite Scroll Presets Interface
 *
 * Stores theme-specific presets for CSS Selectors to aid with setup. Pulls community presets from CSV
 * stored in the plugin's SVN repo.
 *
 * The csv from the repo is cached for 24 hours as a site-transient (available to all sites on a network install)
 *
 * Custom presets (beyond the SVN CSV) are stored as a site option (also available to all sites on network)
 *
 * On a single site install, settings available to all admins.
 * On a network install, settings available only to super-admins (but site-admins can load those presets)
 *
 * If a user hasn't chosen CSS selectors for there theme and a preset exists, the plugin will
 * default to the preset (thus in many cases, no need to adjust any settings or know this exists).
 *
 * Hierarchy of presets: 1) User specified, 2) (admin specified) custom preset, 3) community specified preset
 *
 * @subpackage Presets
 * @author Benjamin J. Balter <ben@balter.com>
 * @package Infinite_Scroll
 */

class Infinite_Scroll_Presets {

	private $parent;
	public $preset_url        = 'http://plugins.svn.wordpress.org/infinite-scroll/branches/PresetDB/PresetDB.csv.php';
	public $custom_preset_key = 'infinite_scroll_presets';
	public $ttl               = 86400; //TTL of transient cache in seconds, 1 day = 86400 = 60*60*24
	public $keys              = array( 'theme', 'contentSelector', 'navSelector', 'itemSelector', 'nextSelector' );

	/**
	 * Register hooks with WordPress API
	 *
	 * @param object $parent (reference) the parent class
	 */
	function __construct( &$parent ) {

		$this->parent = &$parent;

		add_action( 'admin_init', array( &$this, 'set_presets' ) );
		add_action( 'wp_ajax_infinite-scroll-edit-preset', array( &$this, 'process_ajax_edit' ) );
		add_action( 'wp_ajax_infinite-scroll-delete-preset', array( &$this, 'process_ajax_delete' ) );
		add_filter( $this->parent->prefix . 'presets', array( &$this, 'merge_custom_presets' ) );
		add_filter( $this->parent->prefix . 'options', array( &$this, 'default_to_presets'), 9 );
		add_action( $this->parent->prefix . 'refesh_cache', array( &$this, 'get_presets' ) );

	}


	/**
	 * Allow for class overloading
	 * @param string $preset the theme to retrieve
	 * @return array|bool the presets or false on failure
	 */
	function __get( $preset ) {
		return $this->get_preset( $preset );
	}


	/**
	 * Pulls preset array from cache, or retrieves and parses
	 * @return array an array of preset objects
	 */
	function get_presets() {

		//check cache
		if ( $cache = get_transient( $this->parent->prefix . 'presets' ) )
			return apply_filters( $this->parent->prefix . 'presets', $cache );

		$data = wp_remote_get( $this->preset_url );

		if ( is_wp_error( $data ) )
			return array();

		$data = wp_remote_retrieve_body( $data );

		$data = explode( "\n", $data );

		//remove first two lines
		$data = array_slice( $data, 2 );

		//remove the last line
		array_pop( $data );

		$presets = array();

		//build preset objects and stuff into keyed array
		foreach ( $data as $line ) {

			$lineObj = new stdClass;
			$parts = str_getcsv( $line );

			foreach ( $this->keys as $id => $key )
				$lineObj->$key = $parts[ $id ];

			$presets[ $lineObj->theme ] = $lineObj;

		}

		//sort by key alpha ascending
		asort( $presets );

		set_transient( $this->parent->prefix . 'presets', $presets, $this->ttl );

		return apply_filters( $this->parent->prefix . 'presets', $presets );

	}


	/**
	 * Return a theme's preset object
	 * @param string the name of the theme
	 * @param string $preset the theme to retrieve
	 * @return object the preset object
	 */
	function get_preset( $preset ) {

		$presets = $this->get_presets();
		return ( array_key_exists( $preset, $presets ) ) ? $presets[ $preset ] : false;

	}


	/**
	 * On plugin activation register with WP_Cron API to asynchronously refresh cache every 24 hours
	 * This will also asynchronously prime the cache on activation
	 */
	function schedule() {
		wp_schedule_event( time(), 'daily', $this->parent->prefix . 'refresh_cache' );
	}


	/**
	 * Clear chron schedule on deactivation
	 */
	function unschedule() {
		wp_clear_scheduled_hook( $this->parent->prefix . 'refresh_cache' );
	}


	/**
	 * Conditionally prompts users on options page to use the default selectors
	 * @uses get_preset
	 */
	function preset_prompt() {
		$theme = strtolower( get_current_theme() );
		$preset = $this->get_preset( $theme );

		if ( !$preset )
			return;

		unset( $preset->theme );

		//if they are already using the preset, don't prompt
		$using_default = true;
		foreach ( $preset as $key => $value ) {

			if ( $this->parent->options->$key != $value )
				$using_default = false;

		}

		if ( $using_default )
			return;

		require dirname( $this->parent->file ) . '/templates/preset-prompt.php';

	}


	/**
	 * Reset selectors to default
	 */
	function set_presets() {

		if ( !isset( $_GET['set_presets'] ) )
			return;

		if ( !current_user_can( 'manage_options' ) )
			return;

		check_admin_referer( 'infinite-scroll-presets', 'nonce' );

		//don't delete options if we don't have a preset
		$theme = strtolower( get_current_theme() );
		$preset = $this->get_preset( $theme );

		if ( !$preset )
			return;

		foreach ( $this->keys as $key )
			$this->parent->options->$key = null;

		wp_redirect( admin_url( 'options-general.php?page=infinite_scroll_options&settings-updated=true' ) );
		exit();

	}


	/**
	 * Handles AJAX edits from the manage presets form
	 */
	function process_ajax_edit() {

		if ( !current_user_can( 'manage_options' ) )
			wp_die( -1 );

		if ( is_multisite() && !is_super_admin() )
			wp_die( -1 );

		$data = new stdClass;

		foreach ( $this->keys as $key )
			$data->$key = addslashes( trim( $_POST[ $key . '_column-' . $key ] ) );

		$this->set_custom_preset( $data->theme, $data );

		wp_die( 1 );

	}


	/**
	 * Handles AJAX requests to delete presets from the manage presets form
	 */
	function process_ajax_delete() {

		if ( !current_user_can( 'manage_options' ) )
			wp_die( -1 );

		if ( is_multisite() && !is_super_admin() )
			wp_die( -1 );

		if ( !isset( $_GET['theme'] ) )
			wp_die( -1 );

		$this->delete_custom_preset( $_GET['theme'] );

	}


	/**
	 * Retreive global custom presets
	 * @return array the custom preset array
	 */
	function get_custom_presets( ) {
		$presets = get_site_option( $this->custom_preset_key, array(), true );
		return apply_filters( $this->parent->prefix . 'custom_presets', $presets );
	}


	/**
	 * Update global custom presets
	 * @param array $presets the presets (all)
	 * @return bool success/fail
	 */
	function set_custom_presets( $presets ) {
		return update_site_option(  $this->custom_preset_key, $presets );
	}


	/**
	 * Store a theme's global presets
	 * @param string $theme the theme name
	 * @param array $preset the presets
	 * @return bool success/fail
	 */
	function set_custom_preset( $theme, $preset ) {
		$presets = $this->get_custom_presets();
		$presets[ $theme ] = $preset;
		return $this->set_custom_presets( $presets );
	}


	/**
	 * Removes a custom preset from the database
	 * @param string $theme the theme to remove
	 * @return bool success/fail
	 */
	function delete_custom_preset( $theme ) {
		$presets = $this->get_custom_presets();
		unset( $presets[ $theme ] );
		return $this->set_custom_presets( $presets );

	}


	/**
	 * Allow custom presets to merge/override community presets
	 * @param unknown $presets
	 * @return unknown
	 */
	function merge_custom_presets( $presets ) {

		// 2nd array overrides keys that overlap with first array
		$presets = array_merge( $presets, $this->get_custom_presets() );

		//sort by key alpha ascending
		asort( $presets );

		return $presets;

	}


	/**
	 * If a selector is not set, try to grab a preset to save the user trouble
	 * @param array $options the options array
	 * @return array the defaulted options array
	 */
	function default_to_presets( $options ) {

		//we don't have a preset, no need to go any further
		if ( !( $preset = $this->get_preset( strtolower( get_current_theme() ) ) ) )
			return $options;

		foreach ( $this->keys as $key ) {
			if ( empty( $options[$key] ) )
				$options[$key] = $preset->$key;
		}

		return $options;


	}


}


if (!class_exists('WP_List_Table')) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table for manage custom presets page
 */
class Infinite_Scroll_Presets_Table extends WP_List_Table {

	/**
	 * Register with Parent
	 */
	function __construct( $args = array() ) {

		parent::__construct( array(
				'singular' => 'preset',
				'plural' => 'presets',
				'ajax' => true,
			) );

	}


	/**
	 * Default column callback
	 * @param object $item the item to display
	 * @param string $column_name the column name
	 * @return string the HTML to display
	 */
	function column_default( $item, $column_name ) {
		return $item->$column_name;
	}


	/**
	 * Callback to display the theme column
	 * @param object $item the preset object
	 * @return string the HTML to display
	 */
	function column_theme( $item ) {
		$s = '<strong><a href="#" class="theme-name">' . $item->theme . '</a></strong>';
		$s .= '<div class="edit edit-link" style="visibility:hidden;"><a href="#">' . __( 'Edit', 'infinite-scroll' ) . '</a> | <span class="delete"><a href="#">' . __( 'Delete', 'infinite-scroll' ) . '</a></span></div>';
		$s .= '<div class="save save-link" style="display:none; padding-top:5px;"><a href="#" class="button-primary">' . __( 'Save', 'infinite-scroll' ) . '</a> <a href="#" class="cancel">' . __( 'Cancel', 'infinite-scroll' ) . '</a> <img class="loader" style="display:none;" src="'. admin_url( '/images/loading.gif' ) .'" /></div>';
		return $s;
	}


	/**
	 * Callaback to return list of columns to display
	 * @return array the columns to display
	 */
	function get_columns() {
		return array(
			'theme' => 'Theme',
			'contentSelector' => 'Content Selector',
			'navSelector' => 'Navigation Selector',
			'nextSelector' => 'Next Selector',
			'itemSelector' => 'Item Selector',
		);
	}


	/**
	 * Grab data and filter prior to passing to table class
	 */
	function prepare_items() {
		global $infinite_scroll;

		$per_page = 25;

		$columns = $this->get_columns();
		$hidden = array();
		$sortable = array();

		$this->_column_headers = array($columns, $hidden, $sortable);

		$data = $infinite_scroll->presets->get_presets();

		//merge in themes
		$themes = get_themes();

		foreach ( $themes as $theme => $theme_data ) {

			$theme = strtolower( $theme_data['Name'] );

			if ( array_key_exists( $theme, $data) )
				continue;

			$themeObj = new stdClass;

			foreach ( $infinite_scroll->presets->keys as $key )
				$themeObj->$key = null;

			$themeObj->theme = $theme;

			$data[ $theme ] = $themeObj;

		}

		asort( $data );

		$current_page = $this->get_pagenum();

		$total_items = count($data);

		$data = array_slice($data, (($current_page-1)*$per_page), $per_page);

		$this->items = $data;

		$this->set_pagination_args( array(
				'total_items' => $total_items,                  //WE have to calculate the total number of items
				'per_page'    => $per_page,                     //WE have to determine how many items to show on a page
				'total_pages' => ceil($total_items/$per_page)   //WE have to calculate the total number of pages
			) );

	}


}