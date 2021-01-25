<?php
defined('_JEXEC') or die('Restricted access');
/**
 * @package Joomla
 * @subpackage Paymant plugin for integration with Roskassa
 * @copyright 2018-2021 CRMBees
 * @author CRMBees https://crmbees.com
 *
 * This plugin is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License
 *  as published by the Free Software Foundation; either version 2 (GPLv2) of the License, or (at your option) any later version.
 *
 * This plugin is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 *  without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * See the GNU General Public License for more details <http://www.gnu.org/licenses/>.
 *
 **/

if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

if (!class_exists('vmPSPlugin')) require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVmpaymentRoskassa extends vmPSPlugin
{
    public static $_this = false;
	
    function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);
		$jlang = JFactory::getLanguage ();
		$jlang->load ('plg_vmpayment_roskassa', JPATH_ADMINISTRATOR, NULL, TRUE);
        $this->_loggable   = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush        = array(
            'payment_logos' 	=> array('', 'char'),
            'payment_currency'	=> array(0, 'int'),
			'merchant_url' 		=> array('//pay.roskassa.net/', 'string'),
            'merchant_id' 		=> array('', 'string'),
            'secret_key' 		=> array('', 'string'),
            'status_success' 	=> array('', 'char'),
            'status_pending' 	=> array('', 'char'),
            'status_canceled' 	=> array('', 'char'),
			'order_desc' 		=> array('', 'string'),
			'admin_email' 		=> array('', 'string'),
			'log_file' 			=> array('', 'string')
        );
        
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }
    
    protected function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Roskassa Table');
    }
    
    function getTableSQLFields()
    {
        $SQLfields = array(
            'id' => 'tinyint(1) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
            'order_number' => 'char(32) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
            'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
            'payment_order_total' => 'decimal(15,2) NOT NULL DEFAULT \'0.00\' ',
            'payment_currency' => 'char(3) '
        );
        
        return $SQLfields;
    }
	
	public function plgVmOnPaymentNotification()
    {
		if (!class_exists ('VirtueMartModelOrders')) 
		{
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}

		$roskassa_data = vRequest::getRequest();
		
		if (isset($roskassa_data['sign']))
		{
			$err = false;
			$message = '';
			$payment = $this->getDataByOrderId($roskassa_data['order_id']);
			$method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);
			
			// запись логов

            $log_text =
                "--------------------------------------------------------\n" .
                "id you	shoop   	" . $roskassa_data["shop_id"] . "\n" .
                "amount				" . $roskassa_data["amount"] . "\n" .
                "kassa operation id " . $roskassa_data["Initid"] . "\n" .
                "mercant order id	" . $roskassa_data["order_id"] . "\n" .
                "currency			" . $roskassa_data["currency"] . "\n" .
                "sign				" . $roskassa_data["sign"] . "\n\n";
			
			$log_file = $method->log_file;
			
			if (!empty($log_file))
			{
				file_put_contents($_SERVER['DOCUMENT_ROOT'] . $log_file, $log_text, FILE_APPEND);
			}
			
			// проверка ip

						$signArr = $_POST;
						unset($signArr["sign"]);
						ksort($signArr);
						$str = http_build_query($signArr);
            $sign_hash = md5($str . $method->secret_key);

			if (!$err)
			{
				// загрузка заказа
				
				$order_number = $payment->order_number;
				$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
				$order['virtuemart_order_id'] = $payment->virtuemart_order_id;
				$order['virtuemart_user_id'] = $payment->virtuemart_user_id;
				$order['order_total'] = $roskassa_data['amount'];
				$order['customer_notified'] = 0;
				$order['virtuemart_vendor_id'] = 1;
				$order['comments'] = vmText::sprintf('VMPAYMENT_ROSKASSA_PAYMENT_CONFIRMED', $order_number);
				$modelOrder = new VirtueMartModelOrders();
				$order_curr = ($payment->payment_currency == 'RUR') ? 'RUB' : $payment->payment_currency;
				$order_amount = number_format($payment->payment_order_total, 2, '.', '');
				
				// проверка суммы
			
				if ($roskassa_data['amount'] != $order_amount)
				{
					$message .= " - Wrong amount\n";
					$err = true;
				}

				$db = JFactory::getDBO();
				$q = "SELECT order_status FROM #__virtuemart_orders WHERE `virtuemart_order_id`='" . $virtuemart_order_id . "'";
				$db->setQuery($q);
				$db->query();
				$order_status = $db->loadResult();

				if (!$order_status)
				{
					$message .= " order does not exist\n";
					$err = true;
				}
				
				// проверка статуса
				
				if (!$err) {

					if ($roskassa_data["sign"] == $sign_hash) {

                        if ($order_status != $method->status_success) {
                            $order['order_status'] = $method->status_success;
                            $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
                            exit ('YES');
                        }
                    }
                    else {
						
                        if ($order_status != $method->status_canceled) {

                            $order['order_status'] = $method->status_canceled;
                            $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
                        }
                        $message .= " - do not match the digital signature\n";
                        $err = true;
                    }
				}
			}
			
			if ($err)
			{
				$to = $method->admin_email;

				if (!empty($to))
				{
					$message = "Failed to make the payment through the system Free-Kassa for the following reasons:\n\n" . $message . "\n" . $log_text;
					$headers = "From: no-reply@" . $_SERVER['HTTP_HOST'] . "\r\n" . 
					"Content-type: text/plain; charset=utf-8 \r\n";
					mail($to, "Error payment", $message, $headers);
				}
				
				echo $roskassa_data['order_id'] . '|error' . $message;
			}

		}
		
		return true;
    }
	
	function plgVmOnPaymentResponseReceived (&$html) 
	{
		if (!class_exists ('VirtueMartCart')) {
			require(VMPATH_SITE . DS . 'helpers' . DS . 'cart.php');
		}
		if (!class_exists ('shopFunctionsF')) {
			require(VMPATH_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		}
		if (!class_exists ('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}

		VmConfig::loadJLang('com_virtuemart_orders', TRUE);
		$freekassa_data = vRequest::getRequest();


		// the payment itself should send the parameter needed.
		$virtuemart_paymentmethod_id = vRequest::getInt ('pm', 0);
		$order_number = vRequest::getString ('on', 0);
		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
			return NULL;
		} // Another method was selected, do nothing

		if (!$this->selectedThisElement ($method->payment_element)) {
			return NULL;
		}

		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($order_number))) {
			return NULL;
		}

		if (!($paymentTable = $this->getDataByOrderId ($virtuemart_order_id))) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}
		VmConfig::loadJLang('com_virtuemart');
		$orderModel = VmModel::getModel('orders');
		$order = $orderModel->getOrder($virtuemart_order_id);

		vmdebug ('Freekassa plgVmOnPaymentResponseReceived', $freekassa_data);
		$payment_name = $this->renderPluginName ($method);
		$html = $this->_getPaymentResponseHtml ($paymentTable, $payment_name);
		$link=	JRoute::_("index.php?option=com_virtuemart&view=orders&layout=details&order_number=".$order['details']['BT']->order_number."&order_pass=".$order['details']['BT']->order_pass, false) ;

		$html .='<br />
		<a class="vm-button-correct" href="'.$link.'">'.vmText::_('COM_VIRTUEMART_ORDER_VIEW_ORDER').'</a>';

		$cart = VirtueMartCart::getCart ();
		$cart->emptyCart ();
		return TRUE;
	}
	
	function plgVmOnUserPaymentCancel () 
	{
		if (!class_exists ('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}

		$order_number = vRequest::getString ('on', '');
		$virtuemart_paymentmethod_id = vRequest::getInt ('pm', '');
		if (empty($order_number) or
			empty($virtuemart_paymentmethod_id) or
			!$this->selectedThisByMethodId ($virtuemart_paymentmethod_id)
		) {
			return NULL;
		}

		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($order_number))) {
			return NULL;
		}

		if (!($paymentTable = $this->getDataByOrderId ($virtuemart_order_id))) {
			return NULL;
		}

		VmInfo (vmText::_ ('VMPAYMENT_SKRILL_PAYMENT_CANCELLED'));
		$session = JFactory::getSession ();
		$return_context = $session->getId ();
		if (strcmp ($paymentTable->user_session, $return_context) === 0) {
			$this->handlePaymentUserCancel ($virtuemart_order_id);
		}

		return TRUE;
	}

    function plgVmConfirmedOrder($cart, $order)
    {
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)))
		{
			return null;
		}
		
		if (!$this->selectedThisElement($method->payment_element)) 
		{
			return false;
        }
		
        $lang     = JFactory::getLanguage();
        $filename = 'com_virtuemart';
        $lang->load($filename, JPATH_ADMINISTRATOR);
        $session        = JFactory::getSession();
        $return_context = $session->getId();
        $this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');
        if (!class_exists('VirtueMartModelOrders'))
		{
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		
        if (!$method->payment_currency)
		{
            $this->getPaymentCurrency($method);
		}

        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
        $db =& JFactory::getDBO();
        $db->setQuery($q);
		
		$m_url = $method->merchant_url;
		
        $currency = strtoupper($db->loadResult());
		
        if ($currency == 'RUR')
		{
			$currency = 'RUB';
		}
		
		$amount = number_format($order['details']['BT']->order_total, 2, '.', '');
        $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order['details']['BT']->order_number);
        $desc = base64_encode($method->order_desc);

		$m_key = $method->secret_key;
		$arHash = array(
			'shop_id' => $method->merchant_id,
			'amount' => $amount,
      'order_id' => $virtuemart_order_id,
      'currency' => $currency,
      'test' => 1
		);
		ksort($arHash);
		$str = http_build_query($arHash);
		$sign = md5($str . $m_key);

        $this->_virtuemart_paymentmethod_id      = $order['details']['BT']->virtuemart_paymentmethod_id;
        $dbValues['payment_name']                = $this->renderPluginName($method);
        $dbValues['order_number']                = $order['details']['BT']->order_number;
        $dbValues['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
        $dbValues['payment_currency']            = $currency;
        $dbValues['payment_order_total']         = $amount;
        $this->storePSPluginInternalData($dbValues);
       
		$html = '';
		$html .= '<form method="POST" name="vm_roskassa_form" action="' . $m_url . '">';
		$html .= '<input type="hidden" name="shop_id" value="' . $method->merchant_id . '">';
		$html .= '<input type="hidden" name="amount" value="' . $amount . '">';
		$html .= '<input type="hidden" name="order_id" value="' . $virtuemart_order_id . '">';
		$html .= '<input type="hidden" name="currency" value="' . $currency . '">';
		$html .= '<input type="hidden" name="sign" value="' . $sign . '">';
		$html .= '<input type="hidden" name="test" value="1">';
		$html .= '</form>';
        $html .= '<script type="text/javascript">';
        $html .= 'document.forms.vm_roskassa_form.submit();';
        $html .= '</script>';
		
        return $this->processConfirmedOrderPaymentResponse(true, $cart, $order, $html, $this->renderPluginName($method, $order), 'P');
    }
    
    function plgVmOnShowOrderBEPayment ($virtuemart_order_id, $payment_method_id) {

		if (!$this->selectedThisByMethodId ($payment_method_id)) {
			return NULL;
		} // Another method was selected, do nothing

		if (!($paymentTable = $this->_getInternalData ($virtuemart_order_id))) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}

		$this->getPaymentCurrency ($paymentTable);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' .
			$paymentTable->payment_currency . '" ';
		$db = JFactory::getDBO ();
		$db->setQuery ($q);
		$currency_code_3 = $db->loadResult ();
		$html = '<table class="adminlist table">' . "\n";
		$html .= $this->getHtmlHeaderBE ();
		$html .= $this->getHtmlRowBE ('PAYMENT_NAME', $paymentTable->payment_name);

		$code = "mb_";
		foreach ($paymentTable as $key => $value) {
			if (substr ($key, 0, strlen ($code)) == $code) {
				$html .= $this->getHtmlRowBE ($key, $value);
			}
		}
		$html .= '</table>' . "\n";
		return $html;
	}
    
    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        return 0;
    }
    
    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }
    
    function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

		return $this->onStoreInstallPluginTable ($jplugin_id);
	}
    
    public function plgVmOnSelectCheckPayment (VirtueMartCart $cart,  &$msg) {

		return $this->OnSelectCheck ($cart);
	}
    
    public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {

		return $this->displayListFE ($cart, $selected, $htmlIn);
	}
    
    public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {

		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}
    
    function plgVmgetPaymentCurrency ($virtuemart_paymentmethod_id, &$paymentCurrencyId) 
	{
		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) 
		{
			return NULL;
		}

		if (!$this->selectedThisElement ($method->payment_element)) 
		{
			return FALSE;
		}

		$this->getPaymentCurrency ($method);
		$paymentCurrencyId = $method->payment_currency;
	}
	
    function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {

		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}
    
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
    
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }
    
    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }
    
    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }
    
	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}
	
    protected function displayLogos($logo_list)
    {
        $img = "";
        
        if (!(empty($logo_list))) 
		{
            $url = JURI::root() . str_replace('\\', '/', str_replace(JPATH_ROOT, '', dirname(__FILE__))) . '/';
            if (!is_array($logo_list))
                $logo_list = (array) $logo_list;
            foreach ($logo_list as $logo) 
			{
                $alt_text = substr($logo, 0, strpos($logo, '.'));
                $img .= '<img align="middle" src="' . $url . $logo . '"  alt="' . $alt_text . '" /> ';
            }
        }
        return $img;
    }

    private function notifyCustomer($order, $order_info)
    {
        $lang     = JFactory::getLanguage();
        $filename = 'com_virtuemart';
        $lang->load($filename, JPATH_ADMINISTRATOR);
        if (!class_exists('VirtueMartControllerVirtuemart'))
            require(JPATH_VM_SITE . DS . 'controllers' . DS . 'virtuemart.php');
        
        if (!class_exists('shopFunctionsF'))
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
        $controller = new VirtueMartControllerVirtuemart();
        $controller->addViewPath(JPATH_VM_ADMINISTRATOR . DS . 'views');
        
        $view = $controller->getView('orders', 'html');
        if (!$controllerName)
            $controllerName = 'orders';
        $controllerClassName = 'VirtueMartController' . ucfirst($controllerName);
        if (!class_exists($controllerClassName))
            require(JPATH_VM_SITE . DS . 'controllers' . DS . $controllerName . '.php');
        
        $view->addTemplatePath(JPATH_COMPONENT_ADMINISTRATOR . '/views/orders/tmpl');
        
        $db = JFactory::getDBO();
        $q  = "SELECT CONCAT_WS(' ',first_name, middle_name , last_name) AS full_name, email, order_status_name
			FROM #__virtuemart_order_userinfos
			LEFT JOIN #__virtuemart_orders
			ON #__virtuemart_orders.virtuemart_user_id = #__virtuemart_order_userinfos.virtuemart_user_id
			LEFT JOIN #__virtuemart_orderstates
			ON #__virtuemart_orderstates.order_status_code = #__virtuemart_orders.order_status
			WHERE #__virtuemart_orders.virtuemart_order_id = '" . $order['virtuemart_order_id'] . "'
			AND #__virtuemart_orders.virtuemart_order_id = #__virtuemart_order_userinfos.virtuemart_order_id";
        $db->setQuery($q);
        $db->query();
        $view->user  = $db->loadObject();
        $view->order = $order;
        JRequest::setVar('view', 'orders');
        $user = $this->sendVmMail($view, $order_info['details']['BT']->email, false);
        if (isset($view->doVendor)) {
            $this->sendVmMail($view, $view->vendorEmail, true);
        }
    }

    private function sendVmMail(&$view, $recipient, $vendor = false)
    {
        ob_start();
        $view->renderMailLayout($vendor, $recipient);
        $body = ob_get_contents();
        ob_end_clean();
        
        $subject = (isset($view->subject)) ? $view->subject : JText::_('COM_VIRTUEMART_DEFAULT_MESSAGE_SUBJECT');
        $mailer  = JFactory::getMailer();
        $mailer->addRecipient($recipient);
        $mailer->setSubject($subject);
        $mailer->isHTML(VmConfig::get('order_mail_html', true));
        $mailer->setBody($body);
        
        if (!$vendor) 
		{
            $replyto[0] = $view->vendorEmail;
            $replyto[1] = $view->vendor->vendor_name;
            $mailer->addReplyTo($replyto);
        }
        
        if (isset($view->mediaToSend)) 
		{
            foreach ((array) $view->mediaToSend as $media) 
			{
                $mailer->addAttachment($media);
            }
        }
        return $mailer->Send();
    }
    
}