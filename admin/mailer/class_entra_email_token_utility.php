<?php
if ( ! defined( 'ABSPATH' ) ) {
	die;
}
/**
 * 
 * This class defines utility functions for retrieving auth token for Azure Communication Email Service.
 * @author  Zubair <zmohammed@microsoft.com>
 */
class EntraID_Email_Token_Utilities {

    
    /**
     * This function retrieves the access token from the identity endpoint.
     * @return string $access_token
     * @throws RuntimeException
     */
    public static function getAccessToken() {
        // Get environment variables
        $identity_header = getenv('IDENTITY_HEADER');
        $identity_endpoint = getenv('IDENTITY_ENDPOINT');
        $identity_resource_url = getenv('EMAIL_IDENTITY_RESOURCE_URL') ?: "https://communication.azure.com";
        $entraid_api_version = getenv('ENTRAID_API_VERSION') ?: "2019-08-01";
        $entra_client_id = getenv('ENTRA_CLIENT_ID');

         // Validate environment variables
        if (empty($identity_endpoint) || empty($identity_header) || empty($entra_client_id)) {
            throw new RuntimeException("Error: getAccessToken - missing required environment variables.");
        }

        // Construct URL for the request
        $url = add_query_arg([
            'api-version' => $entraid_api_version,
            'resource' => $identity_resource_url,
            'client_id' => $entra_client_id,
        ], $identity_endpoint);

        // Set up the request headers
        $args = [
            'headers' => [
                'X-IDENTITY-HEADER' => $identity_header,
            ],
            'sslverify' => true,
        ];

        // Execute the request
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            throw new RuntimeException("Error: getAccessToken - HTTP request failed: " . $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code != 200) {
            throw new RuntimeException("Error: getAccessToken - HTTP request failed with status code $http_code");
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            throw new RuntimeException("Error: getAccessToken - invalid response data.");
        }

        // Parse JSON response and extract access_token
        $json_response = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Error: getAccessToken - failed to parse the JSON response: " . json_last_error_msg());
        }
        if (!isset($json_response['access_token'])) {
            throw new RuntimeException("Error: getAccessToken - no token found in response data.");
        }

        return $json_response['access_token'];
    }

    /**
     * This function retrieves the access token from the cache if it is not expired.
     * If the token is expired or near to expiration, it retrieves a new token.
     * @return string $auth_token
     * @throws RuntimeException
     */
    public static function getOrUpdateAccessTokenFromCache() {
        // Check if a session is already started
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        // Check if the token is empty and call getAccessToken to get a new token
        if (!isset($_SESSION['email_entra_token']) || empty($_SESSION['email_entra_token'])) {
            $auth_token = self::getAccessToken();
            // Check if $auth_token is empty or null string
            if (empty($auth_token)) {
                throw new RuntimeException("Error: getOrUpdateAccessTokenFromCache - failed to get new token");
            }
            $_SESSION['email_entra_token'] = $auth_token;
            return $auth_token;
        }

        // Decode the token and get the expiration time
        $token_parts = explode('.', $_SESSION['email_entra_token']);
        if (count($token_parts) < 2) {
            throw new RuntimeException("Error: getOrUpdateAccessTokenFromCache - failed to decode token");
        }
        $token_payload = json_decode(base64_decode($token_parts[1]), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Error: getOrUpdateAccessTokenFromCache - failed to decode token payload: " . json_last_error_msg());
        }
        if (!isset($token_payload['exp']) || !is_numeric($token_payload['exp'])) {
            throw new RuntimeException("Error: getOrUpdateAccessTokenFromCache - invalid token expiration time");
        }

        //Both are Unix timestamps
        $exp = $token_payload['exp'];
        $current_time = time();
        $time_to_expire = $exp - $current_time;

        // If token is expired or near to expiration (5 mins to expiration), call getAccessToken
        if ($time_to_expire < 300) {
            $auth_token = self::getAccessToken();
            if (empty($auth_token)) {
                throw new RuntimeException("Error: getOrUpdateAccessTokenFromCache - failed to refresh token");
            }
            $_SESSION['email_entra_token'] = $auth_token;
        }

        return $_SESSION['email_entra_token'];
    }
}
