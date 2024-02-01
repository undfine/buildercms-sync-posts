<?php
namespace BCMS;

if (!defined('ABSPATH')) {
    exit;
}

class BuildercmsApi
{
    private $base_url;
    private $cid;
    private $username;
    private $password;
    private $id_field = 'Lot';
    private $fields;
    private $post_type;
    private $status_taxonomy;

    public function __construct($settings)
    {

        $this->base_url = 'https://buildercms.com/cms/CmsService.svc';
        $this->cid = $settings['cid'];
        $this->username = $settings['username'];
        $this->password = $settings['password'];
        $this->id_field = $settings['id_field'];
        $this->post_type = $settings['post_type'];
        $this->fields = $settings['fields'];
        $this->status_taxonomy = $settings['status'];

        // Uncomment to view how settings are stored
        // $this->log_to_file($settings, 'Settings:');

        add_filter('bcms_get_local_post_by_external_id', [$this, 'filter_external_id']);
    }

    /**
     * localize the log function for convenience
     * @param mixed string/array/object $message
     * @param string $type 
     */
    private function log_to_file($message, $type = 'Error')
    {
        Plugin::get_instance()->log_to_file($message, $type);
    }

    /**
     * The main http request function
     * @param string $endpoint  The specific endpoint of the API request
     * @param string $method    Default is POST, but maybe in the future there will be other options
     * @param array $data       Additional Data to pass to the body (eg. when using a PUT or PATCH request)
     */
    private function api_request($endpoint, $method = 'POST', $data = array())
    {
        $url = $this->base_url . '/' . $endpoint;

        // Credentials are sent in the body, and not in the headers

        $args = array(
            'headers' => [
                'content-type: application/json',
            ],
            'method' => $method,
            'blocking' => true, // TO DO: fix non-blocking to improve Ajax and CRON events
            'sslverify' => true, // used when local testing
        );

        // Format the body if not using GET, ie. POST, PATCH,...
        if ($method !== 'GET') {
            // Include credentials in the request body
            $data['CommunityId'] = $this->cid;
            $data['UserName'] = $this->username;
            $data['Password'] = $this->password;

            $args['body'] = json_encode(array($data));
        }

        $response = wp_remote_request($url, $args);

        // response is only logged if "log_all" is enabled 
        $this->log_to_file( wp_remote_retrieve_headers($response), 'Response Headers:');

        if (is_wp_error($response) || !isset($response['body'])) {
            // Handle error
            $error = 'API Request Error: ' . $response->get_error_message();
            $this->log_to_file($error);
            error_log($error);
            return false;
        } else {
            //trim possible "U+FEFF" BOM (Byte Order Mark) from the beginning of body 
            $body = ltrim($response['body'], "\xEF\xBB\xBF");
            //return an associative array, instead of object
            return json_decode($body, true);
        }
    }

    public function sync_posts_from_api()
    {

        $endpoint = 'UnitDataFeed'; // Adjust the endpoint based on the API's requirements

        $external_posts = $this->api_request($endpoint);

        $this->log_to_file($external_posts, 'External Posts');

        if (!$external_posts || !is_array($external_posts)) {
            $message = "Error retrieving $endpoint from External API.";
            // Handle error
            $this->log_to_file($message);
            error_log($message);
            return false;
        }

        // Start an empty array to store changed items write to the log
        $this->log_to_file('Sync Started');
        $log = [];

        foreach ($external_posts as $external_post) {

            $key = strval($this->id_field);
            if (!isset($external_post[$key]) || empty($external_post[$key])) {
                $this->log_to_file("No matching field ['$key'] in external post", 'Error');
                return false;
            }

            // Check if the post already exists in WordPress
            $local_post = $this->get_local_post($external_post[$key]);

            // 'LotStatus' is a status field in BuilderCMS
            $status_term = isset($external_post['LotStatus']) ? $external_post['LotStatus'] : '';

            $result = [];
            if (is_object($local_post)) {


                // update the post meta fields
                $result = $this->update_post_content($local_post->ID, $external_post);
                $status = $this->update_post_status($local_post->ID, $status_term);

                array_merge($result, $status);
                //remove any empty elements
                $result = array_filter($result);

                // Add the post identifiers
                if (!empty($result)) {
                    $new['RESULT'] = "POST '$local_post->post_name' UPDATED ";
                    $log[$local_post->ID] = array_merge($new, $result);
                }

            } else {

                $post_name = strval($external_post[$key]);

                $new['RESULT'] = "NEW POST '$post_name' CREATED ";

                // Post doesn't exist, create it
                $new_post_id = wp_insert_post(
                    array(
                        'ID' => 0,
                        'post_status' => 'publish',
                        'post_type' => $this->post_type,
                        'post_title' => $post_name,
                        //'post_name'    => strval($local_post) // use the returned filtered value                    
                    )
                );

                //make sure we have an int for the ID
                $new_post_id = is_object($new_post_id) ? $new_post_id->ID : intval($new_post_id);

                // update the post meta fields
                $result = $this->update_post_content($new_post_id, $external_post);
                $status = $this->update_post_status($new_post_id, $status_term, true);

                $log[$new_post_id] = array_merge($new, $result, $status);

            }

        }
        if (!empty($log)) {
            $this->log_to_file($log);
            $this->log_to_file('Sync completed successfully!');
        } else {
            $log = 'Sync completed, with no new updates';
            $this->log_to_file($log);
        }

        return true;
    }

    /**
     * @param int $post_id
     * @param string $status_term
     * @param boolean (optional) $new_post
     * @return array the new status of the post
     */
    private function update_post_status($post_id, $status_term, $new_post = false)
    {

        if (!$new_post) {
            // get current terms
            $post_terms = wp_get_post_terms($post_id, $this->status_taxonomy);

            // If term already exists, do not update
            if (strpos(json_encode($post_terms), $status_term)) {
                return [];
            }
        }

        wp_set_post_terms(
            $post_id,
            $status_term,
            $this->status_taxonomy
        );

        $result[strval($this->status_taxonomy)] = $status_term;

        // Log the update
        // return $this->log_to_file("Post (ID: $local_post->ID '$local_post->post_name') Status updated: '$status_term'");
        return $result;

    }

    /**
     * @param int $post_id
     * @param array $external_post (returned from the API)
     * @return array the new status of the post
     */
    private function update_post_content($post_id, $external_post)
    {
        $result = [];
        foreach ($this->fields as $local_key => $external_key) {

            $prev = get_post_meta($post_id, $local_key, true);
            $new = isset($external_post[strval($external_key)]) ? $external_post[strval($external_key)] : null;

            // check for new value
            if ($prev != $new) {
                //update the meta with the new key and store the values for logging
                update_post_meta($post_id, $local_key, $new);
                $result["$local_key"] = "updated from '$prev' to '$new'";
            }
        }

        return $result;
    }

    /**
     * @param array $external_post
     * @return object
     */
    private function get_local_post($external_id)
    {

        // Allow custom filtering
        $external_id = apply_filters('bcms_get_local_post_by_external_id', $external_id);

        //$this->log_to_file("Searching for post with slug: '$external_id'");

        $local_post = get_page_by_path($external_id, 'OBJECT', $this->post_type);

        return $local_post;
    }

    /**
     * filters the external id for specific cases, such as when it is prefixed
     * @return string
     */
    public function filter_external_id($id)
    {

        $id = strtolower($id);
        //replace any spaces and underscores with a dash (used in slug)
        if (strpos($id, 'ph') > -1) {
            $id = str_replace([' ', '_',], '-', $id);
        }
        return $id;
    }


}
