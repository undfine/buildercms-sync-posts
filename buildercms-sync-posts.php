<?php 
namespace BCMS;
/**
 * Plugin Name: BuilderCMS Sync Posts
 * Plugin URI:
 * Description: This plugin connects to the BuilderCMS to sync posts and custom post–types to current inventory
 * Version: 1.0.1
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

	private $log_file = '';

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
		$this->log_file = $this->plugin_path . '/log/request_log.log';
		
		// $this->load_language( 'bcms' );

		//register plugin activation hooks
		register_activation_hook(__FILE__, array($this, 'plugin_activate')); //activate hook
		register_deactivation_hook(__FILE__, array($this, 'plugin_deactivate')); //deactivate hook

		
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

		add_action( 'wp_ajax_nopriv_bcms_sync_posts', [$this,'ajax_sync_posts'] );
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

		// Clear the log when called manually
		$this->clear_log_file();
		check_ajax_referer('bcms_sync_posts_nonce', 'nonce');

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

	public function log_to_file($message, $type = 'Error') {
		
		if ( !$this->logging || ($type !== 'Error' && intval($this->logging)<2 ) ){
			return; // exit if logging is disabled or logging more than errors
		}

		if (!is_string($message)){
			$message = print_r($message,true);
		}
		
		$log_message = "[" . date('d-M-Y H:i:s') . "] ". $message . PHP_EOL;
		file_put_contents($this->log_file, $log_message, FILE_APPEND);
        
    }

	private function clear_log_file(){
		file_put_contents($this->log_file, '');
	}

	public function setup_cron_schedule(){
		$schedule = $this->settings['cron'];

		if (!$schedule || $schedule === 'none'){
			$this->clear_cron_schedule();
			return;
		}
		
		if (!wp_next_scheduled('bcms_sync_posts_scheduled_event')) {
			wp_schedule_event(time(), $schedule, 'bcms_sync_posts_scheduled_event');
		} 

		add_action('bcms_sync_posts_scheduled_event', [$this,'sync_posts']);
	}

	private function clear_cron_schedule(){
		wp_clear_scheduled_hook('bcms_sync_posts_scheduled_event');
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
			function bcms_ajax_sync_posts(event){

				//console.log('bcms_ajax_sync_posts() called');
				event.preventDefault();

				$message_field = jQuery('#bcms-message');

				// AJAX request
				jQuery.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'bcms_sync_posts', // The action hook
						nonce: '<?php echo wp_create_nonce('bcms_sync_posts_nonce'); ?>', // Nonce for security
					},
					success: function(response) {
						console.log(response);
						if (typeof response === 'object'){
							$message_field.html('<pre>'+response.data+'</pre>');	
						}
						// Handle success, update UI, or log the response
					},
					error: function(error) {
						console.log(error);
						if (typeof response === 'object'){
							$message_field.html('<pre>'+response.data+'</pre>');	
						}
						// Handle error, update UI, or log the error
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

	public function plugin_activate()
	{
		// flush permalinks
		// flush_rewrite_rules();
	}

	public function plugin_deactivate()
	{
		$this->clear_log_file();
		$this->clear_cron_schedule();
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