<?php

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

/**
 * Plugin Name: WooCommerce Flow Webpay
 * Plugin URI: https://www.flow.cl
 * Description: Flow payment gateway for woocommerce (Webpay)
 * Version: 1.6
 * Author: Flow
 * Author URI: https://www.flow.cl
 */

add_action('plugins_loaded', 'woocommerce_flow_init', 0);

include_once('lib/flowlib.php');

function woocommerce_flow_init()
{

    class WC_Gateway_flow extends WC_Payment_Gateway
    {

        var $notify_url;

        /**
         * Constructor for the gateway.
         *
         */
        public function __construct()
        {
        	error_log("on construct");
            $this->id = 'flow';
            //$this->icon = plugins_url('images/buttons/50x25.png', __FILE__);
            $this->has_fields = false;
            $this->method_title = __('Flow - Pago electrónico via Webpay', 'woocommerce');
            $this->notify_url = str_replace('https:', 'http:', add_query_arg('wc-api', 'WC_Gateway_' . $this->id, home_url('/')));

            // Load the settings and init variables.
            $this->privateKeyStatus = $this->get_private_key_status();
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->receiver_id = $this->get_option('receiver_id');
            $this->platform_id = $this->get_option('platform_id');
            $this->skip_type_id = $this->get_option('skip_type_id');
            $this->privateKeyError = null;
            if (get_option('woocommerce_flow_secret_valid', "true") == "false") {
            	$this->privateKeyError = "La llave privada es inválida";
            	update_option('woocommerce_flow_secret_valid', "true");
            }
            $this->receiverIdError = null;
            if (get_option('woocommerce_flow_receiver_valid', "true") == "false") {
            	$this->receiverIdError = "Debe indicar el Id del Comercio registrado en Flow";
            	update_option('woocommerce_flow_receiver_valid', "true");
            }

            // Actions
            add_action('valid-' . $this->id . '-ipn-request', array($this, 'successful_request'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'flow_process_admin_options'));

            // Payment listener/API hook
            add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'check_ipn_response'));

            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }
        }
        
        function flow_process_admin_options() {
        	if (!$this->process_admin_options()) return false;
        	
        	$result = true;
        	
        	$receiverId = $this->get_option('receiver_id');
        	if ( $receiverId == null || trim($receiverId) == '') {
        		update_option('woocommerce_flow_receiver_valid', "false");
        		$result = false;
        		error_log('receiver id'.$receiverId);
        	}
        	
        	if (isset($_FILES['woocommerce_flow_secret'])) {
				$file = $_FILES['woocommerce_flow_secret'];
        		$hasFile = $file['size']>0;
        		if ($hasFile) {
					$fileName = $file['tmp_name'];
        			if (!$this->isValidPrivateKey($fileName)) {
        				update_option('woocommerce_flow_secret_valid', "false");
        				$result = false;
        			} else {
        				$this->privateKeyData = file_get_contents($fileName);
        				update_option('woocommerce_flow_secret', $this->privateKeyData);
        				update_option('woocommerce_flow_secret_valid', "true");
        			}
        		}
        	}
        	return $result;
        }
        
        private function get_private_key_status() {
        	$pk = get_option('woocommerce_flow_secret');
        	if ($pk == null) return 'Debe adjuntar su llave privada Flow';
        	return 'Ya ha registrado una llave privada Flow. Adjunte una nueva si desea modificarla';
        }

        private function isValidPrivateKey($privateKeyFile) {
        	$fp = fopen($privateKeyFile, "r");
        	$priv_key = fread($fp, 8192);
        	fclose($fp);
        	return openssl_get_privatekey($priv_key)!=null;
        }

        /**
         * Check if this gateway is enabled and available in the user's country
         */
        function is_valid_for_use()
        {
            if (!in_array(get_woocommerce_currency(), apply_filters('woocommerce_' . $this->id . '_supported_currencies', array('CLP')))) {
                return false;
            }
            return true;
        }

        /**
         * Admin Panel Options
         */
        public function admin_options()
        {
            ?>
            <?php if ($this->receiverIdError!=null) {?>
            <div class="inline error">
                <p>
                    <strong><?php echo $this->receiverIdError; ?></strong>
                </p>
            </div>
            <?php }?>
            <?php if ($this->privateKeyError!=null) {?>
            <div class="inline error">
                <p>
                    <strong><?php echo $this->privateKeyError; ?></strong>
                </p>
            </div>
            <?php }?>
            <h3><?php _e('Flow', 'woocommerce'); ?></h3>
            <p><?php _e('Pago a través de Flow', 'woocommerce'); ?></p>

            <?php if ($this->is_valid_for_use()) : ?>
            <table class="form-table">
                <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
            </table><!--/.form-table-->
            
        <?php else : ?>
            <div class="inline error">
                <p>
                    <strong><?php _e('Gateway Disabled', 'woocommerce'); ?></strong>: <?php _e('Flow no soporta el tipo de moneda (currency).', 'woocommerce'); ?>
                </p>
            </div>
        <?php
        endif;
        }


        /**
         * Initialise Gateway Settings Form Fields
         */
        function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Activar/Desactivar',
                    'type' => 'checkbox',
                    'label' => 'Activar',
                    'default' => 'yes'
                ),
                'platform_id' => array(
                    'title' => __('Plataforma de Flow', 'woocommerce'),
                    'type' => 'select',
                    'description' => __('Plataforma de Flow a utilizar', 'woocommerce'),
                    'default' => 'T',
                    'desc_tip' => true,
                	'options' => array('T' => 'Plataforma de pruebas de Flow', 'P' => 'Plataforma oficial de Flow')
                ),
            	'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Pago via Webpay', 'woocommerce'),
                    'desc_tip' => true
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce'),
                    'default' => __('Pago electrónico a través de Webpay')
                ),
                'receiver_id' => array(
                    'title' => __('ID Comercio Flow', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Ingrese su ID Comercio Flow (correo electrónico)', 'woocommerce'),
                    'default' => '',
                    'desc_tip' => true
                ),
            	'skip_type_id' => array(
					'title' => __('Modo de acceso a Webpay', 'woocommerce'),
					'type' => 'select',
					'description' => __('Indique la forma en que se accederá a Webpay', 'woocommerce'),
					'default' => 'd',
					'desc_tip' => true,
					'options' => array('d' => 'Ingreso directo a Webpay', 'f' => 'Mostrar pasarela Flow')
				),
            	'secret' => array(
                    'title' => __('Llave privada Flow', 'woocommerce'),
                    'type' => 'file',
                    'description' => $this->privateKeyStatus,
                    'default' => '',
                    'desc_tip' => false
                )
            );

        }


        /**
         * Create the payment on flow and try to start the app.
         */
        function generate_flow_submit_button($order_id)
        {

            $order = new WC_Order($order_id);
            
            $privkey = get_option('woocommerce_flow_secret');
            $flow_comercio = $this->get_option('receiver_id');
            $orden_compra = str_replace('#', '', $order->get_order_number());
            $monto = (int)number_format($order->get_total(), 0, ',', '');
			$modoPago = 1;
            $concepto = 'Orden ' . $order->get_order_number() . ' - ' . get_bloginfo('name');
            $id_producto = $order->billing_email.'#'.$orden_compra;
            $flow_url_exito = $this->get_return_url($order);
            $flow_url_fracaso = str_replace('&amp;', '&', $order->get_cancel_order_url());
            $flow_url_confirmacion =  $this->notify_url;
            
            $tipo_integracion = $this->get_option('skip_type_id');
            $email = $order->billing_email;
            
            $flow_url = $this->get_option('platform_id') == 'P'?flow_get_endpoint():flow_get_endpoint_test();
                        
            $request = flow_pack($privkey, $flow_comercio, $orden_compra, $monto, $modoPago, $concepto, $id_producto, 
				$flow_url_exito, $flow_url_fracaso, $flow_url_confirmacion,
            	$tipo_integracion, $email);

            return '<form action="'.$flow_url.'" method="post">'.
              '<input type="hidden" name="parameters" value="'.$request.'"></input>'.
              '<input type="submit" value="Pagar en Flow"></input>'.
              '</form>';

        }

        /**
         * Process the payment and return the result
         */
        function process_payment($order_id)
        {

            $order = new WC_Order($order_id);
            return array(
                'result' => 'success',
                'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
            );
        }

        /**
         * Output for the order received page.
         */
        function receipt_page($order)
        {
            echo $this->generate_flow_submit_button($order);
        }

        
        function confirm() {
        	$privatekey = get_option('woocommerce_flow_secret');
        	$comercio = $this->receiver_id;
        
        	$errorResponse = array('status' => 'RECHAZADO', 'c' => $comercio);
        	$acceptResponse = array('status' => 'ACEPTADO', 'c' => $comercio);
        
        	$data = $_POST['response'];
        	$data = str_replace('&amp;', '&', $data);
        	try {
        		$params = flow_sign_validate($data);
        	} catch (Exception $e) {
        		error_log($e->getMessage());
        		echo flow_build_response($privatekey, $errorResponse);
        		return;
        	}
        
        	$error = flow_aget($params, 'error');
        	if ($error != null) {
        		error_log("Error recibido: $error");
        		echo flow_build_response($privatekey, $acceptResponse);
        		return;
        	}
        
        	$status = flow_aget($params, 'status');
        	if ($status == null) {
        		error_log("Peticion inválida (sin status)");
        		echo flow_build_response($privatekey, $errorResponse);
        		return;
        	}
        
        	$order_id = flow_aget($params, 'kpf_orden');
        	$amount   = flow_aget($params, 'kpf_monto');
        	 
        	// compatibilidad con versiones antiguas
        	if ($order_id == null) $order_id = flow_aget($params, 'oc');
        	if ($amount == null) $amount = flow_aget($params, 'm');
        	 
        	error_log(print_r($params, true));
        	 
        	$order = new WC_Order($order_id);
        	
        	if ($order) {
        		error_log("order found");
        		$order_total = (int)number_format($order->get_total(), 0, ',', '');
        		$order_amount_valid = $order_total == $amount;
        
        		error_log("order total: $order_total");
        		error_log("order_amount_valid : $order_amount_valid");
        
        
        		if ($order_amount_valid) {
					if ($status == 'EXITO') {
						error_log("STATUS ES ok:".$status);
        				$order->add_order_note(__('Pago con Flow verificado', 'woocommerce'));
						$order->payment_complete();
					} else {
						error_log("STATUS ES failure:".$status);
						$order->update_status( 'failed',  __( 'Pago con Flow rechazado', 'woocommerce' ));
					}
        			echo flow_build_response($privatekey, $acceptResponse);
        			return;
        		}
        	}
        	echo flow_build_response($privatekey, $errorResponse);
        }
        
        /**
         * Check for IPN Response
         */
        function check_ipn_response()
        {
            @ob_clean();
            header('HTTP/1.1 200 OK');
            header('Content-Type: text/plain');
			error_log("on ipn response");
			$this->confirm();
			@ob_end_flush();
			exit; // duro pero evita el "1" adicional que pone woocommerce
        }

    }

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_flow_gateway($methods)
    {
        $methods[] = 'WC_Gateway_flow';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_flow_gateway');

    function woocommerce_flow_add_clp_currency($currencies)
    {
        $currencies["CLP"] = __('Pesos Chilenos');
        return $currencies;
    }

    function woocommerce_flow_add_clp_currency_symbol($currency_symbol, $currency)
    {
        switch ($currency) {
            case 'CLP':
                $currency_symbol = '$';
                break;
        }
        return $currency_symbol;
    }

    add_filter('woocommerce_currencies', 'woocommerce_flow_add_clp_currency', 10, 1);
    add_filter('woocommerce_currency_symbol', 'woocommerce_flow_add_clp_currency_symbol', 10, 2);

}
