<?php
namespace BCMS;

if ( ! defined( 'ABSPATH' ) ) {	exit; }

class SettingsPage extends SettingsPageBuilder
{
    protected $settings = array();

    /**
     * Prefix marking an encrypted password ('bcms1:' + base64 of nonce and ciphertext)
     */
    const SECRET_PREFIX = 'bcms1:';

    /**
     * True when a stored password exists but can't be decrypted (e.g. the site's salts changed)
     */
    private $password_unreadable = false;

    public function __construct(){
        parent::__construct();
        $this->set_options(
            [
            'slug' => 'buildercms-sync-settings',              
            'title' => 'BuilderCMS Sync Settings',
			'menu' => 'BuilderCMS Sync',
			'description' => '<p>This plugin connects to the BuilderCMS to pull and update posts or post-types with current inventory for a specific community</p>'
            ]);
            
            // Get and set the settings
            $this->get_settings();

            add_action('admin_init', array($this, 'encrypt_legacy_password'));
            add_action('admin_notices', array($this, 'password_notice'));
    }

    /**
     * Encrypts a password at rest with a key derived from this install's auth salts
     * @param string $plain
     * @return string
     */
    public static function encrypt_secret($plain){
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::secret_key());
        return self::SECRET_PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * @param string $stored
     * @return string|false the password, or false if it can't be decrypted
     */
    public static function decrypt_secret($stored){
        if (strpos($stored, self::SECRET_PREFIX) !== 0){
            return $stored; // stored before encryption was added
        }
        $raw = base64_decode(substr($stored, strlen(self::SECRET_PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES){
            return false;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return sodium_crypto_secretbox_open($cipher, $nonce, self::secret_key());
    }

    private static function secret_key(){
        return sodium_crypto_generichash(wp_salt('auth') . '|bcms', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * Sanitize callback for the password field: a blank submission keeps the saved password
     * @param string $value
     * @return string
     */
    public function sanitize_password($value){
        $value = is_string($value) ? trim($value) : '';

        if ($value === ''){
            return get_option('bcms_password', '');
        }
        // Already encrypted (e.g. when saved by encrypt_legacy_password)
        if (strpos($value, self::SECRET_PREFIX) === 0){
            return $value;
        }
        return self::encrypt_secret($value);
    }

    /**
     * Encrypts a password saved by version 1.0.x, which stored it as plain text
     */
    public function encrypt_legacy_password(){
        $stored = get_option('bcms_password', '');
        if (is_string($stored) && $stored !== '' && strpos($stored, self::SECRET_PREFIX) !== 0){
            update_option('bcms_password', self::encrypt_secret($stored));
        }
    }

    public function password_notice(){
        if (!$this->password_unreadable || !current_user_can('manage_options')){
            return;
        }
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('BuilderCMS Sync: the saved password can\'t be decrypted on this site (its security salts have changed). Enter the password again in Settings > BuilderCMS Sync.', 'bcms')
        );
    }

    /**
     * @return array of fieldnames matching the option keys
     */
    private function get_option_keys(){
        return [
            'cid' => 'bcms_cid',
            'username' => 'bcms_username',
            'password' => 'bcms_password',
            'post_type' => 'bcms_posttype',
            'id_field' => 'bcms_id_field',
            'status' => 'bcms_taxonomy',
            'fields' => 'bcms_fields',
            'cron' => 'bcms_cron_schedule',
            'logging' => 'bcms_logging'
        ];
    }
       
    /**
     * Main function to setup settings page
     * fields are grouped in sections by the parent item
     * @return array (multidimensional)
     */

    public function get_settings_fields(){
        return array(
            [
                'id' => 'settings',
                'label' => 'BuilderCMS Settings',
                'fields'=> array(
                    [
                        'id' => 'bcms_cid',
                        'label' => 'BuilderCMS Community ID',
                        'type' => 'text',
                        'size' => '5',
                        'placeholder' => '9999',
                        //'below' => 'Enter BuilderCMS Community ID',
                    ],
                    [
                        'id' => 'bcms_username',
                        'label' => 'Username',
                        'type' => 'text',
                        'size' => '50',
                        'autocomplete'=>'off',
                        'placeholder' => 'username',
                        //'below' => ''
                    ],
                    [
                        'id' => 'bcms_password',
                        'label' => 'Password',
                        'type' => 'password',
                        'size' => '50',
                        'autocomplete'=>'new-password',
                        'placeholder' => get_option('bcms_password') ? 'Saved: leave blank to keep it' : 'password',
                        'sanitize' => array($this, 'sanitize_password'),
                        'below' => 'Stored encrypted. It is never shown again after saving.',
                    ],
                )
            ],
            [
                'id' => 'post_settings',
                'label' => 'Post Settings',
                'fields'=> array(
                    [
                        'id' => 'bcms_posttype',
                        'label' => 'Post Type',
                        'type' => 'select',
                        'options' => $this->get_post_types_list(),
                        'below' => 'The post-type to sync',
                    ],
                    [
                        'id' => 'bcms_taxonomy',
                        'label' => 'Status Taxonomy',
                        'type' => 'select',
                        'options' => $this->get_taxonomies_list(),
                        'below' => 'The category type used to set the status of each unit (this should be active for the above post type). Corresponds to "LotStatus" in BuilderCMS',

                    ],
                    [
                        'id' => 'bcms_id_field',
                        'label' => 'ID Field',
                        'type' => 'text',
                        'size' => '50',
                        'placeholder' => 'Lot',
                        'below' => 'This the external fieldname used to sync with local post slug',
                    ],
                )   
            ],
            [
                'id' => 'fields',
                'label' => 'BuilderCMS Field Mapping',
                'fields'=> array(
                    [
                        'id' => 'bcms_fields',
                        'label' => 'Map local fields to external fields.&#10;Separate field names with a colon, one per line.&#10;&#10;Example:&#10;local_field:external_field',
                        'type' => 'textarea',
                        'size' => '10',
                        'placeholder' => 'unit_number:Lot&#10;sqft:SqFt&#10;price:TotalPrice',
                    ],
                    
                )
            ],
            [
                'id' => 'plugin_options',
                'label' => 'Plugin Options',
                'fields'=> array(
                    [
                        'id' => 'bcms_cron_schedule',
                        'label' => 'Schedule Updates',
                        'type' => 'select',
                        'options' => array(
                            'none' => 'None',
                            'hourly' => 'Hourly',
                            'twicedaily' => 'Twice Daily',
                            'daily' => 'Daily',
                            'weekly' => 'Weekly',
                        ),
                        'below' => '<p>Last Sync Run: '. esc_html( get_option('bcms_last_sync_time') ).'</p>'
                    ],
                    [
                        'id' => 'bcms_logging',
                        'label' => 'Logging',
                        'type' => 'checkbox',
                        'options' => array(
                            'log_errors' => 'Log Events',
                            'log_all' => 'Log All (DEBUG)'
                        ),
                        'below' => 'Logging may contain private info and should only be used for debugging and testing'
                    ],
                )
            ],
            [
                'id' => 'plugin_actions',
                'label' => 'Sync Posts',
                'description' => '<a href="#" id="sync-posts-button" class="button">Sync Now</a> <div id="bcms-message"></div>',
            ],
            [
                'id' => 'sync_log',
                'label' => 'Sync Log',
                'description' => '<p>Kept for this site only. A manual sync starts a new log.</p><pre style="max-height:24em;overflow:auto;white-space:pre-wrap;">'
                    . esc_html( Plugin::get_instance()->get_log() ?: 'Empty' ) . '</pre>',
            ],
        );
    }
    
    /**
     * @return array of plugin settings
     */

    public function get_settings(){
        if (!empty($this->settings) ) {
            return $this->settings;
        } 
        
        $keys = $this->get_option_keys();
        foreach ($keys as $key => $option) {
            $value = get_option( $option ); 

            switch ($key){
                case 'password':
                    $password = self::decrypt_secret( is_string($value) ? $value : '' );
                    $this->password_unreadable = ($password === false);
                    $this->settings[$key] = ($password === false) ? '' : $password;
                    break;
                case 'fields': 
                    $this->settings[$key] = $this->parse_multiline_string( $value ); break;
                case 'logging':
                    $this->settings[$key] = is_array($value) ? count($value) : $value; break;
                default:
                    $this->settings[$key] = is_array($value) ? $value[0] : $value;
            }
        }
        
        return $this->settings;
    }

    /**
     * takes a multiline string with key/value pairs separated by a deliminator, with one pair per line
     * @return array
     */
    private function parse_multiline_string($string, $deliminator = ':'){

        // Explode the string into an array of lines
        $lines = explode("\n", $string);

        // Initialize an empty array to store key-value pairs
        $result = array();

        // Loop through each line and extract key-value pairs
        foreach ($lines as $line) {
            // Trim any leading or trailing whitespace
            $line = trim($line);

            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Split the line into key and value using a separator (default = ':')
            $parts = explode($deliminator, $line, 2);

            // Trim any leading or trailing whitespace from key and value
            $key = trim($parts[0]);
            $value = isset($parts[1]) ? trim($parts[1]) : '';

            // Add key-value pair to the result array
            $result[$key] = $value;
        }

        return $result;
    }
    

    public function get_terms_list()
	{
		$terms = get_terms([
			'taxonomy' => $this->settings['taxonomy'],
			'hide_empty' => true
		]);

		$terms_list = [];

		if (!empty($terms)) {
			foreach ($terms as $term) {
				$terms_list[$term->slug] = $term->name;
			}
		}

		return $terms_list;
	}


} // End of class