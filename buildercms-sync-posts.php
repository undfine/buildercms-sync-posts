<?php 
namespace BCMS;
/**
 * Plugin Name: BuilderCMS Sync Posts
 * Plugin URI:
 * Description: This plugin connects to the BuilderCMS to sync posts and custom post–types to current inventory
 * Version: 1.1.0
 * Author: Dustin Wight
 * Author URI:
 * License: GPL3

* Textdomain: "bcms"
*/
if ( ! defined( 'ABSPATH' ) ) {	exit; }


// Instantiate the plugin class only at plugins loaded
add_action(
	'plugins_loaded',
	array ( Plugin::get_instance(), 'init_plugin' )
);

// Activation hooks must be registered when the plugin file loads, not on plugins_loaded
register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'plugin_activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'plugin_deactivate' ) );

class Plugin
{
	/**
	 * Class Namespace
	 * @property string $namespace
	 */
	private $namespace = 'BCMS';
	/**
	 * Plugin instance.
	 * @see get_instance()
	 * @property object $instance
	 */
	private static $instance = NULL;

	/**
	 * URL to this plugin's directory.
	 * @property string $plugin_name
	 */
	private $plugin_name = 'Builder CMS Sync Posts';
	/**
	 * the slug used
	 * @property string $plugin_slug
	 */
	private $plugin_slug = 'buildercms-sync';

	/**
	 * URL to this plugin's directory.
	 * @property string $plugin_url
	 */
	private $plugin_url = '';

	/**
	 * Path to this plugin's directory.
	 * @property string $plugin_path
	 */
	private $plugin_path = '';

	/**
	 * Per-site option holding the sync log (kept out of the web root)
	 */
	const LOG_OPTION = 'bcms_sync_log';

	/**
	 * Oldest log lines are dropped once the log passes this size
	 */
	const LOG_MAX_BYTES = 262144;

	const CRON_HOOK = 'bcms_sync_posts_scheduled_event';

	private $logging;

	/**
	 * the instance of external API
	 */
	private $api = null;

	/**
	 * the instance of the settings page
	 */
    private $settings_page = null;

	private $settings = [];

	/**
	 * Access this plugin’s working instance
	 */
	public static function get_instance()
	{
		NULL === self::$instance and self::$instance = new self;
		return self::$instance;
	}


	public function init_plugin()
	{

		$this->plugin_url    = plugins_url( '', __FILE__ );
		$this->plugin_path   = plugin_dir_path( __FILE__ );
		
		// $this->load_language( 'bcms' );

		$this->include_sourcecode();
		$this->init_classes();
		$this->add_actions();
		$this->setup_cron_schedule();
	}

	private function include_sourcecode(){
		$files = [
			'SettingsPageBuilder',
			'SettingsPage',
			'BuildercmsApi'
		];

		foreach ($files as $file){
			require_once $this->plugin_path . 'src' . DIRECTORY_SEPARATOR . $file .'.php';
		}
	}

	private function init_classes(){
		$this->settings_page = new SettingsPage();
		$this->api = new BuildercmsApi( $this->get_settings());
	}

	private function add_actions(){
		//enqueue scripts & styles
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts_and_styles')); //admin scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'public_scripts_and_styles')); //public scripts and styles
		
		//add settings page link on plugins page
		add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_page_link') );

		// Logged-in admins only: no wp_ajax_nopriv_ handler
		add_action( 'wp_ajax_bcms_sync_posts', [$this,'ajax_sync_posts'] );
		
	}

	/**
	 * Function called via Ajax from "sync posts" button on admin and by scheduled CRON events
	 * 
	 */
	public function sync_posts(){
		$result = $this->api->sync_posts_from_api();
		
		// update the cron schedule
		update_option('bcms_last_sync_time', current_time('mysql'));
		return $result;
	}

	public function ajax_sync_posts(){

		check_ajax_referer('bcms_sync_posts_nonce', 'nonce');

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'You are not allowed to run a sync.', 403 );
		}

		// Clear the log when called manually
		$this->clear_log();

		$result = $this->sync_posts();
		$message = '';

		if ($result) {
			$message = 'Sync completed successfully!';
			wp_send_json_success($message);
		} else {
			$message = 'Error syncing with external feed.';
			wp_send_json_error($message);
		}
	}

	/**
	 * Appends to this site's sync log, stored in the database so it is per site
	 * and never served as a public file
	 */
	public function log($message, $type = 'Error') {
		
		if ( !$this->logging || ($type !== 'Error' && intval($this->logging)<2 ) ){
			return; // exit if logging is disabled or logging more than errors
		}

		if (!is_string($message)){
			$message = print_r($message,true);
		}
		
		$log = get_option(self::LOG_OPTION, '');
		$log .= "[" . wp_date('d-M-Y H:i:s') . "] ". $message . PHP_EOL;

		// Drop the oldest lines once the log gets too big
		if (strlen($log) > self::LOG_MAX_BYTES){
			$log = substr($log, -self::LOG_MAX_BYTES);
			$log = substr($log, strpos($log, PHP_EOL) + 1);
		}

		update_option(self::LOG_OPTION, $log, false);
    }

	public function get_log(){
		return get_option(self::LOG_OPTION, '');
	}

	private function clear_log(){
		delete_option(self::LOG_OPTION);
	}

	public function setup_cron_schedule(){
		$schedule = $this->settings['cron'];

		add_action(self::CRON_HOOK, [$this,'sync_posts']);

		if (!$schedule || $schedule === 'none' || !array_key_exists($schedule, wp_get_schedules())){
			self::clear_cron_schedule();
			return;
		}
		
		// Reschedule when the interval setting has changed
		if (wp_get_schedule(self::CRON_HOOK) !== $schedule) {
			self::clear_cron_schedule();
			wp_schedule_event(time(), $schedule, self::CRON_HOOK);
		} 
	}

	private static function clear_cron_schedule(){
		wp_clear_scheduled_hook(self::CRON_HOOK);
	}

	public function get_plugin_url(){
		return $this->plugin_url;
	}

	public function get_plugin_slug(){
		return $this->plugin_slug;
	}

	private function get_settings(){
		// check if settings is empty and store the value
		if ( empty($this->settings) ){
			$this->settings = $this->settings_page->get_settings();
		}
		if ( isset($this->settings['logging'])){
			$this->logging = $this->settings['logging'];
		}
		return $this->settings;
	}

	public function add_settings_page_link($links){
		// Add link to the beginning		
		array_unshift($links, $this->settings_page->get_settings_page_link() );
		return $links;
	}
	
	public function admin_scripts_and_styles()
    {
		wp_enqueue_script('jquery');
		// echo '<pre>'.var_dump($this->get_settings()).'</pre>';
		// wp_enqueue_style('bcms-admin', $this->plugin_url . '/css/buildercms-sync-admin.css');

		add_action('admin_footer', [$this,'setup_ajax']);
	}

	public function setup_ajax(){
		?>
		<script id="bcms_ajax_sync_posts" type="text/javascript">
			jQuery(function(){
				jQuery('#sync-posts-button').on('click', bcms_ajax_sync_posts);
			});

			function bcms_ajax_sync_posts(event){

				event.preventDefault();

				var $message_field = jQuery('#bcms-message');
				$message_field.text('Syncing…');

				// AJAX request
				jQuery.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'bcms_sync_posts', // The action hook
						nonce: '<?php echo wp_create_nonce('bcms_sync_posts_nonce'); ?>', // Nonce for security
					},
					success: function(response) {
						if (typeof response === 'object'){
							$message_field.empty().append(jQuery('<pre>').text(response.data));
						}
					},
					error: function(xhr) {
						var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : 'Request failed (' + xhr.status + ')';
						$message_field.empty().append(jQuery('<pre>').text(data));
					}
				});
			}
		</script>
		<?php
	}


	public function public_scripts_and_styles()
    {
        // Load custom scripts and scripts
        //wp_register_script('bcms-script', $this->plugin_url . '/js/buildercms-sync.js', [], true);
        //wp_register_style('bcms-styles', $this->plugin_url . '/css/buildercms-sync.css');
    }

	public static function plugin_activate()
	{
		// flush permalinks
		// flush_rewrite_rules();
	}

	/**
	 * Clears the scheduled sync and the log, on every site when network-deactivated
	 * @param bool $network_wide
	 */
	public static function plugin_deactivate($network_wide = false)
	{
		if (is_multisite() && $network_wide){
			foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id){
				switch_to_blog($site_id);
				self::clear_cron_schedule();
				delete_option(self::LOG_OPTION);
				restore_current_blog();
			}
			return;
		}

		self::clear_cron_schedule();
		delete_option(self::LOG_OPTION);
		// flush permalinks
		// flush_rewrite_rules();
	}


	/* purposely empty construct using singleton pattern 
	* class is instantiated on action hook and referenced to itself
	*/
	public function __construct() {}

	/**
	 * Loads translation file.
	 * Accessible to other classes to load different language files (admin and front-end for example).
	 */
	public function load_language( $domain )
	{
		//load_plugin_textdomain( $domain, FALSE, $this->plugin_path . 'languages');
	}
}