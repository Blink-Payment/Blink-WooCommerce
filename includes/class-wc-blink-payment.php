<?php

use P3\SDK\CreditCard;
use P3\SDK\Gateway;

/**
 * Gateway class
 */
class WC_Blink_Payment extends WC_Payment_Gateway
{
	/**
	 * @var string
	 */
	public $lang;

	/**
	 * @var string
	 */
	public $default_merchant_id;

	/**
	 * @var string
	 */
	public $default_secret;

	/**
	 * @var Gateway
	 */
	protected $gateway;

	public function __construct()
	{
		$configs = include(dirname(__FILE__) . '/../config.php');

		$this->has_fields          = false;
		$this->id                  = str_replace(' ', '', strtolower($configs['gateway_title']));
		$this->lang                = strtolower('woocommerce_' . $this->id);
		$this->icon                = plugins_url('/', dirname(__FILE__)) . 'assets/img/logo.png';
		$this->method_title        = __($configs['gateway_title'], $this->lang);
		$this->method_description  = __($configs['method_description'], $this->lang);
		$this->default_merchant_id = $configs['default_merchant_id'];
		$this->default_secret      = $configs['default_secret'];

		$this->supports = array(
			'subscriptions',
			'products',
			'refunds',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_admin'
		);

		$this->init_form_fields();
		$this->init_settings();

		// Get setting values
		$this->title               = $this->settings['title'];
		$this->description         = $this->settings['description'];

		$this->gateway = new Gateway(
			$this->settings['merchantID'],
			$this->settings['signature'],
			$this->settings['gatewayURL']
		);

		// Hooks
		add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

		add_action('woocommerce_api_wc_' . $this->id, array($this, 'process_response_callback'));
		add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'process_scheduled_subscription_payment_callback'), 10, 3);
	}

	/**
	 * Initialise Gateway Settings
	 */
	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled' => array(
				'title'       => __('Enable/Disable', $this->lang),
				'label'       => __('Enable ' . strtoupper($this->method_title), $this->lang),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			),
			'title' => array(
				'title'       => __('Title', $this->lang),
				'type'        => 'text',
				'description' => __('This controls the title which the user sees during checkout.', $this->lang),
				'default'     => __($this->method_title, $this->lang)
			),
			'type' => array(
				'title'       => __('Type of integration', $this->lang),
				'type'        => 'select',
				'options' => array(
					'hosted'     => 'Hosted',
					'hosted_v2'  => 'Hosted (Embedded)',
					'hosted_v3'  => 'Hosted (Modal)',
					'direct'     => 'Direct 3-D Secure',
				),
				'description' => __('This controls method of integration.', $this->lang),
				'default'     => 'Hosted (Embedded)'
			),
			'description' => array(
				'title'       => __('Description', $this->lang),
				'type'        => 'textarea',
				'description' => __('This controls the description which the user sees during checkout.', $this->lang),
				'default'     => $this->method_description,
			),
			'merchantID' => array(
				'title'       => __('Merchant ID', $this->lang),
				'type'        => 'text',
				'description' => __('Please enter your ' . $this->method_title . ' merchant ID', $this->lang),
				'default'     => $this->default_merchant_id,
				'custom_attributes' => [
					'required'        => true,
				],
			),
			'signature' => array(
				'title'       => __('Signature Key', $this->lang),
				'type'        => 'text',
				'description' => __('Please enter the signature key for the merchant account.', $this->lang),
				'default'     => $this->default_secret,
				'custom_attributes' => [
					'required'        => true,
				],
			),
			'gatewayURL' => array(
				'title'       => __('Gateway URL', $this->lang),
				'type'        => 'text',
				'description' => __('Please enter your gateway URL.', $this->lang),
				'default'     => $this->blink_gateway,
				'custom_attributes' => [
					'required'        => true,
				],
			),
			'formResponsive' => array(
				'title'       => __('Responsive form', $this->lang),
				'type'        => 'select',
				'options' => array(
					'Y'       => 'Yes',
					'N'       => 'No'
				),
				'description' => __('This controls whether the payment form is responsive.', $this->lang),
				'default'     => 'No'
			),
			'customerWalletsEnabled' => array(
				'title'       => __('Customer wallets', $this->lang),
				'type'        => 'select',
				'options' => array(
					'Y'       => 'Yes',
					'N'       => 'No'
				),
				'description' => __('This controls whether wallets is enabled for customers on the hosted form.', $this->lang),
				'default'     => 'No'
			),
		);
	}

	/**
	 * You will need it if you want your custom credit card form, Step 4 is about it
	 */
	public function payment_fields()
	{
		if ($this->description) {
			echo wpautop(wp_kses_post($this->description));
		}

		if ($this->settings['type'] === 'direct') {
			$parameters = [
				'cardNumber'         => @$_POST['cardNumber'],
				'cardExpiryMonth'    => @$_POST['cardExpiryMonth'],
				'cardExpiryYear'     => @$_POST['cardExpiryYear'],
				'cardCVV'            => @$_POST['cardCVV'],
			];

			$deviceData = [
				'deviceChannel'				=> 'browser',
				'deviceIdentity'			=> (isset($_SERVER['HTTP_USER_AGENT']) ? htmlentities($_SERVER['HTTP_USER_AGENT']) : null),
				'deviceTimeZone'			=> '0',
				'deviceCapabilities'		=> '',
				'deviceScreenResolution'	=> '1x1x1',
				'deviceAcceptContent'		=> (isset($_SERVER['HTTP_ACCEPT']) ? htmlentities($_SERVER['HTTP_ACCEPT']) : null),
				'deviceAcceptEncoding'		=> (isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? htmlentities($_SERVER['HTTP_ACCEPT_ENCODING']) : null),
				'deviceAcceptLanguage'		=> (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? htmlentities($_SERVER['HTTP_ACCEPT_LANGUAGE']) : null),
				'deviceAcceptCharset'		=> (isset($_SERVER['HTTP_ACCEPT_CHARSET']) ? htmlentities($_SERVER['HTTP_ACCEPT_CHARSET']) : null),
			];

			$browserInfo = '';

			foreach ($deviceData as $key => $value) {
				$browserInfo .= '<input type="hidden" id="' . $key . '" name="browserInfo[' . $key . ']" value="' . htmlentities($value) . '" />';
			}

			$generateMonthOptions = function () use ($parameters) {
				$str = '';
				foreach (range(1, 12) as $value) {
					$s = $parameters['cardExpiryMonth'] == $value ? 'selected' : '';
					$str .= '<option value="' . str_pad($value, 2, '0', STR_PAD_LEFT) . '" ' . $s . '>' . $value . '</option>' . "\n";
				}

				return $str;
			};

			$generateYearOptions = function () use ($parameters) {
				$str = '';
				foreach (range(date("Y"), date("Y") + 12) as $value) {
					$s = $parameters['cardExpiryYear'] == $value ? 'selected' : '';
					$str .= '<option value="' . substr($value, 2) . '" ' . $s . '>' . $value . '</option>' . "\n";
				}

				return $str;
			};

			echo
			/** @lang html */
			<<<FORM
<div style="display:flex; flex-direction:column; margin-bottom: 1vh;">
    <label>Card Number</label>
    <input type='text' id="field-cardNumber" name="cardNumber" value='{$parameters['cardNumber']}' maxlength="23" required='required'/>
</div>
<div style="display:flex; place-content:center space-between;">
    <div style="flex-direction: column; width: 45%; display: flex;">
        <label>Card Expiry Date</label>
        <div>
            <select style="width: 45%;" id="field-cardExpiryMonth" name="cardExpiryMonth" required='required'>
                <option value="" disabled selected>Month</option>
                {$generateMonthOptions()}
            </select>
            <select style="width: 45%;" id="field-cardExpiryYear" name="cardExpiryYear" required='required'>
                <option value="" disabled selected>Year</option>
                {$generateYearOptions()}
            </select>
        </div>
    </div>
    <div style="width: 40%; flex-direction: column; display: flex;">
        <label>CVV</label>
        <input type="text" id="field-cardCVV" name="cardCVV" value="{$parameters['cardCVV']}" maxlength="4" required="required"/>
    </div>
</div>
<br/>
$browserInfo
<script>
    var screen_width = (window && window.screen ? window.screen.width : '0');
    var screen_height = (window && window.screen ? window.screen.height : '0');
    var screen_depth = (window && window.screen ? window.screen.colorDepth : '0');
    var identity = (window && window.navigator ? window.navigator.userAgent : '');
    var language = (window && window.navigator ? (window.navigator.language ? window.navigator.language : window.navigator.browserLanguage) : '');
    var timezone = (new Date()).getTimezoneOffset();
    var java = (window && window.navigator ? navigator.javaEnabled() : false);
    document.getElementById('deviceIdentity').value = identity;
    document.getElementById('deviceTimeZone').value = timezone;
    document.getElementById('deviceCapabilities').value = 'javascript' + (java ? ',java' : '');
    document.getElementById('deviceAcceptLanguage').value = language;
    document.getElementById('deviceScreenResolution').value = screen_width + 'x' + screen_height + 'x' + screen_depth;
</script>
<script type="text/javascript">
var cardNumber = document.getElementById('field-cardNumber');
payform.cardNumberInput(cardNumber);
cardNumber.addEventListener('change', e => {
    e.target.style.borderColor = payform.validateCardNumber(e.target.value) ? '#B0B0B0' : 'red';     
});
document.getElementById('field-cardCVV').addEventListener('change', e => {
    e.target.style.borderColor = payform.validateCardCVC(e.target.value) ? '#B0B0B0' : 'red';     
});
var cardExpiryMonthElement = document.getElementById('field-cardExpiryMonth');
var cardExpiryYearElement = document.getElementById('field-cardExpiryYear');
var listener = e => {
    let isValid = payform.validateCardExpiry(cardExpiryMonthElement.value, '20'+cardExpiryYearElement.value);
    
    cardExpiryMonthElement.style.borderColor =  isValid ? '#B0B0B0' : 'red';     
    cardExpiryYearElement.style.borderColor = isValid ? '#B0B0B0' : 'red';     
};
cardExpiryMonthElement.addEventListener('change', listener);
cardExpiryYearElement.addEventListener('change', listener);
</script>
FORM;

			wp_enqueue_style('gateway-credit-card-styles', plugins_url('assets/css/gateway.css', dirname(__FILE__)));
		}
	}

	public function validate_fields()
	{
		if ($this->settings['type'] === 'direct') {
			$result = CreditCard::validCreditCard($_POST['cardNumber']);

			if (!$result['valid']) {
				wc_add_notice('Not a valid Card Number. Please check the card details.', 'error');
				return false;
			}

			if (!CreditCard::validDate('20' . $_POST['cardExpiryYear'], $_POST['cardExpiryMonth'])) {
				wc_add_notice('Not a valid Expiry Date. Please check the card details.', 'error');
				return false;
			}

			if (!CreditCard::validCvc($_POST['cardCVV'], $result['type'])) {
				wc_add_notice('Not a valid Card CVV. Please check the card details.', 'error');
				return false;
			}
		}

		return true;
	}

	/**
	 * Process the payment and return the result
	 *
	 * @param $order_id
	 *
	 * @return array
	 */
	public function process_payment($order_id)
	{
		$order = new WC_Order($order_id);

		if (in_array($this->settings['type'], ['hosted', 'hosted_v2', 'hosted_v3'], true)) {
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url(true)
			);
		}

		$args = array_merge(
			$this->capture_order($order_id),
			$_POST['browserInfo'],
			[
				'type'                 => 1,
				'cardNumber'           => $_POST['cardNumber'],
				'cardExpiryMonth'      => $_POST['cardExpiryMonth'],
				'cardExpiryYear'       => $_POST['cardExpiryYear'],
				'cardCVV'              => $_POST['cardCVV'],
				'remoteAddress'        => $_SERVER['REMOTE_ADDR'],
				'threeDSRedirectURL'   => add_query_arg(
					[
						'wc-api' => 'wc_' . $this->id,
						'XDEBUG_SESSION_START' => 'asdf'
					],
					home_url('/')
				),
			]
		);

		$response = $this->gateway->directRequest($args);
		setcookie('xref', $response['xref'], time() + 315);

		return $this->process_response_callback($response);
	}

	public function process_refund($order_id, $amount = null, $reason = '')
	{
		$order = wc_get_order($order_id);

		if (!$this->can_refund_order($order)) {
			return new WP_Error('error', __('Refund failed.', 'woocommerce'));
		}

		try {
			$amountByCurrency = \P3\SDK\AmountHelper::calculateAmountByCurrency($amount, $order->get_currency());

			$data = $this->gateway->refundRequest($order->get_transaction_id(), $amountByCurrency);

			$order->add_order_note($data['message']);

			return true;
		} catch (Exception $exception) {
			return new WP_Error('error', $exception->getMessage());
		}
	}

	/**
	 * receipt_page
	 */
	public function receipt_page($order)
	{
		if (in_array($this->settings['type'], ['hosted', 'hosted_v2', 'hosted_v3'])) {
			$redirect = add_query_arg('wc-api', 'wc_' . $this->id, home_url('/'));
			$callback = add_query_arg('wc-api', 'wc_' . $this->id, home_url('/'));

			$req = array_merge($this->capture_order($order), array(
				'redirectURL' => $redirect,
				'callbackURL' => $callback,
				'formResponsive' => $this->settings['formResponsive'],
			));

			echo $this->gateway->hostedRequest($req, 'hosted_v2' == $this->settings['type'], 'hosted_v3' == $this->settings['type']);
		}

		return null;
	}

	public function on_order_success($response)
	{
		$order = new WC_Order((int)$response['orderRef']);

		$orderNotes  = "\r\nResponse Code : {$response['responseCode']}\r\n";
		$orderNotes .= "Message : {$response['responseMessage']}\r\n";
		$orderNotes .= "Amount Received : " . number_format($response['amount'] / 100, 2) . "\r\n";
		$orderNotes .= "Unique Transaction Code : {$response['transactionUnique']}";

		$order->set_transaction_id($response['xref']);
		$order->add_order_note(__(ucwords($this->method_title) . ' payment completed.' . $orderNotes, $this->lang));
		$order->payment_complete();

		$successUrl = $this->get_return_url($order);

		if (is_ajax()) {
			return [
				'result' => 'success',
				'redirect' => $successUrl,
			];
		}

		$this->redirect($successUrl);
		die();
	}

	public function on_threeds_required($threeDSVersion, $res)
	{
		setcookie('threeDSRef', $res['threeDSRef'], time() + 315);

		switch (true) {
			case is_ajax() && $threeDSVersion >= 200:

				return [
					'result' => 'success',
					'redirect' => add_query_arg(
						[
							'ACSURL' => rawurlencode($res['threeDSURL']),
							'threeDSRef' => rawurlencode($res['threeDSRef']),
							'threeDSRequest' => $res['threeDSRequest'],
						],
						plugins_url('public/3d-secure-form-v2.php', dirname(__FILE__))
					),
				];
			case is_ajax() && $threeDSVersion < 200:
				$callback = add_query_arg(
					[
						'wc-api' => 'wc_' . $this->id,
						'xref' => $res['xref'],
					],
					home_url('/')
				);

				return [
					'result' => 'success',
					'redirect' => add_query_arg(
						[
							'ACSURL' => rawurlencode($res['threeDSURL']),
							'PaReq' => rawurlencode($res['threeDSRequest']['PaReq']),
							'MD' => rawurlencode($res['threeDSRequest']['MD']),
							'TermUrl' => rawurlencode($callback),
						],
						plugins_url('public/3d-secure-form.php', dirname(__FILE__))
					),
				];
			default:
				// Silently POST the 3DS request to the ACS in the IFRAME
				echo Gateway::silentPost($res['threeDSURL'], $res['threeDSRequest']);

				die();
		}
	}

	##########################
	## Hook Callbacks
	##########################

	/**
	 * Hook to process a subscription payment
	 *
	 * @param $amount_to_charge
	 * @param $renewal_order
	 */
	public function process_scheduled_subscription_payment_callback($amount_to_charge, $renewal_order)
	{
		// Gets all subscriptions (hopefully just one) linked to this order
		$subs = wcs_get_subscriptions_for_renewal_order($renewal_order);

		// Get all orders on this subscription and remove any that haven't been paid
		$orders = array_filter(current($subs)->get_related_orders('all'), function ($ord) {
			return $ord->is_paid();
		});

		// Replace every order with orderId=>xref kvps
		$xrefs = array_map(function ($ord) {
			return $ord->get_transaction_id();
		}, $orders);

		// Return the xref corresponding to the most recent order (assuming order number increase with time)
		$xref = $xrefs[max(array_keys($xrefs))];

		$req = array(
			'merchantID' => $this->settings['merchantID'],
			'xref' => $xref,
			'amount' => \P3\SDK\AmountHelper::calculateAmountByCurrency($amount_to_charge, $renewal_order->get_currency()),
			'action' => "SALE",
			'type' => 9,
			'rtAgreementType' => 'recurring',
			'avscv2CheckRequired' => 'N',
		);

		$response = $this->gateway->directRequest($req);

		try {
			$result = $this->gateway->verifyResponse($response, [$this, 'on_threeds_required'], function ($res) use ($renewal_order) {
				$orderNotes  = "\r\nResponse Code : {$res['responseCode']}\r\n";
				$orderNotes .= "Message : {$res['responseMessage']}\r\n";
				$orderNotes .= "Amount Received : " . number_format($res['amount'] / 100, 2) . "\r\n";
				$orderNotes .= "Unique Transaction Code : {$res['transactionUnique']}";

				$renewal_order->set_transaction_id($res['xref']);
				$renewal_order->add_order_note(__(ucwords($this->method_title) . ' payment completed.' . $orderNotes, $this->lang));
				$renewal_order->payment_complete();
				$renewal_order->save();

				return true;
			});
		} catch (Exception $exception) {
			$result = new WP_Error('payment_failed_error', $exception->getMessage());
			$renewal_order->add_order_note(
				__(ucwords($this->method_title) . ' payment failed. Could not communicate with direct API. Curl data: ' . json_encode($req), $this->lang)
			);
			$renewal_order->save();
		}

		if (is_wp_error($result)) {
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($renewal_order);
		} else {
			WC_Subscriptions_Manager::process_subscription_payments_on_order($renewal_order);
		}
	}

	/**
	 * Hook to process the response from payment gateway
	 */
	public function process_response_callback($response = null)
	{
		// v1
		if (isset($_REQUEST['MD'], $_REQUEST['PaRes'])) {
			$req = array(
				'action'	   => 'SALE',
				'merchantID'   => $this->settings['merchantID'],
				'xref'         => $_COOKIE['xref'],
				'threeDSMD'    => $_REQUEST['MD'],
				'threeDSPaRes' => $_REQUEST['PaRes'],
				'threeDSPaReq' => ($_REQUEST['PaReq'] ?? null),
			);

			$response = $this->gateway->directRequest($req);
		}

		// v2
		if (isset($_POST['threeDSMethodData']) || isset($_POST['cres'])) {
			$req = array(
				'merchantID' => $this->settings['merchantID'],
				'action' => 'SALE',
				// The following field must be passed to continue the 3DS request
				'threeDSRef' => $_COOKIE['threeDSRef'],
				'threeDSResponse' => $_POST,
			);

			$response = $this->gateway->directRequest($req);
		}

		$res = empty($response) ? stripslashes_deep($_POST) : $response;

		$this->create_wallet($res);

		try {
			return $this->gateway->verifyResponse($res, [$this, 'on_threeds_required'], [$this, 'on_order_success']);
		} catch (Exception $exception) {
			return $this->process_error($exception->getMessage(), $res);
		}
	}

	##########################
	## Helper functions
	##########################

	/**
	 * @param $order_id
	 * @return array
	 * @throws Exception
	 */
	protected function capture_order($order_id)
	{
		$order     = new WC_Order($order_id);
		$amount    = \P3\SDK\AmountHelper::calculateAmountByCurrency($order->get_total(), $order->get_currency());

		$billing_address  = $order->get_billing_address_1();
		$billing2 = $order->get_billing_address_2();

		if (!empty($billing2)) {
			$billing_address .= "\n" . $billing2;
		}
		$billing_address .= "\n" . $order->get_billing_city();
		$state = $order->get_billing_state();
		if (!empty($state)) {
			$billing_address .= "\n" . $state;
			unset($state);
		}
		$country = $order->get_billing_country();
		if (!empty($country)) {
			$billing_address .= "\n" . $country;
			unset($country);
		}

		// Fields for hash
		$req = array(
			'action'			  => 'SALE',
			'merchantID'          => $this->settings['merchantID'],
			'amount'              => $amount,
			'countryCode'         => $order->get_billing_country(),
			'currencyCode'        => $order->get_currency(),
			'transactionUnique'   => uniqid($order->get_order_key() . "-"),
			'orderRef'            => $order_id,
			'customerName'        => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'customerCountryCode' => $order->get_billing_country(),
			'customerAddress'     => $billing_address,
			'customerCounty'	  => $order->get_billing_state(),
			'customerTown'		  => $order->get_billing_city(),
			'customerPostCode'    => $order->get_billing_postcode(),
			'customerEmail'       => $order->get_billing_email(),
		);

		$phone = $order->get_billing_phone();
		if (!empty($phone)) {
			$req['customerPhone'] = $phone;
			unset($phone);
		}

		/**
		 * Subscriptions
		 */
		if (class_exists('WC_Subscriptions_Product')) {
			foreach ($order->get_items() as $item) {
				// If the product in the order is a subscription.
				if (WC_Subscriptions_Product::is_subscription($item->get_product_id())) {
					// Add rtagreementType flag
					$req['rtAgreementType'] = 'recurring';
					// Break out of the loop. Only one product needs to be a sub.
					break;
				}
			}
		}

		/**
		 * Wallets
		 */
		if ($this->settings['customerWalletsEnabled'] === 'Y' && is_user_logged_in()) {
			//Try and find the users walletID in the wallets table.
			global $wpdb;
			$wallet_table_name = $wpdb->prefix . 'woocommerce_Blink_Payment_wallets';

			//Query table. Select customer wallet where belongs to user id and current configured merchant.
			$customersWalletID = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT wallets_id FROM $wallet_table_name WHERE users_id = %d AND merchants_id = %d LIMIT 1",
					get_current_user_id(),
					$this->settings['merchantID']
				)
			);

			//If the customer wallet record exists.
			if ($customersWalletID > 0) {
				//Add walletID to request.
				$req['walletID'] = $customersWalletID;
			} else {
				//Create a new wallet.
				$req['walletStore'] = 'Y';
			}

			$req['walletEnabled'] = 'Y';
			$req['walletRequired'] = 'Y';
		}

		return $req;
	}

	/**
	 * Redirect to the URL provided depending on integration type
	 */
	protected function redirect($url)
	{
		if ($this->settings['type'] === 'hosted_v2') {
			echo <<<SCRIPT
<script>window.top.location.href = "$url";</script>;
SCRIPT;
		} else {
			wp_redirect($url . '?XDEBUG_SESSION_START=asdf');
		}
		exit;
	}

	/**
	 * Wallet creation.
	 *
	 * A wallet will always be created if a walletID is returned. Even if payment fails.
	 */
	protected function create_wallet($response)
	{
		global $wpdb;

		if (!isset($response['orderRef'])) {
			return;
		}

		$order = new WC_Order((int)$response['orderRef']);

		//when the wallets is enabled, the user is logged in and there is a wallet ID in the response.
		if ($this->settings['customerWalletsEnabled'] === 'Y' && isset($response['walletID']) && $order->get_user_id() != 0) {
			$wallet_table_name = $wpdb->prefix . 'woocommerce_' . 'Blink_Payment_' . 'wallets';

			$customersWalletID = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT wallets_id FROM $wallet_table_name WHERE users_id = %d AND merchants_id = %d AND wallets_id = %d LIMIT 1",
					$order->get_user_id(),
					$this->settings['merchantID'],
					$response['walletID']
				)
			);

			//If the customer wallet record does not exists.
			if ($customersWalletID === null) {
				//Add walletID to request.
				$wpdb->insert($wallet_table_name, [
					'users_id' => $order->get_user_id(),
					'merchants_id' => $this->settings['merchantID'],
					'wallets_id' => $response['walletID']
				]);
			}
		}
	}

	/**
	 * Process Error
	 */
	protected function process_error($message, $response)
	{
		if (isset($response['responseCode']) && in_array($response['responseCode'], [66315, 66316, 66316, 66320])) {
			$message = 'Double check to make sure that you entered your Credit Card number, CVV2 code, and Expiration Date correctly.';
		}

		$_SESSION['payment_gateway_error'] = $message;

		wc_add_notice($message, 'error');

		$redirectUrl = get_site_url();
		if (isset($response['orderRef'], $response['responseCode'], $response['responseMessage'], $response['amount'])) {
			$order = new WC_Order((int)$response['orderRef']);

			$orderNotes  = "\r\nResponse Code : {$response['responseCode']}\r\n";
			$orderNotes .= "Message : {$response['responseMessage']}\r\n";
			$orderNotes .= "Amount Received : " . number_format($response['amount'] / 100, 2) . "\r\n";
			$orderNotes .= "Unique Transaction Code : {$response['transactionUnique']}";

			$order->update_status('failed');
			$order->add_order_note(__(ucwords($this->method_title) . ' payment failed.' . $orderNotes, $this->lang));

			$redirectUrl = $this->get_return_url($order);
		}


		if (is_ajax()) {
			return [];
		} else {
			$this->redirect($redirectUrl);
			die();
		}
	}

	##########################
	## Other Functions
	##########################

	public function payment_scripts()
	{
		// we need JavaScript to process a token only on cart/checkout pages, right?
		if (!is_cart() && !is_checkout()) {
			return;
		}

		// if our payment gateway is disabled, we do not have to enqueue JS too
		if ('no' === $this->enabled) {
			return;
		}

		// and this is our custom JS in your plugin directory that works with token.js
		wp_register_script('woocommerce_payform', plugins_url('assets/js/payform.js', dirname(__FILE__)), array('jquery'));

		wp_enqueue_script('woocommerce_payform');
	}
}