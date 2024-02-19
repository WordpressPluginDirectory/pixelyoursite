<?php

namespace PixelYourSite;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/** @noinspection PhpIncludeInspection */
require_once PYS_FREE_PATH . '/modules/google_analytics/function-helpers.php';
require_once PYS_FREE_PATH . '/modules/google_analytics/function-collect-data-4v.php';


class GA extends Settings implements Pixel {
	
	private static $_instance;
	
	private $configured;
	
	public static function instance() {
		
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		
		return self::$_instance;
		
	}
	
    public function __construct() {
		
        parent::__construct( 'ga' );
        
	    $this->locateOptions(
		    PYS_FREE_PATH . '/modules/google_analytics/options_fields.json',
		    PYS_FREE_PATH . '/modules/google_analytics/options_defaults.json'
	    );
	    
	    add_action( 'pys_register_pixels', function( $core ) {
		    /** @var PYS $core */
		    $core->registerPixel( $this );
	    } );
    }


	
	public function enabled() {
		return $this->getOption( 'enabled' );
	}
	
	public function configured() {
		
		if ( $this->configured === null ) {
			
			$tracking_id = $this->getPixelIDs() ;
			$this->configured = $this->enabled()
                                && count( $tracking_id ) > 0
                                && !empty($tracking_id[0])
			                    && ! apply_filters( 'pys_pixel_disabled', false, $this->getSlug() );
			
		}
		
		return $this->configured;
		
	}

    public function getPixelDebugMode() {

        $flags = (array) $this->getOption( 'is_enable_debug_mode' );

        if ( isSuperPackActive() && SuperPack()->getOption( 'enabled' ) && SuperPack()->getOption( 'additional_ids_enabled' ) ) {
            $additionalPixels = SuperPack()->getGaAdditionalPixel();
            $index = 1;
            foreach ($additionalPixels as $_pixel) {
                if($_pixel->extensions['debug_mode'])
                {
                    $flags[] = 'index_'.$index;
                }
                $index++;
            }
            return $flags;
        } else {
            return (array) reset( $flags ); // return first id only
        }
    }

	public function getPixelIDs() {

		$ids = (array) $this->getOption( 'tracking_id' );
        if(count($ids) == 0) {
            return apply_filters("pys_ga_ids",[]);
        } else {
			$id = array_shift($ids);
			return apply_filters("pys_ga_ids", array($id)); // return first id only
        }
	}

    public function getPixelOptions()
    {

        return array(
            'trackingIds' => $this->getPixelIDs(),
            'commentEventEnabled' => $this->getOption('comment_event_enabled'),
            'downloadEnabled' => $this->getOption('download_event_enabled'),
            'formEventEnabled' => $this->getOption('form_event_enabled'),
            'crossDomainEnabled' => $this->getOption('cross_domain_enabled'),
            'crossDomainAcceptIncoming' => $this->getOption('cross_domain_accept_incoming'),
            'crossDomainDomains' => $this->getOption('cross_domain_domains'),
            'isDebugEnabled'                => $this->getPixelDebugMode(),
            'disableAdvertisingFeatures'    => $this->getOption( 'disable_advertising_features' ),
            'disableAdvertisingPersonalization' => $this->getOption( 'disable_advertising_personalization' ),
            'wooVariableAsSimple' => $this->getOption( 'woo_variable_as_simple' )
        );
    }
    private function isGaV4($tag) {
        return strpos($tag, 'G') === 0;
    }
    /**
     * Create pixel event and fill it
     * @param SingleEvent $event
     * @return SingleEvent[]
     */
    public function generateEvents($event) {
        $pixelEvents = [];
        if ( ! $this->configured() ) {
            return [];
        }

        //$onlyGA4event = ['woo_view_cart'];
        //$isGA4Event = in_array($event->getId(), $onlyGA4event);
        $pixelIds = array_filter($this->getPixelIDs(), static function ($tag) {
            return strpos($tag, 'UA-') === false;
        });

        if(count($pixelIds) > 0) {
            $pixelEvent = clone $event;
            if($this->addParamsToEvent($pixelEvent)) {
                $pixelEvent->addPayload([ 'trackingIds' => $pixelIds ]);
                $pixelEvents[] = $pixelEvent;
            }
        }

        return $pixelEvents;
    }

    //refactor it
    private function addDataToEvent($eventData,&$event) {
        $params = $eventData["data"];
        unset($eventData["data"]);
        //unset($eventData["name"]);
        $event->addParams($params);
        $event->addPayload($eventData);
    }
    public function addParamsToEvent(&$event)
    {
        if (!$this->configured()) {
            return false;
        }
        $isActive = false;
        switch ($event->getId()) {
        //Automatic events

            case 'automatic_event_signup' : {
                $event->addPayload(["name" => "sign_up"]);
                $isActive = $this->getOption($event->getId().'_enabled');
            } break;
            case 'automatic_event_login' :{
                $event->addPayload(["name" => "login"]);
                $isActive = $this->getOption($event->getId().'_enabled');
            } break;
            case 'automatic_event_search' :{
                $event->addPayload(["name" => "search"]);
                $event->addParams([
                    "search_term"       =>  empty( $_GET['s'] ) ? null : $_GET['s'],
                ]);
                $isActive = $this->getOption($event->getId().'_enabled');
            } break;

            case 'automatic_event_form' :
            case 'automatic_event_download' :
            case 'automatic_event_comment' :
            case 'automatic_event_scroll' :
            case 'automatic_event_time_on_page' : {
                $isActive = $this->getOption($event->getId().'_enabled');
            }break;


            case 'init_event': {
                    $eventData = $this->getPageViewEventParams();
                    if ($eventData) {
                        $isActive = true;
                        $this->addDataToEvent($eventData, $event);
                    }
            } break;


            case 'custom_event': {
                $eventData = $this->getCustomEventData($event->args);
                if ($eventData) {
                    $isActive = true;
                    $this->addDataToEvent($eventData, $event);
                }
            }break;
            case 'woo_view_content': {
                $eventData =  $this->getWooViewContentEventParams();
                if ($eventData) {
                    $isActive = true;
                    $this->addDataToEvent($eventData, $event);
                }
            }break;
            case 'woo_view_cart': {
                $isActive =  $this->getWooViewCartEventParams($event);
            }break;
            case 'woo_add_to_cart_on_cart_page':
            case 'woo_add_to_cart_on_checkout_page':{
                $eventData =  $this->getWooAddToCartOnCartEventParams();
                if ($eventData) {
                    $isActive = true;
                    $this->addDataToEvent($eventData, $event);
                }
            }break;
            case 'woo_remove_from_cart':{
                $isActive =  $this->getWooRemoveFromCartParams( $event );

            }break;
            case 'woo_initiate_checkout':{
                $eventData =  $this->getWooInitiateCheckoutEventParams();
                if ($eventData) {
                    $isActive = true;
                    $this->addDataToEvent($eventData, $event);
                }
            }break;
            case 'woo_purchase':{
                $eventData =  $this->getWooPurchaseEventParams();
                if ($eventData) {
                    $isActive = true;
                    $this->addDataToEvent($eventData, $event);
                }
            }break;
            /*case 'woo_view_item_list':
                {
                    $eventData = $this->getWooViewCategoryEventParams();
                    if ($eventData) {
                        $isActive = true;
                        $this->addDataToEvent($eventData, $event);
                    }
                }break;*/
            case 'edd_view_content': {
                $eventData = $this->getEddViewContentEventParams();
                if ($eventData) {
                    $isActive = true;
                    $this->addDataToEvent($eventData, $event);
                }
            }break;
            case 'edd_add_to_cart_on_checkout_page':  {
                $eventData = $this->getEddCartEventParams('add_to_cart');
                if ($eventData) {
                    $isActive = true;
                    $this->addDataToEvent($eventData, $event);
                }
            }break;
            case 'edd_remove_from_cart': {
                $eventData =  $this->getEddRemoveFromCartParams( $event->args['item'] );
                if ($eventData) {
                    $isActive = true;
                    $this->addDataToEvent($eventData, $event);
                }
            }break;

            /*case 'edd_view_category': {
                $eventData = $this->getEddViewCategoryEventParams();
                if ($eventData) {
                    $isActive = true;
                    $this->addDataToEvent($eventData, $event);
                }
            }break;*/

            case 'edd_initiate_checkout': {
                $eventData = $this->getEddCartEventParams('begin_checkout');
                if ($eventData) {
                    $isActive = true;
                    $this->addDataToEvent($eventData, $event);
                }
            }break;

            case 'edd_purchase': {
                $eventData = $this->getEddCartEventParams('purchase');
                if ($eventData) {
                    $isActive = true;
                    $this->addDataToEvent($eventData, $event);
                }
            }break;

            case 'woo_add_to_cart_on_button_click': {
                if (  $this->getOption( 'woo_add_to_cart_enabled' ) && PYS()->getOption( 'woo_add_to_cart_on_button_click' ) ) {
                    $isActive = true;
                    if(isset($event->args['productId'])) {
                        $eventData =  $this->getWooAddToCartOnButtonClickEventParams( $event );

                        if($eventData) {
                            $event->addParams($eventData["params"]);
                            unset($eventData["params"]);
                            $event->addPayload($eventData);
                        }
                    }
                    $event->addPayload(array(
                        'name'=>"add_to_cart"
                    ));
                }
            }break;

            case 'edd_add_to_cart_on_button_click': {
                if (  $this->getOption( 'edd_add_to_cart_enabled' ) && PYS()->getOption( 'edd_add_to_cart_on_button_click' ) ) {
                    $isActive = true;
                    if($event->args != null) {
                        $eventData =  $this->getEddAddToCartOnButtonClickEventParams( $event->args );
                        $event->addParams($eventData);
                    }
                    $event->addPayload(array(
                        'name'=>"add_to_cart"
                    ));
                }
            }break;
        }

        return $isActive;
    }


	public function getEventData( $eventType, $args = null ) {

        return false;
	}
	
	public function outputNoScriptEvents() {
	 
		if ( ! $this->configured() || $this->getOption('disable_noscript')) {
			return;
		}

		$eventsManager = PYS()->getEventsManager();

		foreach ( $eventsManager->getStaticEvents( 'ga' ) as $eventName => $events ) {
			foreach ( $events as $event ) {
				foreach ( $this->getPixelIDs() as $pixelID ) {
					$args = array(
						'v'   => 1,
						'tid' => $pixelID,
						't'   => 'event',
					);

					//@see: https://developers.google.com/analytics/devguides/collection/protocol/v1/parameters#ec
					if ( isset( $event['params']['event_category'] ) ) {
						$args['ec'] = urlencode( $event['params']['event_category'] );
					}

					if ( isset( $event['params']['event_action'] ) ) {
						$args['ea'] = urlencode( $event['params']['event_action'] );
					}

					if ( isset( $event['params']['event_label'] ) ) {
						$args['el'] = urlencode( $event['params']['event_label'] );
					}

					if ( isset( $event['params']['value'] ) ) {
						$args['ev'] = urlencode( $event['params']['value'] );
					}

                    if ( isset( $event['params']['items'] ) && is_array( $event['params']['items'] )) {

                        foreach ( $event['params']['items'] as $key => $item ) {
                            if(isset($item['id']))
							    @$args["pr{$key}id" ] = urlencode( $item['id'] );
                            if(isset($item['name']))
							    @$args["pr{$key}nm"] = urlencode( $item['name'] );
                            if(isset($item['category']))
							    @$args["pr{$key}ca"] = urlencode( $item['category'] );
							//@$args["pr{$key}va"] = urlencode( $item['id'] ); // variant
                            if(isset($item['price']))
							    @$args["pr{$key}pr"] = urlencode( $item['price'] );
                            if(isset($item['quantity']))
							    @$args["pr{$key}qt"] = urlencode( $item['quantity'] );

						}
						
						//https://developers.google.com/analytics/devguides/collection/protocol/v1/parameters#pa
						$args["pa"] = 'detail'; // required

					}
                    $src = add_query_arg( $args, 'https://www.google-analytics.com/collect' ) ;
                    $src = str_replace("[","%5B",$src);
                    $src = str_replace("]","%5D",$src);
					// ALT tag used to pass ADA compliance
					printf( '<noscript><img height="1" width="1" style="display: none;" src="%s" alt="google_analytics"></noscript>',
                        $src);

					echo "\r\n";

				}
			}
		}
		
	}
	
	private function getPageViewEventParams() {

		if ( PYS()->getEventsManager()->doingAMP ) {

			return array(
				'name' => 'PageView',
				'data' => array(),
			);

		} else {
			return false; // PageView is fired by tag itself
		}

	}


	/**
	 * @param CustomEvent $event
	 *
	 * @return array|bool
	 */
	private function getCustomEventData( $event ) {

		$ga_action = $event->getGoogleAnalyticsAction();

		if ( ! $event->isGoogleAnalyticsEnabled() || empty( $ga_action ) ) {
			return false;
		}


        if($event->isGaV4()) {
            $params = $event->getGaParams();

            foreach ($event->getGACustomParams() as $item) {
                $params[$item['name']] = $item['value'];
            }

        } else {
            $params = array(
                'event_category'  => $event->ga_event_category,
                'event_label'     => $event->ga_event_label,
                'value'           => $event->ga_event_value,
            );
        }


		return array(
			'name'  => $event->getGoogleAnalyticsAction(),
			'data'  => $params,
			'delay' => $event->getDelay(),
		);

	}

	private function getWooViewCategoryEventParams() {
		global $posts;

		if ( ! $this->getOption( 'woo_view_category_enabled' ) ) {
			return false;
		}
        
        $product_categories = array();
        $term = get_term_by( 'slug', get_query_var( 'term' ), 'product_cat' );
        
        if ( $term ) {
            $parent_ids = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
            $product_categories[] = $term->name;
            
            foreach ( $parent_ids as $term_id ) {
                $parent_term = get_term_by( 'id', $term_id, 'product_cat' );
                $product_categories[] = $parent_term->name;
            }
        }


		$items = array();
		$product_ids = array();
		$total_value = 0;

		for ( $i = 0; $i < count( $posts ); $i ++ ) {

			if ( $posts[ $i ]->post_type !== 'product' ) {
				continue;
			}

			$item = array(
				'id'            => GA\Helpers\getWooProductContentId($posts[ $i ]->ID),
				'name'          => $posts[ $i ]->post_title,
				'quantity'      => 1,
				'price'         => getWooProductPriceToDisplay( $posts[ $i ]->ID ),
			);
            $category = $this->getCategoryArrayWoo($posts[ $i ]->ID);
            if(!empty($category))
            {
                $item = array_merge($item, $category);
            }
			$items[] = $item;
			$product_ids[] = $item['id'];
			$total_value += $item['price'];

		}

		$params = array(
			'event_category'  => 'ecommerce',
			'event_label'     => 'category',
			'items'           => $items,
		);
		
		return array(
			'name'  => 'view_item_list',
			'data'  => $params,
		);

	}

	private function getWooViewContentEventParams() {

		if ( ! $this->getOption( 'woo_view_content_enabled' ) ) {
			return false;
		}
        $quantity = 1;
        $customProductPrice = -1;

        global $post;
        $product = wc_get_product( $post->ID );
        if(!$product)  return false;

        $productId = GA\Helpers\getWooProductContentId($product->get_id());

        $items = array();
        $general_item = array(
            'id'       => $productId,
            'name'     => $product->get_name(),
            'quantity' => $quantity,
            'price'    => getWooProductPriceToDisplay($product->get_id(), $quantity, $customProductPrice),
        );

        $category = $this->getCategoryArrayWoo($productId);
        if(!empty($category))
        {
            $general_item = array_merge($general_item, $category);
        }

        $items[] = $general_item;
// Check if the product has variations
        if ($product->is_type('variable') && !$this->getOption( 'woo_variable_as_simple' )) {

            $variations = $product->get_available_variations();

            foreach ($variations as $variation) {
                $variationProduct = wc_get_product($variation['variation_id']);
                $variationProductId = GA\Helpers\getWooProductContentId($variation['variation_id']);
                $category = $this->getCategoryArrayWoo($variationProductId, true);

                $item = array(
                    'id'       => $variationProductId,
                    'name'     => $this->getOption('woo_variations_use_parent_name') ? $variationProduct->get_title() : $variationProduct->get_name(),
                    'quantity' => $quantity,
                    'price'    => getWooProductPriceToDisplay($variationProduct->get_id(), $quantity, $customProductPrice)
                );
                $items[] = array_merge($item, $category);
            }
        }
        $params = array(
            'event_category'  => 'ecommerce',
        );
        $params['items'] = $items;


		return array(
			'name'  => 'view_item',
			'data'  => $params,
			'delay' => (int) PYS()->getOption( 'woo_view_content_delay' ),
		);

	}
    private function getWooViewCartEventParams(&$event){
        if ( ! $this->getOption( 'woo_view_cart_enabled' ) ) {
            return false;
        }
        $data = ['name'  => 'view_cart'];
        $payload = $event->payload;
        $params = $this->getWooEventViewCartParams( $event );
        $event->addParams($params);
        $event->addPayload($data);
        return true;
    }
    private function getWooEventViewCartParams($event){
        $params = [
            'event_category' => 'ecommerce',
        ];
        $params['currency'] = get_woocommerce_currency();
        $items = array();
        $product_ids = array();
        $withTax = 'incl' === get_option( 'woocommerce_tax_display_cart' );
        if(WC()->cart->get_cart())
        {
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {

                $product = wc_get_product(!empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id']);

                if ($product) {

                    $product_data = $product->get_data();
                    $product_id = GA\Helpers\getWooCartItemId( $cart_item );
                    $content_id = GA\Helpers\getWooProductContentId($product_id);
                    $price = $cart_item['line_subtotal'];


                    if ($withTax) {
                        $price += $cart_item['line_subtotal_tax'];
                    }
                    $item = array(
                        'id'       => $content_id,
                        'name'     => $this->getOption('woo_variations_use_parent_name') && $product->is_type('variation') ? $product->get_title() : $product->get_name(),
                        'quantity' => $cart_item['quantity'],
                        'price'    => $cart_item['quantity'] > 0 ? pys_round($price / $cart_item['quantity']) : $price,
                    );
                    $category = $this->getCategoryArrayWoo($product_id, $product->is_type('variation'));
                    if (!empty($category)) {
                        $item = array_merge($item, $category);
                    }
                    if ($product && $product->is_type('variation')) {
                        foreach ($event->args['products'] as $event_product){
                            if($event_product['product_id'] == $product_id && !empty($event_product['variation_name']))
                            {
                                $item['variant'] = $event_product['variation_name'];
                            }

                        }
                    }

                    $items[] = $item;
                    $product_ids[] = $item['id'];
                }
            }
        }
        $params['items'] = $items;
        $params['value'] = getWooEventCartTotal($event);

        return $params;
    }
	private function getWooAddToCartOnButtonClickEventParams( $args ) {

		if ( ! $this->getOption( 'woo_add_to_cart_enabled' )  || ! PYS()->getOption( 'woo_add_to_cart_on_button_click' ) ) {
			return false;
		}
        $product_id = $args->args['productId'];
        $quantity = $args->args['quantity'];

        $product = wc_get_product( $product_id );
        if(!$product) return false;

        $product_ids = array();
        $items = array();

        $isGrouped = $product->get_type() == "grouped";
        if($isGrouped) {
            $product_ids = $product->get_children();
        } else {
            $product_ids[] = $product_id;
        }

        foreach ($product_ids as $child_id) {
            $childProduct = wc_get_product($child_id);
            if($childProduct->get_type() == "variable" && $isGrouped) {
                continue;
            }
            $content_id = GA\Helpers\getWooProductContentId($child_id);
            $price = getWooProductPriceToDisplay( $child_id, $quantity );
            $name = $this->getOption('woo_variations_use_parent_name') && $childProduct->is_type('variation') ? $childProduct->get_title() : $childProduct->get_name();


            if ( $childProduct->get_type() == 'variation' ) {
                $variation_name = !$this->getOption('woo_variations_use_parent_name') ? implode("/", $childProduct->get_variation_attributes()) : $product->get_title();
                $categories = $this->getCategoryArrayWoo($child_id, true);
            } else {
                $categories = $this->getCategoryArrayWoo($child_id);
                $variation_name = null;
            }
            $item = array(
                'id'       => $content_id,
                'name'     => $name,
                'quantity' => $quantity,
                'price'    => $price,
                'variant'  => $variation_name,
            );
            if (!empty($categories)) {
                $item = array_merge($item, $categories);
            }

            $items[] = $item;
        }


		$params = array(
			'event_category'  => 'ecommerce',
			'items'           => $items,
		);

        $data = array(
            'params'  => $params,
        );

        if($product->get_type() == 'grouped') {
            $grouped = array();
            foreach ($product->get_children() as $childId) {
                $grouped[$childId] = array(
                    'content_id' => GA\Helpers\getWooProductContentId( $childId ),
                    'price' => getWooProductPriceToDisplay( $childId )
                );
            }
            $data['grouped'] = $grouped;
        }

		return $data;

	}

	private function getWooAddToCartOnCartEventParams() {

		if ( ! $this->getOption( 'woo_add_to_cart_enabled' ) ) {
			return false;
		}

		$params = $this->getWooCartParams();

		return array(
			'name' => 'add_to_cart',
			'data' => $params
		);

	}

    /**
     * @param SingleEvent $event
     * @return bool
     */
	private function getWooRemoveFromCartParams( $event ) {

		if ( ! $this->getOption( 'woo_remove_from_cart_enabled' ) ) {
			return false;
		}
        $cart_item = $event->args['item'];
		$product_id = $cart_item['product_id'];

		$product = wc_get_product( $product_id );
		if(!$product) return false;

        $name = $product->get_title();


		if ( ! empty( $cart_item['variation_id'] ) ) {
            $variation = wc_get_product( $cart_item['variation_id'] );
            if($variation && $variation->get_type() == 'variation'  && !$this->getOption( 'woo_variable_as_simple' )) {
                $variation_name = !$this->getOption('woo_variations_use_parent_name') ? implode("/", $variation->get_variation_attributes()) : $product->get_title();
                $categories = $this->getCategoryArrayWoo($product_id, true);
            } else {
                $variation_name = null;
                $categories = $this->getCategoryArrayWoo($product_id);
            }
		} else {
			$variation_name = null;
            $categories = $this->getCategoryArrayWoo($product_id);
		}

        $data = [
            'name' => "remove_from_cart"
        ];
        $params = [
            'event_category'  => 'ecommerce',
            'currency'        => get_woocommerce_currency(),
            'items'           => array(
                array(
                    'id'       => $product_id,
                    'name'     => $name,
                    'quantity' => $cart_item['quantity'],
                    'price'    => getWooProductPriceToDisplay( $product_id, $cart_item['quantity'] ),
                    'variant'  => $variation_name,
                ),
            )];

        if(!empty($categories))
        {
            $params['items'][0] = array_merge($params['items'][0], $categories);
        }

        $event->addParams($params);
        $event->addPayload($data);

		return true;
	}

	private function getWooInitiateCheckoutEventParams() {

		if ( ! $this->getOption( 'woo_initiate_checkout_enabled' ) ) {
			return false;
		}

		$params = $this->getWooCartParams();

		return array(
			'name'  => 'begin_checkout',
			'data'  => $params
		);

	}
	
	private function getWooPurchaseEventParams() {
        global $wp;
		if ( ! $this->getOption( 'woo_purchase_enabled' ) ) {
			return false;
		}
        $key = sanitize_key($_REQUEST['key']);
        $cache_key = 'order_id_' . $key;
        $order_id = get_transient( $cache_key );
        if (PYS()->woo_is_order_received_page() && empty($order_id) && $wp->query_vars['order-received']) {

            $order_id = absint( $wp->query_vars['order-received'] );
            if ($order_id) {
                set_transient( $cache_key, $order_id, HOUR_IN_SECONDS );
            }
        }
        if ( empty($order_id) ) {
            $order_id = (int) wc_get_order_id_by_order_key( $key );
            set_transient( $cache_key, $order_id, HOUR_IN_SECONDS );
        }

		$order = new \WC_Order( $order_id );
		$items = array();
		$product_ids = array();
		$total_value = 0;

		foreach ( $order->get_items( 'line_item' ) as $line_item ) {

            $product_id = GA\Helpers\getWooCartItemId( $line_item );
            $content_id = GA\Helpers\getWooProductContentId( $product_id );

			$product = wc_get_product( $product_id );
            if(!$product) continue;
            $name = $this->getOption('woo_variations_use_parent_name') && $product->is_type('variation') ? $product->get_title() : $product->get_name();



            if ( $line_item['variation_id'] ) {
				$variation = wc_get_product( $line_item['variation_id'] );
                if($variation && $variation->get_type() == 'variation' && !$this->getOption( 'woo_variable_as_simple' )) {

                    $variation_name = !$this->getOption('woo_variations_use_parent_name') ? implode("/", $variation->get_variation_attributes()) : $product->get_title();
                    $categories = $this->getCategoryArrayWoo($product_id, true);
                } else {
                    $variation_name = null;
                    $categories = $this->getCategoryArrayWoo($product_id);
                }
			} else {
				$variation_name = null;
                $categories = $this->getCategoryArrayWoo($product_id);
			}

			/**
			 * Discounted price used instead of price as is on Purchase event only to avoid wrong numbers in
			 * Analytic's Product Performance report.
			 */
			if ( isWooCommerceVersionGte( '3.0' ) ) {
				$price = pys_round($line_item['total'] + $line_item['total_tax']);
			} else {
				$price = pys_round($line_item['line_total'] + $line_item['line_tax']);
			}

			$qty = $line_item['qty'];
			$price = $price / $qty;

			if ( isWooCommerceVersionGte( '3.0' ) ) {

				if ( 'yes' === get_option( 'woocommerce_prices_include_tax' ) ) {
					$price = wc_get_price_including_tax( $product, array( 'qty' => 1, 'price' => $price ) );
				} else {
					$price = wc_get_price_excluding_tax( $product, array( 'qty' => 1, 'price' => $price ) );
				}

			} else {

				if ( 'yes' === get_option( 'woocommerce_prices_include_tax' ) ) {
					$price = $product->get_price_including_tax( 1, $price );
				} else {
					$price = $product->get_price_excluding_tax( 1, $price );
				}

			}

			$item = array(
				'id'       => $content_id,
				'name'     => $name,
				'quantity' => $qty,
				'price'    => $price,
				'variant'  => $variation_name,
			);
            if (!empty($categories)) {
                $item = array_merge($item, $categories);
            }
			$items[] = $item;
			$product_ids[] = $item['id'];
			$total_value   += $item['price' ];

		}
		
		$params = array(
			'event_category'  => 'ecommerce',
            'transaction_id'  => $order_id,
			'value'           => $order->get_total(),
			'currency'        => get_woocommerce_currency(),
			'items'           => $items,
		);

        $params['fees'] = get_fees($order);

		return array(
			'name' => 'purchase',
			'data' => $params
		);

	}

	private function getWooCartParams() {

		$items = array();
		$product_ids = array();
		$total_value = 0;

		foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {

            $product_id = GA\Helpers\getWooCartItemId( $cart_item );
            $content_id = GA\Helpers\getWooProductContentId( $product_id );

			$product = wc_get_product( $product_id );
			if(!$product) continue;

            $name = $this->getOption('woo_variations_use_parent_name') && $product->is_type('variation') ? $product->get_title() : $product->get_name();


			if ( $cart_item['variation_id'] ) {
                $variation = wc_get_product( $cart_item['variation_id'] );
                if($variation && $variation->get_type() == 'variation' && !$this->getOption( 'woo_variable_as_simple' )) {
                    $variation_name = !$this->getOption('woo_variations_use_parent_name') ? implode("/", $variation->get_variation_attributes()) : $product->get_title();
                    $categories = $this->getCategoryArrayWoo($product_id, true);
                } else {

                    $variation_name = null;
                    $categories = $this->getCategoryArrayWoo($product_id);
                }
            } else {

                $variation_name = null;
                $categories = $this->getCategoryArrayWoo($product_id);
			}

			$item = array(
				'id'       => $content_id,
				'name'     => $name,
				'quantity' => $cart_item['quantity'],
				'price'    => getWooProductPriceToDisplay( $product_id ),
				'variant'  => $variation_name,
			);

            if (!empty($categories)) {
                $item = array_merge($item, $categories);
            }
			$items[] = $item;
			$product_ids[] = $item['id'];
			$total_value += $item['price'];

		}
		
		$params = array(
			'event_category' => 'ecommerce',
			'items' => $items,
		);

		return $params;

	}
	
	private function getEddViewContentEventParams() {
		global $post;

		if ( ! $this->getOption( 'edd_view_content_enabled' ) ) {
			return false;
		}

		$params = array(
			'event_category'  => 'ecommerce',
			'items'           => array(
				array(
					'id'       => $post->ID,
					'name'     => $post->post_title,
					'category' => implode( '/', getObjectTerms( 'download_category', $post->ID ) ),
					'quantity' => 1,
					'price'    => getEddDownloadPriceToDisplay( $post->ID ),
				),
			),
		);

		return array(
			'name'  => 'view_item',
			'data'  => $params,
			'delay' => (int) PYS()->getOption( 'edd_view_content_delay' ),
		);

	}

	private function getEddAddToCartOnButtonClickEventParams( $download_id ) {
		// maybe extract download price id
		if ( strpos( $download_id, '_') !== false ) {
			list( $download_id, $price_index ) = explode( '_', $download_id );
		} else {
			$price_index = null;
		}

		$download_post = get_post( $download_id );

		$params = array(
			'event_category'  => 'ecommerce',
			'items'           => array(
				array(
					'id'       => GA\Helpers\getEddDownloadContentId($download_id),
					'name'     => $download_post->post_title,
					'category' => implode( '/', getObjectTerms( 'download_category', $download_id ) ),
					'quantity' => 1,
					'price'    => getEddDownloadPriceToDisplay( $download_id, $price_index ),
				),
			),
		);
		
		return $params;

	}

	private function getEddCartEventParams( $context = 'add_to_cart' ) {

		if ( $context == 'add_to_cart' && ! $this->getOption( 'edd_add_to_cart_enabled' ) ) {
			return false;
		} elseif ( $context == 'begin_checkout' && ! $this->getOption( 'edd_initiate_checkout_enabled' ) ) {
			return false;
		} elseif ( $context == 'purchase' && ! $this->getOption( 'edd_purchase_enabled' ) ) {
			return false;
		}

		if ( $context == 'add_to_cart' || $context == 'begin_checkout' ) {
			$cart = edd_get_cart_contents();
		} else {
			$cart = edd_get_payment_meta_cart_details( edd_get_purchase_id_by_key( getEddPaymentKey() ), true );
		}

		$items = array();
		$product_ids = array();
		$total_value = 0;

		foreach ( $cart as $cart_item_key => $cart_item ) {

			$download_id   = (int) $cart_item['id'];
			$download_post = get_post( $download_id );

			if ( in_array( $context, array( 'purchase', 'FrequentShopper', 'VipClient', 'BigWhale' ) ) ) {
				$item_options = $cart_item['item_number']['options'];
			} else {
				$item_options = $cart_item['options'];
			}

			if ( ! empty( $item_options ) && !empty($item_options['price_id'])) {
				$price_index = $item_options['price_id'];
			} else {
				$price_index = null;
			}

			/**
			 * Price as is used for all events except Purchase to avoid wrong values in Product Performance report.
			 */
			if ( $context == 'purchase' ) {
			 
				$price = $cart_item['item_price'] - $cart_item['discount'];

				if ( edd_prices_include_tax() ) {
					$price -= $cart_item['tax'];
				} else {
					$price += $cart_item['tax'];
				}

			} else {
				$price = getEddDownloadPriceToDisplay( $download_id, $price_index );
			}

			$item = array(
				'id'       => GA\Helpers\getEddDownloadContentId($download_id),
				'name'     => $download_post->post_title,
				'category' => implode( '/', getObjectTerms( 'download_category', $download_id ) ),
				'quantity' => $cart_item['quantity'],
				'price'    => $price
//				'variant'  => $variation_name,
			);

			$items[] = $item;
			$product_ids[] = (int) $cart_item['id'];
			$total_value += $price;

		}

		$params = array(
			'event_category' => 'ecommerce',
			'items' => $items,
		);


		if ( $context == 'purchase' ) {

			$payment_key = getEddPaymentKey();
			$payment_id = (int) edd_get_purchase_id_by_key( $payment_key );

            $params['transaction_id'] = $payment_id;
			$params['currency'] = edd_get_currency();
            $params['value'] = edd_get_payment_amount( $payment_id );

		}
		
		return array(
			'name' => $context,
			'data' => $params,
		);

	}

	private function getEddRemoveFromCartParams( $cart_item ) {

		if ( ! $this->getOption( 'edd_remove_from_cart_enabled' ) ) {
			return false;
		}

		$download_id = $cart_item['id'];
		$download_post = get_post( $download_id );

		$price_index = ! empty( $cart_item['options'] ) ? $cart_item['options']['price_id'] : null;

		return array(
            'name' => 'remove_from_cart',
			'data' => array(
				'event_category'  => 'ecommerce',
				'currency'        => edd_get_currency(),
				'items'           => array(
					array(
						'id'       => GA\Helpers\getEddDownloadContentId($download_id),
						'name'     => $download_post->post_title,
						'category' => implode( '/', getObjectTerms( 'download_category', $download_id ) ),
						'quantity' => $cart_item['quantity'],
						'price'    => getEddDownloadPriceToDisplay( $download_id, $price_index ),
//						'variant'  => $variation_name,
					),
				),
			),
		);

	}

	private function getEddViewCategoryEventParams() {
		global $posts;

		if ( ! $this->getOption( 'edd_view_category_enabled' ) ) {
			return false;
		}

		$term = get_term_by( 'slug', get_query_var( 'term' ), 'download_category' );
        if ( !$term ) return false;
		$parent_ids = get_ancestors( $term->term_id, 'download_category', 'taxonomy' );

		$download_categories = array();
		$download_categories[] = $term->name;

		foreach ( $parent_ids as $term_id ) {
			$parent_term = get_term_by( 'id', $term_id, 'download_category' );
			$download_categories[] = $parent_term->name;
		}

		$list_name = implode( '/', array_reverse( $download_categories ) );

		$items = array();
		$product_ids = array();
		$total_value = 0;

		for ( $i = 0; $i < count( $posts ); $i ++ ) {

			$item = array(
				'id'            => GA\Helpers\getEddDownloadContentId($posts[ $i ]->ID),
				'name'          => $posts[ $i ]->post_title,
				'category'      => implode( '/', getObjectTerms( 'download_category', $posts[ $i ]->ID ) ),
				'quantity'      => 1,
				'price'         => getEddDownloadPriceToDisplay( $posts[ $i ]->ID ),
				'list_position' => $i + 1,
				'list'          => $list_name,
			);

			$items[] = $item;
			$product_ids[] = $item['id'];
			$total_value += $item['price'];

		}

		$params = array(
			'event_category'  => 'ecommerce',
			'event_label'     => $list_name,
			'items'           => $items,
		);

		return array(
			'name'  => 'view_item_list',
			'data'  => $params,
		);

	}



    private function getCategoryArrayWoo($contentID, $isVariant = false)
    {
        $category_array = array();

        if ($isVariant) {
            $parent_product_id = wp_get_post_parent_id($contentID);
            $category = getObjectTerms('product_cat', $parent_product_id);
        } else {
            $category = getObjectTerms('product_cat', $contentID);
        }

        $category_index = 1;

        foreach ($category as $cat) {
            if ($category_index >= 6) {
                break; // Stop the loop if the maximum limit of 5 categories is exceeded
            }
            $category_array['item_category' . ($category_index > 1 ? $category_index : '')] = $cat;
            $category_index++;
        }
        return $category_array;
    }
}

/**
 * @return GA
 */
function GA() {
	return GA::instance();
}

GA();