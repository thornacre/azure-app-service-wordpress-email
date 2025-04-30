<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://github.com/Azure/Wordpress-on-Linux-App-Service-plugins/tree/main/app_service_email/
 * @since      1.0.0
 *
 * @package    App_service_email
 * @subpackage App_service_email/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Azure_app_service_migration
 * @subpackage Azure_app_service_migration/admin
 * @author     Microsoft <wordpressdev@microsoft.com>
 */
class Azure_app_service_email_controller
{

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param      string    $plugin_name       The name of this plugin.
     * @param      string    $version    The version of this plugin.
     */
    public function __construct($plugin_name, $version)
    {

        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */

    public function override_wp_mail_with_acs($args)
    {
        $maxRetries = 3;
        $retryCount = 0;

        // Extract email data from arguments

        $to = isset($args['to']) ? $args['to'] : '';
        $subject = isset($args['subject']) ? $args['subject'] : '';
        $message = isset($args['message']) ? $args['message'] : '';
        $emailHeaders = isset($args['headers']) ? $args['headers'] : '';
        $attachments = isset($args['attachments']) ? $args['attachments'] : '';

        do {
            $result = $this->acs_send_email($to, $subject, $message, $emailHeaders, $attachments);
            $retryCount++;
        } while (!$result && $retryCount < $maxRetries);

        return $result;
    }

    public function generate_request_body($to, $subject, $message, $senderaddress, $emailHeaders, $attachments)
    {
        $content_type = '';
        $reply_to = $cc = $bcc = [];
        $this->processHeaders($emailHeaders, $content_type, $reply_to, $cc, $bcc);

        if (!is_array($to)) {
            $to = explode(',', $to);
        }
        if (empty($content_type) || !isset($content_type)) {
            $content_type = (strpos($message, '<html') !== false || strpos($message, '<!DOCTYPE') !== false)
                ? 'text/html'
                : 'text/plain';
        } else {
            $content_type = (substr($content_type, 0, 10) == 'text/plain') ? 'text/plain' : 'text/html';
        }

        $toObjects = [];
        foreach ($to as $address) {
            $toObjects[] = ['address' => $address];
        }

        $replyToObjects = [];
        foreach ($reply_to as $address) {
            if (preg_match('/(.*)<(.+)>/', $address, $matches)) {
                if (count($matches) == 3) {
                    // If there are three elements in $matches, it means the pattern matched successfully
                    $replyToObjects[] = ['address' => $matches[2]];
                }
            } else {
                $replyToObjects[] = ['address' => $address];
            }
        }

        $ccObjects = [];
        foreach ($cc as $address) {
            $ccObjects[] = ['address' => $address];
        }

        $bccObjects = [];
        foreach ($bcc as $address) {
            $bccObjects[] = ['address' => $address];
        }

        $attachmentObject = [];
        // Ensure $attachments is an array
        $filePaths = is_array($attachments) ? $attachments : explode("\n", str_replace("\r\n", "\n", $attachments));
        foreach ($filePaths as $path) {
            if ($path[0] !== '/') {
                $path = ABSPATH . trim($path);
            }
            if (file_exists($path)) {
                $attachmentContent = base64_encode(file_get_contents(trim($path)));
                $attachmentObject[] = [
                    'name' => basename($path),
                    'contentType' => mime_content_type($path),
                    'contentInBase64' => $attachmentContent,
                ];
            }
        }

        return json_encode([
            'senderAddress' => $senderaddress,
            'content' => [
                'subject' => $subject,
                'plainText' => $message,
                'html' => ($content_type == 'text/html') ? $message : null
            ],
            'recipients' => [
                'to' => $toObjects,
                'cc' => $ccObjects,
                'bcc' => $bccObjects
            ],
            'replyTo' => $replyToObjects,
            'attachments' => $attachmentObject
        ]);
    }

    public function send_email_request($acsurl, $headers, $requestBody)
    {
        $args = [
            'headers' => $headers,
            'body' => $requestBody,
            'method' => 'POST'
        ];
        return wp_remote_post($acsurl, $args);
    }

    public function set_headers($dateStr, $hashedBodyStr, $acshost, $signature)
    {
        return [
            'Date' => $dateStr,
            'x-ms-content-sha256' => $hashedBodyStr,
            'Authorization' => 'HMAC-SHA256 SignedHeaders=date;host;x-ms-content-sha256&Signature=' . $signature,
            'Content-Type' => 'application/json'
        ];
    }

    public function set_entra_headers($dateStr, $key)
    {
        return [
            'Date' => $dateStr,
            'Authorization' => 'Bearer '. $key,
            'Content-Type' => 'application/json'
        ];
    }

    public function generate_string_to_sign($requestMethod, $pathWithQuery, $dateStr, $acshost, $hashedBodyStr, $key)
    {
        $stringToSign = $requestMethod . "\n" . $pathWithQuery . "\n" . $dateStr . ";" . $acshost . ";" . $hashedBodyStr;
        return base64_encode(hash_hmac('sha256', $stringToSign, $key, true));
    }

    public function acs_send_email($to, $subject, $message, $emailHeaders, $attachments)
    {
        require_once plugin_dir_path(dirname(__FILE__)) . '../admin/logger/class-azure_app_service_email-logger.php';
        $logemail = new Azure_app_service_email_logger();

        if (empty(getenv('WP_EMAIL_CONNECTION_STRING'))) {
            $error_msg = 'App Setting WP_EMAIL_CONNECTION_STRING is missing. <a href="https://github.com/Azure/wordpress-linux-appservice/blob/main/WordPress/wordpress_email_integration.md#:~:text=WP_EMAIL_CONNECTION_STRING">Click here</a> for more details.';
            do_action('wp_mail_failed', new WP_Error('acs_mail_failed', $error_msg));
            $logemail->email_logger_capture_emails($to, $subject, 'Failure', $error_msg);
            return false;
        }

        $appSetting = getenv('WP_EMAIL_CONNECTION_STRING');
        $pattern = '/endpoint=(.*?);senderaddress=(.*?);accesskey=(.*)$/';
        $patternWithoutAK = '/endpoint=(.*?);senderaddress=(.*?)$/';

        if (preg_match($pattern, $appSetting, $matches) || preg_match($patternWithoutAK, $appSetting, $matches)) {
            $acsurl = $matches[1];
            $senderaddress = $matches[2];
            $apikey = '';

            if (count($matches) == 4) {
                $apikey = $matches[3];
            }

            if (strtolower(getenv('ENABLE_EMAIL_MANAGED_IDENTITY')) == 'true') {
                try {
                    require_once plugin_dir_path(__FILE__) . 'class_entra_email_token_utility.php';
                    if (strtolower(getenv('CACHE_EMAIL_ACCESS_TOKEN')) !== 'true') {
                        $apikey = EntraID_Email_Token_Utilities::getAccessToken();
                    } else {
                        $apikey = EntraID_Email_Token_Utilities::getOrUpdateAccessTokenFromCache();
                    }
                } catch (Exception $e) {
                    $error_message = 'Unable to retrieve access token for Email service using Managed Identity! ' . $e->getMessage();
                    $wp_error = new WP_Error('acs_mail_failed', $error_message);
                    $logemail->email_logger_capture_emails($to, $subject, 'Failure', $error_message);
                    do_action('wp_mail_failed', $wp_error);
                    return false;
                }
            }

        } else {
            $error_message = 'App Setting WP_EMAIL_CONNECTION_STRING is not in the right format. <a href="https://github.com/Azure/wordpress-linux-appservice/blob/main/WordPress/wordpress_email_integration.md#:~:text=WP_EMAIL_CONNECTION_STRING">Click here</a> for more details.';
            $wp_error = new WP_Error('acs_mail_failed', $error_message);
            $logemail->email_logger_capture_emails($to, $subject, 'Failure', $error_message);
            do_action('wp_mail_failed', $wp_error);
            return false;
        }

        if (substr($acsurl, -1) === '/') {
            // Remove the trailing slash
            $acsurl = rtrim($acsurl, '/');
        }

        $acshost = str_replace('https://', '', $acsurl);
        $pathWithQuery = '/emails:send?api-version=2023-01-15-preview';
        $requestBody = $this->generate_request_body($to, $subject, $message, $senderaddress, $emailHeaders, $attachments);
        $hashedBodyStr = base64_encode(hash('sha256', $requestBody, true));
        $requestMethod = 'POST';
        $dateStr = gmdate('D, d M Y H:i:s \G\M\T');

        $key = base64_decode($apikey);
        $signature = $this->generate_string_to_sign($requestMethod, $pathWithQuery, $dateStr, $acshost, $hashedBodyStr, $key);
        $headers = $this->set_headers($dateStr, $hashedBodyStr, $acshost, $signature);

        if (strtolower(getenv('ENABLE_EMAIL_MANAGED_IDENTITY')) == 'true') {
            $headers = $this->set_entra_headers($dateStr, $apikey);
        }

        $acsurl = "https://" . $acshost . $pathWithQuery;
        try {
            $response = $this->send_email_request($acsurl, $headers, $requestBody);
            if (is_wp_error($response)) {
                $message = $response->get_error_message() . '<a href="https://learn.microsoft.com/en-us/azure/communication-services/support">Click here</a> for more support.';
                do_action('wp_mail_failed', new WP_Error('acs_mail_failed', $message));
                $logemail->email_logger_capture_emails($to, $subject, 'Failure', $message);
                return false;
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                $response_array = json_decode(wp_json_encode($response), true);
                $body_array = json_decode($response_array['body'], true);
                $status = $body_array['status'];
                if ($response_code === 200 || ($response_code === 202 && $status === 'Running')) {
                    $logemail->email_logger_capture_emails($to, $subject, 'Success', '');
                    return true;
                } else {
                    $error_array = $body_array['error'];
                    $message = $error_array['message'] . '<a href="https://learn.microsoft.com/en-us/azure/communication-services/support">Click here</a> for more support.';
                    do_action('wp_mail_failed', new WP_Error('acs_mail_failed', $message));
                    $logemail->email_logger_capture_emails($to, $subject, 'Failure', $message);
                    return false;
                }
            }
        } catch (Exception $e) {
            $message = $e->getMessage() . '<a href="https://learn.microsoft.com/en-us/azure/communication-services/support">Click here</a> for more support.';
            do_action('wp_mail_failed', new WP_Error('acs_mail_failed', 'An Error Occured: ' . $message));
            $logemail->email_logger_capture_emails($to, $subject, 'Failure', 'An Error Occured: ' . $message);
            return false;
        }
    }

    public function processHeaders($emailHeaders, &$content_type, &$reply_to, &$cc, &$bcc)
    {
        if (empty($emailHeaders)) {
            $emailHeaders = array();
        } else {

            if (!is_array($emailHeaders)) {
                /*
                 * Explode the headers out, so this function can take
                 * both string headers and an array of headers.
                 */
                $tempheaders = explode("\n", str_replace("\r\n", "\n", $emailHeaders));
            } else {
                $tempheaders = $emailHeaders;
            }
            $emailHeaders = array();

            // If it's actually got contents.
            if (!empty($tempheaders)) {
                // Iterate through the raw headers.
                foreach ((array) $tempheaders as $header) {
                    if (!str_contains($header, ':')) {
                        continue;
                    }
                    // Explode them out.
                    list($name, $content) = explode(':', trim($header), 2);

                    // Cleanup crew.
                    $name    = trim($name);
                    $content = trim($content);
                    switch (strtolower($name)) {
                            // Mainly for legacy -- process a "From:" header if it's there.
                        case 'content-type':
                            $this->processContentTypeHeader($content, $content_type);
                            break;
                        case 'reply-to':
                            $reply_to = array_merge((array) $reply_to, explode(',', $content));
                            break;
                        case 'cc':
                            $cc = array_merge((array) $cc, explode(',', $content));
                            break;
                        case 'bcc':
                            $bcc = array_merge((array) $bcc, explode(',', $content));
                            break;
                        default:
                            // Add it to our grand headers array.
                            $headers[trim($name)] = trim($content);
                            break;
                    }
                }
            }
        }
    }

    private function processContentTypeHeader($content, &$content_type)
    {
        if (str_contains($content, ';')) {
            list($type,) = explode(';', $content);
            $content_type = trim($type);
        } elseif ('' !== trim($content)) {
            $content_type = trim($content);
        }
    }
}