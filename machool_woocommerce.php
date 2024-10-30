<?php
/**
 * Plugin Name: Machool for WooCommerce
 * Plugin URI: https://app.machool.com/
 * Description: Connects WooCommerce to Machool to provide realtime shipping rates.
 * Version: 2.0.4
 * Author: Machool
 * Author URI: https://machool.com
 * Text Domain: machool
 * Domain Path: /i18n/languages/
 * Requires at least: 5.2
 * Requires PHP: 7.0
 *
 * @package Machool
 */
defined( 'ABSPATH' ) || exit;

if ( file_exists( dirname( __FILE__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __FILE__ ) . '/vendor/autoload.php';
}

/**
 * The code that runs during plugin activation
 */
function activate_machool_plugin(): void {
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'activate_machool_plugin' );

/**
 * The code that runs during plugin deactivation
 */
function deactivate_machool_plugin(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'deactivate_machool_plugin' );

/**
 * Initialize all the core classes of the plugin
 */
if ( class_exists( 'Inc\\Init' ) ) {
	Inc\Init::register_services();
}


include_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';

if(!function_exists('machool_shipping_method')){
    function machool_shipping_method(): void {
        if (!class_exists('Machool_Shipping_Method')) {
            class Machool_Shipping_Method extends WC_Shipping_Method
            {
                private string $plugin_version = '2.0.4';
                private $api_key;
                private $store_domain;
                private bool $api_key_valid = false;
                private string $api_base_url = 'https://api.machool.com/REST-app-services/';
                public function __construct($instance_id = 0)
                {
                    $this->id = 'machool_shipping';
                    $this->instance_id = absint($instance_id);
                    $this->title = __('Shipping with Machool', 'machool');
                    $this->method_title = $this->title;
                    $this->method_description = __('All enabled providers from your Machool account will be used to generate quotes during checkout.', 'machool');
                    $this->supports = array(
                        'settings',
                        'shipping-zones',
                        'instance-settings',
                        'instance-settings-modal',
                    );
                    $this->init();
                    $this->enabled = 'yes';
	                error_log('Machool Shipping Method Constructor called');
                }

	            /**
	             * Initializes the settings and sets up necessary actions and filters.
	             * This function loads the settings API, initializes form fields and settings,
	             * removes all admin notices, validates the API key, and sets up WooCommerce options update actions.
	             * It also handles the display of an error notice if the API key validation fails.
	             *
	             * @return void
	             */
                function init(): void {
                    // Load the settings API
                    $this->init_form_fields();
                    $this->init_settings();
					// remove all notices
	                remove_all_actions('admin_notices');

                    // Retrieve api key and store domain from settings
                    $this->api_key = array_key_exists('api_key', $this->settings) ? $this->settings['api_key'] : false;
                    $this->store_domain = array_key_exists('store_domain', $this->settings) ? $this->settings['store_domain'] : false;

                    $this->validateKey();

					// throw error message if validateKey fails.
					if(!$this->api_key_valid) {
						// Placed remove_all_actions here because we want to display the error message only once.
						remove_all_actions('admin_notices');
						if (!has_action('admin_notices', [$this, 'display_api_error_notice'])) {
							add_action('admin_notices', [$this, 'display_api_error_notice']);
						}
					}
	                add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
                }

	            /**
	             * Initializes the form fields for the plugin settings.
	             * This function sets up the form fields for the settings page if the instance_id is not set.
	             * It includes fields for the store domain and API key, both of which are required for the plugin's operation.
	             *
	             * @return void
	             */
                public function init_form_fields(): void {
                    if(!$this->instance_id) {
                    $this->form_fields = array(
                        'store_domain' => array(
                            'title' => __('Store Domain', 'machool'),
                            'type' => 'text',
                            'description' => __('Enter the domain of your store. This is used to identify your store when communicating with the Machool API.', 'machool'),
                            'placeholder' => 'example.com',
                            'custom_attributes' => [
                                'required' => true,
                            ],
                        ),
                        'api_key' => array(
                            'title' => __('API Key', 'machool'),
                            'type' => 'text',
                            'description' => __('Retrieve your API key from the Machool e-commerce portal', 'machool'),
                            'placeholder' => 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX',
                            'custom_attributes' => [
                                'required' => true,
                            ],
                        ),
                    );
                    }
                }

	            /**
	             * Retrieves shipping rates for a given package.
	             * This function checks if plugin is available for the given package and then
	             * retrieves rates using the Machool API. The rates are registered with WooCommerce.
	             *
	             * @param array $package The package details for which rates are to be fetched.
	             * @return array An array of rates for the given package.
	             */
	            public function get_rates_for_package($package = array()): array {
		            if ($this->is_available($package)) {
			            $this->rates = [];
			            $rates = $this->getMachoolRates($package);

			            // Register the rates with WooCommerce
			            if ($rates) {
				            foreach ($rates as $rate) {
					            $this->add_rate($rate);
				            }
			            }
		            }
		            return $this->rates;
	            }

	            /**
	             * Fetches shipping rates from the Machool API.(api/v1/rates/shopify)
	             * This function constructs a request with package details and sends it to the Machool API.
	             * It returns the rates obtained from the API response.
	             *
	             * @param array $package Package information used to fetch rates.
	             * @return array An array of rates returned by the Machool API.
	             */
	            private function getMachoolRates($package): array {
		            if (empty($package['destination']['postcode'])) {
			            return [];
		            }
		            $items = $this->prepareItems($package['contents']);
		            $request = $this->buildRequest($package, $items);
		            $apiResponse = $this->doAPICall("api/v1/rates", $request);
		            return $this->processApiResponse($apiResponse);
	            }

	            /**
	             * Prepares items for the API call.
	             * This function iterates over the contents of the package and prepares an array of items,
	             * including their weight, for the API call.
	             *
	             * @param array $contents The contents of the package.
	             * @return array An array of items with their respective weight and metric flag.
	             */
	            private function prepareItems($contents): array {
		            $items = [];
		            foreach ($contents as $itemArr) {
			            $item = $itemArr['data'];
						// we only need weights for API call
                        $itemWeight = (float)wc_get_weight($item->get_weight(), 'kg');
			            $itemData = [
                            'grams' => $itemWeight ? $itemWeight * 1000 : 0,
                            'quantity' => 1,
			            ];
			            $items = array_merge($items, array_fill(0, $itemArr['quantity'], $itemData));
		            }
		            return $items;
	            }

	            /**
	             * Builds a request array for the Machool API call to get rates.
	             * This function prepares the request body for the Machool API call,
	             * including account details, package origin and destination, items, currency, and locale.
	             *
	             * @param array $package The package information.
	             * @param array $items The items to be included in the rate request.
	             *
	             * @return array The request array to be sent to the Machool API.
	             */
				private function buildRequest(array $package, array $items):array {
					return [
						'accountNumber' => $this->store_domain,
						'apiToken' => $this->api_key,
						'rate' => [
							'apiToken' => $this->api_key,
							'accountNumber' => $this->store_domain,
							'origin'        => $this->getOrigin(),
							'destination'   => $this->getDestination($package['destination']),
							'items'         => $items,
							'currency' => get_woocommerce_currency(),
							'locale' => get_locale(),
						],
					];
				}

	            /**
	             * Constructs the destination array for the shipping request.
	             * This function formats the destination details into the required array structure for the API call.
	             *
	             * @param array $destination An array containing destination details like country, postal code, etc.
	             *
	             * @return array An array with formatted destination details.
	             */
				private function getDestination(array $destination): array {
					return [
						"country" => $destination['country'],
						"postal_code" => $destination['postcode'],
						"province" => $destination['state'],
						"city" => $destination['city'],
						"name" => "",
						"address1" => $destination['address_1'],
						"address2" => $destination['address_2'],
						"phone" => "",
						"fax" => "",
						"email"=> "",
						"address_type" => "",
						"company_name" => "",
					];
				}

	            /**
	             * Constructs the origin array for the shipping request.
	             * This function gathers the base location details from WooCommerce settings and formats them for the API call.
	             *
	             * @return array An array with formatted origin details.
	             */
				private function getOrigin(): array {
					return [
						"country" => WC()->countries->get_base_country(),
						"postal_code" => WC()->countries->get_base_postcode(),
						"province" => WC()->countries->get_base_state(),
						"city" => WC()->countries->get_base_city(),
						"name" => "",
						"address1" => WC()->countries->get_base_address(),
						"address2" => WC()->countries->get_base_address_2(),
						"phone" => "",
						"fax" => "",
						"email"=> "",
						"address_type" => "",
						"company_name" => "",
					];
				}

	            /**
	             * Processes the API response to extract and return shipping rates.
	             * This function takes the API response, extracts the rates, and sorts them by cost.
	             *
	             * @param object $apiResponse The response object from the API call.
	             * @return array An array of sorted shipping rates.
	             */
		        private function processApiResponse($apiResponse): array {
			        $returnedRates = [];

			        if (isset($apiResponse->rates) && is_array($apiResponse->rates)) {
				        foreach ($apiResponse->rates as $rate) {
					        $this->addRate($returnedRates, $rate);
				        }
			        }
					// return the rates sorted by cost
			        return $this->sortRatesByCost($returnedRates);
		        }

	            /**
	             * Adds a shipping rate to the array of returned rates.
	             * This function formats the rate details and adds them to the returned rates array.
	             *
	             * @param array &$returnedRates The array of rates to which the new rate will be added.
	             * @param object $rate The rate object to be added.
	             * @return void
	             */
		        private function addRate(&$returnedRates, $rate): void {
			        if (isset($rate->total_price) && $rate->total_price > 0) {
				        $returnedRates[] = [
					        'id'        => "machool_" . strtolower( str_replace( ' ', '_', $rate->service_name ) ),
					        'label'     => sprintf(
						        __( '%s (%s)', 'machool' ),
						        ucfirst( $rate->service_name ),
						        $this->getEstimatedDaysString( $rate->max_delivery_date ),
					        ),
					        'cost'      => $this->formatPrice( $rate->total_price ),
					        'taxes'     => false,
					        'meta_data' => [ 'version' => $this->plugin_version ]
				        ];
			        }
		        }

	            /**
	             * Checks if shipping is available for a given package.
	             * This function checks if the shipping method is enabled and applies any filters to determine availability.
	             *
	             * @param array $package The package details to check for shipping availability.
	             * @return bool Returns true if shipping is available, otherwise false.
	             */
	            public function is_available( $package ) {
		            $is_available = $this->is_enabled();
		            return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', $is_available, $package, $this );
	            }

	            /**
	             * Displays an error notice in the admin panel if the API key and Store domain are invalid.
	             * This function outputs an error message indicating an issue with the Machool API key and store domain.
	             *
	             * @return void
	             */
	            function display_api_error_notice():void {
		            echo '<div class="error"><p>' . __('Machool API key and Store domain is invalid. Please check your settings.', 'text-domain') . '</p></div>';
	            }

	            /**
	             * Sorts an array of shipping rates by cost.
	             * This function sorts the given rates array in ascending order of cost.
	             *
	             * @param array $rates The array of shipping rates to be sorted.
	             * @return array The sorted array of rates.
	             */
	            private function sortRatesByCost(array $rates): array {
					// sort rates by cost ascending order
		            usort($rates, function($a, $b) {
			            $aCost = $a['cost'];
			            $bCost = $b['cost'];
			            if ($aCost == $bCost) {
				            return 0;
			            }
			            return ($aCost < $bCost) ? -1 : 1;
		            });
		            return $rates;
	            }

	            /**
	             * Formats the price from cents to a decimal format.
	             * This function assumes the input price is in cents and converts it to a decimal format.
	             *
	             * @param mixed $price The price in cents to be formatted.
	             * @return float|int The formatted price.
	             * @throws Exception If the input price is not a numeric value.
	             */
		        function formatPrice($price): float {
			        if (!is_numeric($price)) {
				        // should never be trigger because we are using Machool API to get the rates.
				        // thus, will not be a problem for our users.
				        return $price;
			        }
			        return $price / 100;
		        }

	            /**
	             * This function interprets the format of time estimates from the API response
	             * and converts them into a standardized format.
	             *
	             * @param string $estimate The estimated shipping time.
	             * @return string A human-readable string representing the number of days for shipping.
	             */
		        private function getEstimatedDaysString($estimate): string {
			        // Default estimate in case of unknown format
			        $days = 2;

			        if (strtolower($estimate) === 'next day delivery' || strtolower($estimate) === 'next business day') {
				        $days = 1;
			        } elseif (preg_match('/(\d+)\s+business\s+days?/', strtolower($estimate), $matches)) {
				        // Extracts the number from strings like "2 business days"
				        if (isset($matches[1]) && is_numeric($matches[1])) {
					        $days = (int)$matches[1];
				        }
			        }
			        return sprintf(
				        _n(
					        '%d day',
					        '%d days',
					        $days,
					        'machool'
				        ),
				        $days
			        );
		        }

	            /**
	             * Validates the API key.
	             * This function checks if the provided API key is in the correct UUID format and validates it against the Machool API.
	             *
	             * @return void
	             */
	            private function validateKey(): void {
                    // make sure the api key is a UUID before we attempt to validate
                    if(preg_match('/[0-9a-f]{8}\b-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-\b[0-9a-f]{12}/', $this->api_key)){
                        $request = [
	                        'apiToken' => $this->api_key,
                            'accountNumber' => $this->store_domain,
                        ];
                        $apiResponse = $this->doAPICall("api/v1/tokens/validate", $request);

                        if ($apiResponse) {
                            if ($apiResponse->statusCode && $apiResponse->statusCode !== 200) {
                                $this->api_key_valid = false;
                            } else {
                                $this->api_key_valid = true;
                            }
                        };
                    }
                }

	            /**
	             * Performs an API call to a specified endpoint.
	             * This function sends a request to the Machool API and returns the response.
	             *
	             * @param string|null $endPoint The API endpoint to which the request is made.
	             * @param array|null $params The parameters for the request.
	             * @return mixed The response from the API call or false if the call fails.
	             */
                private function doAPICall($endPoint = false, $params = null) {
                    if (!$endPoint) return false;

                    $url = $this->api_base_url . $endPoint;
                    $params_json = $params ? wp_json_encode($params) : '';
                    $headers = array(
	                    'authorization' => $this->api_key,
	                    'token' => $this->api_key,
                    );
					$query = [
						"apiToken" => wp_json_encode($this->api_key),
						'accountNumber' => wp_json_encode($this->store_domain),
					];

                    if ($params) {
                        $headers['Content-Type'] = 'application/json';
                        $headers['Content-Length'] = strlen($params_json);
                    }

                    $options = [
                        'body'        => $params_json,
                        'headers'     => $headers,
	                    'query' 	 => $query,
                        'timeout'     => 30,
                        'redirection' => 1,
                        'blocking'    => true,
                        'httpversion' => '1.1',
                        'sslverify'   => false,
                        'data_format' => 'body',
                    ];
                    $response = wp_remote_post($url, $options);

                    if (!$response || is_wp_error($response)) {
                        // Setup error message
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            if (!$response) {
                                error_log('No response returned when connecting to: ' . $url);
                            } else {
                                error_log(print_r($response->get_error_message(), true));
                            }
                        }
                        return false;
                    } else {
                        // The API returns data in JSON format, so first convert that to an array of data objects
                        return json_decode($response['body']);
                    }
                }

	            /**
	             * Prevents cloning of instances of the singleton class.
	             * This function throws an error if an attempt is made to clone an instance of this class.
	             *
	             * @return void
	             * @throws Exception If an attempt is made to clone the instance.
	             */
                public function __clone()
                {
                    wc_doing_it_wrong(__FUNCTION__, __('Cloning is forbidden.', 'woocommerce'), '1.0');
                }

	            /**
	             * Prevents unserializing of instances of the singleton class.
	             * This function throws an error if an attempt is made to unserialize an instance of this class.
	             *
	             * @return void
	             * @throws Exception If an attempt is made to unserialize the instance.
	             */
                public function __wakeup()
                {
                    wc_doing_it_wrong(__FUNCTION__, __('Unserializing instances of this class is forbidden.', 'woocommerce'), '1.0');
                }
            }
        }
    }
}
    add_action( 'woocommerce_shipping_init', 'machool_shipping_method' );

    // Register Machool shipping method with woocommerce
    function add_Machool_shipping_method( $methods ) {
        $methods['machool_shipping'] = 'Machool_Shipping_Method';
	    error_log('Machool Shipping Method added');
        return $methods;
    }
    add_filter( 'woocommerce_shipping_methods', 'add_Machool_shipping_method' );

