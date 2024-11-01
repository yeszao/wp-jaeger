<?php
/**
 * Plugin Name: WP Jaeger
 * Description: A standalone WordPress plugin integrating Jaeger tracing.
 * Version: 0.0.1
 * Author: Gary Meng
 * Author URI: https://garymeng.com
 */

require_once __DIR__ . '/vendor/autoload.php';

const YAEGER_SERVICE_NAME = 'wordpress-app';
const YAEGER_REPORTING_HOST = '192.168.1.110';
const YAEGER_REPORTING_PORT = 6831;

use Jaeger\Config;
use OpenTracing\GlobalTracer;
use OpenTracing\Formats;
use const Jaeger\SAMPLER_TYPE_CONST;

class WordPressJaeger {
    private static $instance = null;
    private $tracer;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->initJaeger();
        $this->addHooks();
    }

    private function initJaeger() {
        $config = new Config(
            [
                'sampler' => [
                    'type' => SAMPLER_TYPE_CONST,
                    'param' => 1,
                ],
                'local_agent' => [
                    'reporting_host' => YAEGER_REPORTING_HOST,
                    'reporting_port' => YAEGER_REPORTING_PORT,
                ],
                'logging' => true,
            ],
            YAEGER_SERVICE_NAME
        );

        $this->tracer = $config->initTracer();
        GlobalTracer::set($this->tracer);
    }

    private function addHooks() {
        // Track page load
        add_action('init', [$this, 'startRequestSpan']);
        add_action('shutdown', [$this, 'endRequestSpan']);

        // Track database queries
        add_filter('query', [$this, 'trackDatabaseQuery']);

        // Track template loading
        add_action('template_include', [$this, 'trackTemplateLoad']);

        // Track REST API requests
        add_action('rest_api_init', [$this, 'trackRestApiRequest']);
    }

    private $requestSpan = null;

    public function startRequestSpan() {
        $spanContext = $this->tracer->extract(
            Formats::HTTP_HEADERS,
            getallheaders()
        );

        $this->requestSpan = $this->tracer->startSpan(
            'wordpress_request',
            [
                'child_of' => $spanContext,
                'tags' => [
                    'http.url' => $_SERVER['REQUEST_URI'],
                    'http.method' => $_SERVER['REQUEST_METHOD'],
                    'wordpress.user' => is_user_logged_in() ? wp_get_current_user()->user_login : 'anonymous'
                ]
            ]
        );
    }

    public function endRequestSpan() {
        if ($this->requestSpan) {
            $this->requestSpan->finish();
        }
        $this->tracer->flush();
    }

    public function trackDatabaseQuery($query) {
        $span = $this->tracer->startSpan(
            'database_query',
            ['child_of' => $this->requestSpan]
        );

        $span->setTag('db.statement', $query);
        $span->setTag('db.type', 'mysql');

        // Execute query
        global $wpdb;
        $result = $wpdb->query($query);

        $span->setTag('db.rows_affected', $wpdb->rows_affected);
        $span->finish();

        return $query;
    }

    public function trackTemplateLoad($template) {
        $span = $this->tracer->startSpan(
            'template_load',
            ['child_of' => $this->requestSpan]
        );

        $span->setTag('template.path', $template);
        $span->setTag('template.name', basename($template));

        register_shutdown_function(function() use ($span) {
            $span->finish();
        });

        return $template;
    }

    public function trackRestApiRequest() {
        $span = $this->tracer->startSpan(
            'rest_api_request',
            ['child_of' => $this->requestSpan]
        );

        $span->setTag('api.endpoint', rest_get_url_prefix() . $_SERVER['REQUEST_URI']);
        $span->setTag('api.method', $_SERVER['REQUEST_METHOD']);

        register_shutdown_function(function() use ($span) {
            $span->finish();
        });
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    WordPressJaeger::getInstance();
});