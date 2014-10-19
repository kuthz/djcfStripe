<?php
/**
* @version		1.0
* @package		DJ Classifieds
* @subpackage	DJ Classifieds Payment Plugin
* @copyright	Copyright (C) 2010 DJ-Extensions.com LTD, All rights reserved.
* @license		http://www.gnu.org/licenses GNU/GPL
* @autor url    http://design-joomla.eu
* @autor email  contact@design-joomla.eu
* @Developer    Lukasz Ciastek - lukasz.ciastek@design-joomla.eu 
* 
* 
* DJ Classifieds is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* DJ Classifieds is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with DJ Classifieds. If not, see <http://www.gnu.org/licenses/>.
* 
*/
defined('_JEXEC') or die('Restricted access');

class plgdjclassifiedspaymentdjcfStripe extends JPlugin
{
    const URL_STRIPE_JS = 'https://js.stripe.com/v2/';
    const JTEXT_PREFIX  = 'PLG_DJCFSTRIPE';
    
    protected $autoloadLanguage = TRUE;
    
    private $db;
    private $id;
    private $Itemid;

    /**
     * Init
     * 
     * @param type $subject
     * @param type $config
     * 
     * @return void
     */
    public function plgdjclassifiedspaymentdjcfStripe(&$subject, $config)
    {
        parent::__construct($subject, $config);
                
//        $this->loadLanguage('plg_djcfStripe');
        
//        $params["plugin_name"] = "djcfStripe";

        //Set the configured stripe information
        $params["test_secret_key"]      = $this->params->get("test_secret_key");
        $params["test_publishable_key"] = $this->params->get("test_publishable_key");
        $params["live_secret_key"]      = $this->params->get("live_secret_key");
        $params["live_publishable_key"] = $this->params->get("live_publishable_key");
        $params["mode"]                 = $this->params->get('mode', "test");
        $params["currency_code"]        = $this->params->get('currency_code', "usd");
        
        $this->params = $params;
    }
    
    /**
     * Get Stripe secret key
     * 
     * @return string
     */
    private function get_secret_key()
    {
        $secret_key = $this->params['test_secret_key'];
        if ($this->params['mode'] === 'live')
        {
            $secret_key = $this->params['live_secret_key'];
        }
        
        return $secret_key;
    }
    
    /**
     * Get Stripe publishable key
     * 
     * @return string
     */
    private function get_publishable_key()
    {
        $publishable_key = $this->params['test_publishable_key'];
        if ($this->params['mode'] === 'live')
        {
            $publishable_key = $this->params['live_publishable_key'];
        }
        
        return $publishable_key;
    }
    
    /**
     * Process Payment
     * 
     * @return string
     */
    public function onProcessPayment()
    {
        $JInput = JFactory::getApplication()->input;
        $ptype  = $JInput->getString('ptype', '');
        $id     = $JInput->getInt('id', '');
        $html  = "";
        
        if($ptype == $this->_name)
        {            
            $action = $JInput->getString('pactiontype','');
            switch ($action)
            {
                case "notify" :
                    $html = $this->_notify_url();
                    break;

                case "paymentmessage" :
                    $html = $this->_paymentsuccess();
                    break;
                
                case "process" :
                default :
                    $html =  $this->process($id);
                    break;
            }
        }

        return $html;
    }
    
    /**
     * Get payment record  
     * 
     * @return mixed
     */
    private function get_payment($item_id)
    {        
        $select_payments = $this->db->getQuery(TRUE)
                ->select("p.*")
                ->from($this->db->quoteName("#__djcf_payments", "p"))
                ->where($this->db->quoteName("p.id") . " = " . $this->db->quote($item_id));
        
        $this->db->setQuery($select_payments);

        return $this->db->loadObject();
    }
    
    /**
     * Update payment record
     * 
     * @return void
     */
    private function update_payment($item_id)
    {
        $update_payment = $this->db->getQuery(TRUE)
                   ->update($this->db->quoteName("#__djcf_payments"))
                   ->set($this->db->quoteName("status") . " = 'Completed'")
                   ->where($this->db->quoteName("id") . " = " . $this->db->quote($item_id))
                   ->where($this->db->quoteName("method") . " = " . $this->db->quote($this->_name));
        
        $this->db->setQuery($update_payment)->execute();
    }
    
    /**
     * Notify url
     */
    public function _notify_url()
    {
        require_once(JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name . '/stripe/lib/Stripe.php');				
                
        $this->db = JFactory::getDBO();        
        $app      = JFactory::getApplication();
        $user     = JFactory::getUser();
        $JInput   = $app->input;        

        $this->Itemid = $JInput->getInt("Itemid", '0');
        $this->id     = $JInput->getInt('id', '0');
        $stripeToken  = $JInput->getString('stripeToken', '');
        $ptype        = $JInput->getString('ptype');
        $type         = $JInput->getString('type', '');
        $par          = &JComponentHelper::getParams('com_djclassifieds');
        $row          = &JTable::getInstance('Payments', 'DJClassifiedsTable');
        $description  = array();
                        
        if ($type == 'prom_top')
        {        	
            $select_promotion_top = $this->db->getQuery(TRUE)
                    ->select("i.*")
                    ->from($this->db->quoteName("#__djcf_items", "i"))
                    ->where($this->db->quoteName("i.id") . " = " . $this->db->quote($this->id));
            $this->db->setQuery($select_promotion_top, 0, 1);
            $item = $this->db->loadObject();
            if (!isset($item))
            {
                $this->redirect_to_ads_listing_with_error(JText::_('COM_DJCLASSIFIEDS_WRONG_AD'));                
            }        						 

            $row->item_id    = $this->id;
            $row->user_id    = $user->id;
            $row->method     = $ptype;
            $row->status     = 'Start';
            $row->ip_address = $_SERVER['REMOTE_ADDR'];
            $row->price      = $par->get('promotion_move_top_price', 0);
            $row->type       = 2;
            $row->store();
            
            $amount        = $par->get('promotion_move_top_price', 0);
            $itemname      = $item->name;
            $item_id       = $row->id;
            $item_cid      = '&cid=' . $item->cat_id;
            $description[] = JText::_(self::JTEXT_PREFIX . '_PROMOTION_TOP') . ' - ' . $itemname;
        }
        else if($type == 'points')
        {
            $select_points = $this->db->getQuery(TRUE)
                    ->select("p.*")
                    ->from($this->db->quoteName("#__djcf_points", "p"))
                    ->where($this->db->quoteName("p.id") . " = " . $this->db->quote($this->id));
            $this->db->setQuery($select_points, 0 , 1);
            $points = $this->db->loadObject();
                        
            if (!isset($points))
            {
                $this->redirect_to_ads_listing_with_error(JText::_('COM_DJCLASSIFIEDS_WRONG_POINTS_PACKAGE'));
            }
            
            $row->item_id    = $this->id;
            $row->user_id    = $user->id;
            $row->method     = $ptype;
            $row->status     = 'Start';
            $row->ip_address = $_SERVER['REMOTE_ADDR'];
            $row->price      = $points->price;
            $row->type       = 1;
            $row->store();

            $amount        = $points->price;
            $itemname      = $points->name;
            $item_id       = $row->id;
            $item_cid      = '';
            $description[] = JText::_(self::JTEXT_PREFIX . '_POINTS') . ' - ' . $itemname;
        }
        else
        {
            $amount = 0;
            
            //Get the item information
            $select_item = $this->db->getQuery(TRUE)                    
                    ->select(array("i.*", "c.name as c_name" ,"c.price as c_price"))
                    ->from($this->db->quoteName('#__djcf_items', "i"))
                    ->leftjoin($this->db->quoteName('#__djcf_categories', "c") . " ON " . $this->db->quoteName("c.id") . " =  " . $this->db->quoteName("i.cat_id"))
                    ->where($this->db->quoteName("i.id") . " = " . $this->db->quote($this->id));
            
            $this->db->setQuery($select_item);
            $item = $this->db->loadObject();
                            
            if (!isset($item))
            {
                $this->redirect_to_ads_listing_with_error(JText::_('COM_DJCLASSIFIEDS_WRONG_AD'));
            }
                        
            if (strstr($item->pay_type, 'cat'))
            {
                $amount += $item->c_price / 100;
                $description[] = JText::_(self::JTEXT_PREFIX . '_CATEGORY') . " " . $item->c_name;
            }
            
            if (strstr($item->pay_type, 'duration_renew'))
            {
                $select_duration_renew = $this->db->getQuery(TRUE)
                        ->select("d.price_renew")
                        ->from($this->db->quoteName("#__djcf_days", "d"))
                        ->where($this->db->quoteName("d.days") . " = " . $this->db->quote($item->exp_days));
                
                $this->db->setQuery($select_duration_renew);
                $amount += $this->db->loadResult();
                $description[] = JText::_(self::JTEXT_PREFIX . '_DURATION_RENEW') . ' ' . $item->exp_days . ' ' . JText::_(self::JTEXT_PREFIX . "_DAYS");
            }
            else if (strstr($item->pay_type, 'duration'))
            {
                 $select_duration = $this->db->getQuery(TRUE)
                         ->select("d.price")
                         ->from($this->db->quoteName("#__djcf_days", "d"))
                         ->where($this->db->quoteName("d.days") . " = " . $this->db->quote($item->exp_days));
                                
                $this->db->setQuery($select_duration);
                $amount += $this->db->loadResult();
                $description[] = JText::_(self::JTEXT_PREFIX . '_DURATION') . ' ' . $item->exp_days . ' ' . JText::_(self::JTEXT_PREFIX . "_DAYS");
            }

            $select_promotions = $this->db->getQuery(TRUE)
                     ->select("p.*")
                     ->from($this->db->quoteName("#__djcf_promotions", "p"))
                     ->where($this->db->quoteName("p.published") . " = 1")
                     ->order("p.id");
            $this->db->setQuery($select_promotions);
            $promotions = $this->db->loadObjectList();
                        
            foreach ($promotions as $prom)
            {
                if (strstr($item->pay_type, $prom->name))
                {
                    $amount += $prom->price;
                    $description[] .= JText::_($prom->label);
                }
            }
            
            /*$query = 'DELETE FROM #__djcf_payments WHERE item_id= "'.$id.'" ';
             $db->setQuery($query);
            $db->query();


            $query = 'INSERT INTO #__djcf_payments ( item_id,user_id,method,  status)' .
            ' VALUES ( "'.$id.'" ,"'.$user->id.'","'.$ptype.'" ,"Start" )'
            ;
            $db->setQuery($query);
            $db->query();*/

            $row->item_id    = $this->id;
            $row->user_id    = $user->id;
            $row->method     = $ptype;
            $row->status     = 'Start';
            $row->ip_address = $_SERVER['REMOTE_ADDR'];
            $row->price      = $amount;
            $row->type       = 0;
            $row->store();

            $itemname = $item->name;
            $item_id  = $row->id;
            $item_cid = '&cid=' . $item->cat_id;
        }
        
        $payment = $this->get_payment($item_id);
                              
        try 
        { 
            Stripe::setApiKey($this->get_secret_key());
            $charge = Stripe_Charge::create(array(
                "amount"        => $amount * 100, // amount in cents 
                "currency"      => $this->params["currency_code"],
                "card"          => $stripeToken,
                "description"   => implode(', ', $description),
                "receipt_email" => $user->email,
                "metadata"      => array(
                        "user.email" => $user->email,
                        "item.id"    => $item_id,
                        "payment.id" => $payment->id
                    )
                )
            )->__toArray();    
            
            $transaction_id = $charge['id'];
                        
            $this->update_payment($item_id);
            
            if ($type == 'prom_top')
            {
                $date_sort = date("Y-m-d H:i:s");
                
                $update_promotion_top = $this->db->getQuery(TRUE)
                        ->update($this->db->quoteName('#__djcf_items'))
                        ->set($this->db->quoteName('date_sort') . " = " . $this->db->quote($date_sort))
                        ->where($this->db->quoteName("id") . " = " . $this->db->quote($this->id));
                
                $this->db->setQuery($update_promotion_top);
                $this->db->query();
            }
            else if ($type == 'points')
            {
                $select_points = $this->db->getQuery(TRUE)
                        ->select($this->db->quoteName("p.points"))
                        ->from($this->db->quoteName("#__djcf_points", "p"))
                        ->where($this->db->quoteName("p.id") . " = " . $this->db->quote($this->id));					
                $this->db->setQuery($select_points);
                $points = $this->db->loadResult();
                                
                $description   = JText::_('COM_DJCLASSIFIEDS_POINTS_PACKAGE') . " Stripe <br />" . JText::_('COM_DJCLASSIFIEDS_PAYMENT_ID') . ' ' . $payment->id;
                $insert_points = $this->db->getQuery(TRUE)
                        ->insert($this->db->quoteName("#__djcf_users_points"))
                        ->set($this->db->quoteName("user_id") . " = " . $this->db->quote($payment->user_id))
                        ->set($this->db->quoteName("points") . " = " . $this->db->quote($points))
                        ->set($this->db->quoteName("description") . " = " . $this->db->quote($description));
                
                $this->db->setQuery($insert_points)->execute();                														
            }
            else
            {
                $select_category = $this->db->getQuery(TRUE)
                        ->select("c.*")
                        ->from(array($this->db->quoteName("#__djcf_items", "i"), $this->db->quoteName("#__djcf_categories", "c")))
                        ->where($this->db->quoteName("i.cat_id") . " = " . $this->db->quoteName("c.id"))
                        ->where($this->db->quoteName("i.id") . " = " . $this->db->quote($this->id));
                $this->db->setQuery($select_category);
                $cat = $this->db->loadObject();
                
                $pub=0;
                if (($cat->autopublish=='1') || ($cat->autopublish=='0' && $par->get('autopublish')=='1'))
                {						
                    $pub = 1;							 						
                }

                $update_item = $this->db->getQuery(TRUE)
                        ->update($this->db->quoteName("#__djcf_items"))
                        ->set($this->db->quoteName("payed") . " = 1")
                        ->set($this->db->quoteName("pay_type") . " = ''")
                        ->set($this->db->quoteName("published") . " = " . $this->db->quote($pub))
                        ->where($this->db->quoteName("id") . " = " . $this->db->quote($this->id));        
                                                
                $this->db->setQuery($update_item)->execute();                
            }						

            $app->enqueueMessage(sprintf(JTExt::_('PLG_DJCFSTRIPE_AFTER_SUCCESSFULL_MSG'), $transaction_id));            
            $app->redirect('index.php?option=com_djclassifieds&view=items&cid=0&Itemid=' . $this->Itemid);
        }
        catch(Stripe_CardError $e) // Since it's a decline, Stripe_CardError will be caught
        {
            $this->handle_stripe_card_error($e);
        } 
        catch (Stripe_InvalidRequestError $e) // Invalid parameters were supplied to Stripe's API
        { 
            $this->handle_stripe_general_error();
        } 
        catch (Stripe_AuthenticationError $e) // Authentication with Stripe's API failed (maybe you changed API keys recently) 
        {
            $this->handle_stripe_general_error();         
        }
        catch (Stripe_ApiConnectionError $e) // Network communication with Stripe failed 
        { 
            $this->handle_stripe_general_error();         
        }
        catch (Stripe_Error $e) // Display a very generic error to the user, and maybe send // yourself an email
        { 
            $this->handle_stripe_general_error();           
        }
        catch (Exception $e) // Something else happened, completely unrelated to Stripe }
        {             
            $this->handle_stripe_general_error();
        }	
    }

    /**
     * Handle stripe card error
     * 
     * @param Stripe_CardError $exception Stripe Card Error
     * 
     * @return void
     */
    private function handle_stripe_card_error(Stripe_CardError $exception)
    {
        $body    = $exception->getJsonBody();
        $error   = $body['error'];
        $message = $this->stripe_error_to_text($error['type'], $error['code']);

        $this->redirect_to_payment_page_with_error($message);
    }
    
    /**
     * Stripe error to text
     * 
     * @param string $type Error type
     * @param string $code Error code
     * 
     * @return string
     */
    private function stripe_error_to_text($type, $code)
    {        
        return JText::_(self::JTEXT_PREFIX . '_' . strtoupper($type) . '_' . strtoupper($code));
    }
    
    /**
     * Handle stripe general error
     * 
     * @return void
     */
    private function handle_stripe_general_error()
    {
        $message = JText::_(self::JTEXT_PREFIX . "_GENERAL_ERROR");
        $this->redirect_to_payment_page_with_error($message);
    }
    
    /**
     * Redirect to the payment page with error
     * 
     * @param string $message Message
     * 
     * @return void
     */
    private function redirect_to_payment_page_with_error($message)
    {
        $app = JFactory::getApplication();      
        
        $app->enqueueMessage($message, 'Error');
        $app->redirect('index.php?option=com_djclassifieds&view=payment&id=' . $this->id . '&Itemid=' . $this->Itemid);
    }
    
    /**
     * Redirect to ads listing with error
     * 
     * @param string $message
     * 
     * @return void
     */
    private function redirect_to_ads_listing_with_error($message)
    {
        $app = JFactory::getApplication();      
        
        $app->enqueueMessage($message, 'Error');
        $app->redirect("index.php?option=com_djclassifieds&view=items&cid=0");
    }
        
    /**
     * Payment method list
     * 
     * @param type $val
     * 
     * @return string
     */
    public function onPaymentMethodList($val)
    {
        $html             = '';        
        $publishable_key  = $this->get_publishable_key();
        $user             = JFactory::getUser();
                        
        if (!$user->guest && $publishable_key !== '')
        {
            $JInput     = JFactory::getApplication()->input;
            $Itemid     = $JInput->getInt("Itemid", '0');
            $type       = $JInput->getCmd('type', '');
            $document   = JFactory::getDocument();
            
            //Add some style and document ready script
            $document->addStyleDeclaration('.invalid { border-color: red !important; }');
            $document->addScriptDeclaration('jQuery( document ).ready(function() {
                    Stripe.setPublishableKey(\'' . $publishable_key . '\');
                });'
            );

            //Include stripe javascript to the page 
            JHtml::_('jquery.framework');
            JHtml::_('script', self::URL_STRIPE_JS);
            JHtml::_('script', 'plugins/djclassifiedspayment/' . $this->_name . '/js/' . $this->_name . '.js');

            $action_url = JRoute::_('index.php?option=com_djclassifieds&task=processPayment&ptype=' . $this->_name . '&pactiontype=notify&Itemid=' . $Itemid . '&id=' .$val["id"], FALSE);
            //Build the form
            $form = "<form id='stripepayment' name='stripepayment' action='" . $action_url . "' method='post' class='form-horizontal form-validate'>
                        <fieldset>
                            <div id='stripeerror' class='alert alert-error' style='display:none'>                                   
                                <div>
                                    <p id='invalid-holder-name' style='display:none'>" . JText::_(self::JTEXT_PREFIX . "_CARD_ERROR_INVALID_HOLDER_NAME") . "</p>
                                    <p id='invalid-credit-card-type' style='display:none'>" . JText::_(self::JTEXT_PREFIX . "_CARD_ERROR_INVALID_CREDIT_CARD_TYPE") . "</p>
                                    <p id='incorrect-number' style='display:none'>" . JText::_(self::JTEXT_PREFIX . "_CARD_ERROR_INCORRECT_NUMBER") . "</p>
                                    <p id='invalid-expiry-month' style='display:none'>" . JText::_(self::JTEXT_PREFIX . "_CARD_ERROR_INVALID_EXPIRY_MONTH") . "</p>
                                    <p id='invalid-expiry-year' style='display:none'>" . JText::_(self::JTEXT_PREFIX . "_CARD_ERROR_INVALID_EXPIRY_YEAR") . "</p>
                                    <p id='invalid-cvc' style='display:none'>" . JText::_(self::JTEXT_PREFIX . "_CARD_ERROR_INVALID_CVC") . "</p>
                                </div>
                            </div>
                            <div class='control-group'>
                                <div class='controls'>
                                    <input type='hidden' name='option' value='com_djclassifieds'/>
                                    <input type='hidden' name='task' value='processPayment'/>
                                    <input type='hidden' name='ptype' value='" . $this->_name . "'/>
                                    <input type='hidden' name='pactiontype' value='notify'/>
                                    <input type='hidden' name='type' value='" . $type ."'/>
                                </div>
                            </div>
                            <div class='control-group'>
                                <div class='control-label'>
                                    <label id='fullusername-lbl' for='fullusername' class='required'>"
                                        . JText::_(self::JTEXT_PREFIX . "_CREDIT_CARD_HOLDER_NAME") . "<span class='star'>&#160;*</span>
                                    </label>
                                </div>
                                <div class='controls'>
                                    <input type='text' class='required' autocomplete='off' name='fullusername' id='fullusername' value='" . $user->name . "'/>
                                </div>
                            </div>

                            <div class='control-group'>
                                <div class='control-label'>
                                    <label id='card_type-lbl' for='card_type' class='required'>"
                                        . JText::_(self::JTEXT_PREFIX . "_CREDIT_CARD_TYPE") . "<span class='star'>&#160;*</span>
                                    </label>
                                </div>
                                <div class='controls'>
                                    <select id='card_type' name='cart_type' class='required'>                                        
                                        <option value=''></option>
                                        <option value='Visa'>Visa</option>
                                        <option value='MasterCard'>Master Card</option>
                                        <option value='American Express'>American Express</option>
                                    </select>
                                </div>
                            </div>

                            <div class='control-group'>
                                <div class='control-label'>
                                    <label id='card_number-lbl' for='card_number' class='required'>"
                                        . JText::_(self::JTEXT_PREFIX . "_CREDIT_CARD_NUMBER") . "<span class='star'>&#160;*</span>
                                    </label>
                                </div>
                                <div class='controls'>
                                    <input type='text' class='required' autocomplete='off' name='card_number' id='card_number'/>
                                </div>
                            </div>

                            <div class='control-group'>
                                <div class='control-label'>
                                    <label id='card_cvc-lbl' for='card_cvc' class='required'>"
                                        . JText::_(self::JTEXT_PREFIX . "_CREDIT_CARD_SECURITY_CODE") . "<span class='star'>&#160;*</span>
                                    </label>
                                </div>
                                <div class='controls'>
                                    <input type='text' class='required' autocomplete='off' name='card_cvc' id='card_cvc' size='5'/>
                                </div>
                            </div>

                            <div class='control-group'>
                                <div class='control-label'>
                                    <label id='exp_month-lbl' for='exp_month' class='required'>"
                                        . JText::_(self::JTEXT_PREFIX . "_EXPIRATION_DATE") . "<span class='star'>&#160;*</span>
                                    </label>
                                </div>
                                <div class='controls'>
                                    <input id='exp_month' name='exp_month' class='required' autocomplete='off' size='3' placeholder='" . JText::_(self::JTEXT_PREFIX . "_EXPIRATION_DATE_MM") . "'/>
                                    <input id='exp_year' name='exp_year' class='required' autocomplete='off' size='5' placeholder='" . JText::_(self::JTEXT_PREFIX . "_EXPIRATION_DATE_YYYY") . "'/>
                                </div>
                            </div>
                        </fieldset>
                </form>";
            
            $html ='<table cellpadding="5" cellspacing="0" width="100%" border="0">
                <tr>
                    <td class="td2">
                        <h2>' . JText::_(self::JTEXT_PREFIX . "_PAYMENT_METHOD_NAME") . '</h2>
                            <p style="text-align:justify;">' . JText::_(self::JTEXT_PREFIX . "_PAYMENT_METHOD_DESC") . "<br/>" . $form . '</p>
                    </td>
                    <td class="td3" width="130" align="center">
                        <a class="button"  style="text-decoration:none;" onclick="stripeValidate();" href="javascript:void(0);">' . JText::_('COM_DJCLASSIFIEDS_BUY_NOW') . '</a>
                    </td>
                </tr>
                </table>';
        }

        return $html;
    }
}