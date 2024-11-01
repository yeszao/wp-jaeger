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
use const OpenTracing\Formats\HTTP_HEADERS;
use const Jaeger\SAMPLER_TYPE_CONST;

class WordPressJaeger {
    private static $instance = null;
    private $tracer;
    private $requestSpan = null;

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
                    'param' => true,
                ],
                'local_agent' => [
                    'reporting_host' => YAEGER_REPORTING_HOST,
                    'reporting_port' => YAEGER_REPORTING_PORT,
                ],
                'logging' => true,
            ],
            YAEGER_SERVICE_NAME
        );
        $config->initializeTracer();

        $this->tracer = GlobalTracer::get();
        GlobalTracer::set($this->tracer);
    }

    private function addHooks() {
        // Track page load
        add_action('init', [$this, 'startRequestSpan']);
        add_action('shutdown', [$this, 'endRequestSpan']);

        // Track database queries with lower priority to ensure request span is created
        add_filter('query', [$this, 'trackDatabaseQuery'], 999);

        // Track template loading
        add_action('template_include', [$this, 'trackTemplateLoad']);

        // Track REST API requests
        add_action('rest_api_init', [$this, 'trackRestApiRequest']);
    }

    public function startRequestSpan() {
        try {
            // Try to extract context from headers, but don't fail if it's not possible
            $spanContext = null;
            try {
                $spanContext = $this->tracer->extract(
                    HTTP_HEADERS,
                    getallheaders() ?: []
                );
            } catch (Exception $e) {
                // Ignore extraction errors
            }

            // Always create a root span
            $this->requestSpan = $this->tracer->startSpan(
                'wordpress_request',
                $spanContext ? ['child_of' => $spanContext] : []
            );

            // Add tags to the request span
            if ($this->requestSpan) {
                $this->requestSpan->setTag('http.url', $_SERVER['REQUEST_URI'] ?? 'unknown');
                $this->requestSpan->setTag('http.method', $_SERVER['REQUEST_METHOD'] ?? 'unknown');
                $this->requestSpan->setTag(
                    'wordpress.user',
                    is_user_logged_in() ? wp_get_current_user()->user_login : 'anonymous'
                );
            }
        } catch (Exception $e) {
            error_log('Error starting request span: ' . $e->getMessage());
        }
    }

    public function endRequestSpan() {
        try {
            if ($this->requestSpan) {
                $this->requestSpan->finish();
            }
            $this->tracer->flush();
        } catch (Exception $e) {
            error_log('Error ending request span: ' . $e->getMessage());
        }
    }

    public function trackDatabaseQuery($query) {
        try {
            if (!$this->requestSpan) return $query;

            global $wpdb;
            $startTime = microtime(true);
            $result = $wpdb->query($query);
            $duration = microtime(true) - $startTime;

            // Only create a span if the query takes longer than a threshold
            if ($duration > 1) { // Adjust as needed
                $span = $this->tracer->startSpan('database_query', ['child_of' => $this->requestSpan]);
                $span->setTag('db.statement', $query);
                $span->setTag('db.type', 'mysql');
                $span->setTag('db.duration', $duration);
                register_shutdown_function(function() use ($span) {
                    try {
                        $span->finish();
                        $this->tracer->flush();
                    } catch (Exception $e) {
                        error_log('Error finishing dbQuery request span: ' . $e->getMessage());
                    }
                });
            }
        } catch (Exception $e) {
            error_log('Error tracking database query: ' . $e->getMessage());
        }
        return $query;
    }


    public function trackTemplateLoad($template) {
        try {
            // Only create a span if we have a valid request span
            if (!$this->requestSpan) {
                return $template;
            }

            $span = $this->tracer->startSpan(
                'template_load',
                ['child_of' => $this->requestSpan]
            );

            $span->setTag('template.path', $template);
            $span->setTag('template.name', basename($template));

            register_shutdown_function(function() use ($span) {
                try {
                    $span->finish();
                } catch (Exception $e) {
                    error_log('Error finishing template load span: ' . $e->getMessage());
                }
            });
        } catch (Exception $e) {
            error_log('Error tracking template load: ' . $e->getMessage());
        }

        return $template;
    }

    public function trackRestApiRequest() {
        try {
            // Only create a span if we have a valid request span
            if (!$this->requestSpan) {
                return;
            }

            $span = $this->tracer->startSpan(
                'rest_api_request',
                ['child_of' => $this->requestSpan]
            );

            $span->setTag('api.endpoint', rest_get_url_prefix() . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
            $span->setTag('api.method', $_SERVER['REQUEST_METHOD'] ?? 'unknown');

            register_shutdown_function(function() use ($span) {
                try {
                    $span->finish();
                } catch (Exception $e) {
                    error_log('Error finishing REST API request span: ' . $e->getMessage());
                }
            });
        } catch (Exception $e) {
            error_log('Error tracking REST API request: ' . $e->getMessage());
        }
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    WordPressJaeger::getInstance();
});