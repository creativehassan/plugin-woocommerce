<?php
/**
 * PayZen V2-Payment Module version 1.4.1 for WooCommerce 2.x-3.x. Support contact : support@payzen.eu.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * @author    Lyra Network (http://www.lyra-network.com/)
 * @author    Alsacréations (Geoffrey Crofte http://alsacreations.fr/a-propos#geoffrey)
 * @copyright 2014-2017 Lyra Network and contributors
 * @license   http://www.gnu.org/licenses/old-licenses/gpl-2.0.html  GNU General Public License (GPL v2)
 * @category  payment
 * @package   payzen
 */

/**
 * PayZen Payment Gateway : multiple payment class.
 */
class WC_Gateway_PayzenMulti extends WC_Gateway_PayzenStd
{

    public function __construct()
    {
        $this->id = 'payzenmulti';
        $this->icon = apply_filters('woocommerce_payzenmulti_icon', WC_PAYZEN_PLUGIN_URL . '/assets/images/payzenmulti.png');
        $this->has_fields = true;
        $this->method_title = 'PayZen - ' . __('Payment in several times', 'woo-payzen-payment');

        // init PayZen common vars
        $this->payzen_init();

        // load the form fields
        $this->init_form_fields();

        // load the module settings
        $this->init_settings();

        // define user set variables
        $this->title = $this->get_title();
        $this->description = $this->get_description();
        $this->testmode = ($this->get_option('ctx_mode') == 'TEST');
        $this->debug = ($this->get_option('debug') == 'yes') ? true : false;

        // reset PayZen multi payment admin form action
        add_action('woocommerce_settings_start', array($this, 'payzen_reset_admin_options'));

        // update PayZen multi payment admin form action
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // adding JS to admin form action
        add_action('admin_head-woocommerce_page_' . $this->admin_page, array($this, 'payzen_admin_head_script'));

        // generate PayZen multi payment form action
        add_action('woocommerce_receipt_' . $this->id, array($this, 'payzen_generate_form'));

        // return from payment platform action
        add_action('woocommerce_api_wc_gateway_payzen', array($this, 'payzen_notify_response'));

        // filter to allow order status override
        add_filter('woocommerce_payment_complete_order_status', array($this, 'payzen_complete_order_status'), 10, 2);

        // customize email
        add_action('woocommerce_email_after_order_table', array($this, 'payzen_add_order_email_payment_result'), 10, 3);
    }

    /**
     * Initialise Gateway Settings Form Fields
     */
    public function init_form_fields()
    {
        parent::init_form_fields();

        // by default, disable multiple payment sub-module
        $this->form_fields['enabled']['default'] = 'no';
        $this->form_fields['enabled']['description'] = __('Enables / disables multiple payment.', 'woo-payzen-payment');

        $this->form_fields['title']['default'] = __('Pay by credit card in several times', 'woo-payzen-payment');

        // if WooCommecre Multilingual is not available (or installed version not allow gateways UI translation)
        // let's suggest our translation feature
        if (! class_exists('WCML_WC_Gateways')) {
            $this->form_fields['title']['default'] = array(
                'en_US' => 'Pay by credit card in several times',
                'en_GB' => 'Pay by credit card in several times',
                'fr_FR' => 'Paiement par carte bancaire en plusieurs fois',
                'de_DE' => 'Ratenzahlung mit EC-/Kreditkarte'
            );
        }

        $this->form_fields['multi_options'] = array(
            'title' => __('MULTIPLE PAYMENT OPTIONS', 'woo-payzen-payment'),
            'type' => 'title'
        );

        // multiple payment options
        $descr = __('Click on "Add" button to configure one or more payment options. <br /><b>Label : </b>The option label to display on the frontend. <br /><b>Min amount : </b>Minimum amount to enable the payment option. <br /><b>Max amount : </b>Maximum amount to enable the payment option. <br /><b>Count : </b>Total number of payments. <br /><b>Period : </b>Delay (in days) between payments. <br /><b>1st payment : </b>Amount of first payment, in percentage of total amount. If empty, all payments will have the same amount.<br /><b>Do not forget to click on "Save" button to save your modifications.</b>', 'woo-payzen-payment');

        $cards = $this->get_supported_card_types();

        $columns = array();
        $columns['label'] = array(
            'title' => __('Label', 'woo-payzen-payment'),
            'width' => '154px'
        );

        $columns['amount_min'] = array(
            'title' => __('Min amount', 'woo-payzen-payment'),
            'width' => '92px'
        );

        $columns['amount_max'] = array(
            'title' => __('Max amount', 'woo-payzen-payment'),
            'width' => '92px'
        );

        if (isset($cards['CB'])) {
            $columns['contract'] = array(
                'title' => __('Contract', 'woo-payzen-payment'),
                'width' => '74px'
            );

            $descr = __('Click on "Add" button to configure one or more payment options. <br /><b>Label : </b>The option label to display on the frontend. <br /><b>Min amount : </b>Minimum amount to enable the payment option. <br /><b>Max amount : </b>Maximum amount to enable the payment option. <br /><b>Contract : </b>ID of the contract to use with the option (leave blank preferably). <br /><b>Count : </b>Total number of payments. <br /><b>Period : </b>Delay (in days) between payments. <br /><b>1st payment : </b>Amount of first payment, in percentage of total amount. If empty, all payments will have the same amount.<br /><b>Do not forget to click on "Save" button to save your modifications.</b>', 'woo-payzen-payment');
        }

        $columns['count'] = array(
            'title' => __('Count', 'woo-payzen-payment'),
            'width' => '74px'
        );

        $columns['period'] = array(
            'title' => __('Period', 'woo-payzen-payment'),
            'width' => '74px'
        );

        $columns['first'] = array(
            'title' => __('1st payment', 'woo-payzen-payment'),
            'width' => '84px'
        );

        $this->form_fields['payment_options'] = array(
            'title' => __('Payment options', 'woo-payzen-payment'),
            'type' => 'table',
            'columns' => $columns,
            'description' => $descr
        );
    }

    protected function get_supported_card_types()
    {
        $cards = parent::get_supported_card_types();

        $multi_cards_keys = array(
            'AMEX', 'CB', 'DINERS', 'DISCOVER', 'E-CARTEBLEUE', 'JCB', 'MASTERCARD',
            'PRV_BDP', 'PRV_BDT', 'PRV_OPT', 'PRV_SOC', 'VISA', 'VISA_ELECTRON'
        );

        foreach ($cards as $key => $value) {
            if (! in_array($key, $multi_cards_keys)) {
                unset($cards[$key]);
            }
        }

        return $cards;
    }

    public function payzen_admin_head_script()
    {
?>
        <script type="text/javascript">
        //<!--
            function payzenAddOption(fieldName, record, key) {
                if (jQuery('#' + fieldName + '_table tbody tr').length == 1) {
                    jQuery('#' + fieldName + '_btn').css('display', 'none');
                    jQuery('#' + fieldName + '_table').css('display', '');
                }

                if (! key) {
                    // new line, generate key
                    key = new Date().getTime();
                }

                var optionLine = '<tr id="' + fieldName + '_line_' + key + '">';
                jQuery.each(record, function(attr, value) {
                    var width = jQuery('#' + fieldName + '_table thead tr th.' + attr).width() - 5;
                    var inputName = fieldName + '[' + key + '][' + attr + ']';

                    optionLine += '<td style="padding: 0px;">';
                    optionLine += '<input class="input-text regular-input" style="width: ' + width + 'px;" name="' + inputName + '" id="' + inputName + '" type="text" value="' + value + '">';
                    optionLine += '</td>';
                });
                optionLine += '<td style="padding: 0px;"><input type="button" value="<?php echo __('Delete', 'woo-payzen-payment')?>" onclick="javascript: payzenDeleteOption(\'' + fieldName + '\', \'' + key + '\');"></td>';

                optionLine += '</tr>';

                jQuery(optionLine).insertBefore('#' + fieldName + '_add');
            }

            function payzenDeleteOption(fieldName, key) {
                jQuery('#' + fieldName + '_line_' + key).remove();

                if (jQuery('#' + fieldName + '_table tbody tr').length == 1) {
                    jQuery('#' + fieldName + '_btn').css('display', '');
                    jQuery('#' + fieldName + '_table').css('display', 'none');
                }
            }
        //-->
        </script>
<?php
    }

    /**
     * Generate Text Input HTML.
     *
     * @access public
     * @param mixed $key
     * @param mixed $data
     * @since 1.0.0
     * @return string
     */
    public function generate_table_html($key, $data)
    {
        global $woocommerce;

        $html = '';

        $data['title']            = isset($data['title']) ? $data['title'] : '';
        $data['disabled']        = empty($data['disabled']) ? false : true;
        $data['class']             = isset($data['class']) ? $data['class'] : '';
        $data['css']             = isset($data['css']) ? $data['css'] : '';
        $data['placeholder']     = isset($data['placeholder']) ? $data['placeholder'] : '';
        $data['type']             = isset($data['type']) ? $data['type'] : 'text';
        $data['desc_tip']        = isset($data['desc_tip']) ? $data['desc_tip'] : false;
        $data['description']    = isset($data['description']) ? $data['description'] : '';
        $data['columns']        = isset($data['columns']) ? (array) $data['columns'] : array();

        // description handling
        if ($data['desc_tip'] === true) {
            $description = '';
            $tip         = $data['description'];
        } elseif (! empty($data['desc_tip'])) {
            $description = $data['description'];
            $tip         = $data['desc_tip'];
        } elseif (! empty($data['description'])) {
            $description = $data['description'];
            $tip         = '';
        } else {
            $description = $tip = '';
        }

        $options = $this->get_option($key);

        $field_name = esc_attr($this->plugin_id . $this->id . '_' . $key);

        $html .= '<tr valign="top">' . "\n";
        $html .= '<th scope="row" class="titledesc">';
        $html .= '<label for="' . esc_attr($this->plugin_id . $this->id . '_' . $key) . '">' . wp_kses_post($data['title']) . '</label>';

        if ($tip) {
            $html .= '<img class="help_tip" data-tip="' . esc_attr($tip) . '" src="' . $woocommerce->plugin_url() . '/assets/images/help.png" height="16" width="16" />';
        }

        $html .= '</th>' . "\n";
        $html .= '<td class="forminp">' . "\n";
        $html .= '<fieldset><legend class="screen-reader-text"><span>' . wp_kses_post($data['title']) . '</span></legend>' . "\n";

        $html .= '<input id="' . $field_name . '_btn" class="' . $field_name . '_btn '. esc_attr($data['class']) . '"' . (! empty($options) ? ' style="display: none;"' : '') . ' type="button" value="' . __('Add', 'woo-payzen-payment') . '">';
         $html .= '<table id="' . $field_name . '_table" class="'. esc_attr($data['class']) . '"' . (empty($options) ? ' style="display: none;"' : '') . ' cellpadding="10" cellspacing="0" >';

        $html .= '<thead><tr>';
        $record = array();
        foreach ($data['columns'] as $code => $column) {
            $record[$code] = '';
            $html .= '<th class="' . $code . '" style="width: ' . $column['width'] . '; padding: 0px;">' . $column['title'] . '</th>';
        }

        $html .= '<th style="width: auto; padding: 0px;"></th>';
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        $html .= '<tr id="' . $field_name . '_add">
                    <td colspan="' . count($data['columns']) . '"></td>
                    <td style="padding: 0px;"><input class="' . $field_name . '_btn" type="button" value="' . __('Add') . '"></td>
                  </tr>';
        $html .= '</tbody></table>';

        $html .= "\n" . '<script type="text/javascript">';
        $html .= "\n" . 'jQuery(".' . $field_name . '_btn").click(function() {
                            payzenAddOption("' . $field_name . '", ' . json_encode($record) . ');
                         })';

        if (! empty($options)) {
            // add already inserted lines
            foreach ($options as $code => $option) {
                $html .= "\n" . 'payzenAddOption("' . $field_name . '", ' . json_encode($option) . ', "' . $code . '");';
            }
        }
        $html .= "\n" . '</script>';

        if ($description) {
            $html .= ' <p class="description">' . wp_kses_post($description) . '</p>' . "\n";
        }

        $html .= '</fieldset>';
        $html .= '</td>' . "\n";
        $html .= '</tr>' . "\n";

        return $html;
    }

    public function validate_payment_options_field($key, $value = null)
    {
        $name = $this->plugin_id . $this->id . '_' . $key;
        $value = $value ? $value : (key_exists($name, $_POST) ? $_POST[$name] : array());

        foreach ($value as $code => $option) {
            if (! $option['label']
                    || ($option['amount_min'] && (! is_numeric($option['amount_min']) || $option['amount_min'] < 0))
                    || ($option['amount_max'] && (! is_numeric($option['amount_max']) || $option['amount_max'] < 0))
                    || ! is_numeric($option['count']) || $option['count'] < 1
                    || ! is_numeric($option['period']) || $option['period'] <= 0
                    || ($option['first'] && (! is_numeric($option['first']) || $option['first'] < 0 || $option['first'] > 100))) {

                unset($value[$code]); // not save this option
            } else {
                // clean string
                $fnc = function_exists('wc_clean') ? 'wc_clean' : 'woocommerce_clean';
                $value[$code] = array_map('esc_attr', array_map($fnc, (array) $option));
            }
        }

        return $value;
    }

    /**
     * Check if this gateway is enabled and available for the current cart.
     */
    public function is_available()
    {
        global $woocommerce;

        // check if any multi payment option is available
        $available_options = $this->get_available_options();
        if ($woocommerce->cart && empty($available_options)) {
            return false;
        }

        return parent::is_available();
    }

    private function get_available_options()
    {
        global $woocommerce;

        $amount = $woocommerce->cart->total;

        $options = $this->get_option('payment_options');
        $enabled_options = array();

        if (isset($options) && is_array($options) && ! empty($options)) {
            foreach ($options as $code => $option) {
                if ((! $option['amount_min'] || $amount >= $option['amount_min']) && (! $option['amount_max'] || $amount <= $option['amount_max'])) {
                    $enabled_options[$code] = $option;
                }
            }
        }

        return $enabled_options;
    }

    /**
     * Display payment fields and show method description if set.
     *
     * @access public
     * @return void
     */
    public function payment_fields()
    {
        parent::payment_fields();

        $options = $this->get_available_options();

        if (empty($options)) {
            // should not happens for multi payment
            return;
        }

        echo '<ul>';

        if (count($options) == 1) {
            $option = reset($options); // the option itself
            $key = key($options); // the option key in options array
            echo '<span style="font-weight: bold;">' . __('Your payment option', 'woo-payzen-payment') . '</span>';
            echo '<li style="list-style-type: none;">
                    <input type="hidden" id="payzenmulti_option_' . $key . '" value="' . $key . '" name="payzenmulti_option">
                    <label style="display: inline;">' . $option['label'] . '</label>
                  </li>';

        } else {
            $first = true;
            echo '<span style="font-weight: bold;">' . __('Choose your payment option', 'woo-payzen-payment') . '</span>';
            foreach ($options as $key => $option) {
                echo '<li style="list-style-type: none;">
                        <input class="radio" type="radio"'. ($first == true ? ' checked="checked"' : '') . ' id="payzenmulti_option_' . $key . '" value="' . $key . '" name="payzenmulti_option">
                        <label for="payzenmulti_option_' . $key . '" style="display: inline;">' . $option['label'] . '</label>
                      </li>';
                $first = false;
            }
        }

        echo '</ul>';
    }

    /**
     * Process the payment and return the result
     **/
    public function process_payment($order_id)
    {
        global $woocommerce;

        if ($this->get_option('card_data_mode') == 'MERCHANT') {
            $this->save_selected_card($order_id);
        }

        $options = $this->get_available_options();
        $option = $options[$_POST['payzenmulti_option']];

        // save selected payment option into session
        set_transient('payzenmulti_option_' . $order_id, $option);

        // ... and into DB
        $order = new WC_Order($order_id);
        update_post_meta($this->get_order_property($order, 'id'), '_payment_method_title', $this->get_order_property($order, 'payment_method_title') . " ({$option['count']} x)");

        if (version_compare($woocommerce->version, '2.1.0', '<')) {
            $pay_url = add_query_arg('order', $this->get_order_property($order, 'id'), add_query_arg('key', $this->get_order_property($order, 'order_key'), get_permalink(woocommerce_get_page_id('pay'))));
        } else {
            $pay_url = $order->get_checkout_payment_url(true);
        }

        return array(
            'result' => 'success',
            'redirect' => $pay_url
        );
    }

    /**
     * Prepare PayZen form params to send to payment platform.
     **/
    protected function payzen_fill_request($order)
    {
        parent::payzen_fill_request($order);

        $option = get_transient('payzenmulti_option_' . $this->get_order_property($order, 'id'));

        // multiple payment options
        $amount = $this->payzen_request->get('amount');
        $first = $option['first'] ? round(($option['first'] / 100) * $amount) : null;
        $this->payzen_request->setMultiPayment($amount, $first, $option['count'], $option['period']);
        $this->payzen_request->set('contracts', (isset($option['contract']) && $option['contract']) ? 'CB='.$option['contract'] : null);

        delete_transient('payzenmulti_option_' . $this->get_order_property($order, 'id'));
    }
}
