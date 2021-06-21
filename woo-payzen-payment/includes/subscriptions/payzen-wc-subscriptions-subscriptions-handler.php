<?php
/**
 * Copyright © Lyra Network and contributors.
 * This file is part of PayZen plugin for WooCommerce. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @author    Geoffrey Crofte, Alsacréations (https://www.alsacreations.fr/)
 * @copyright Lyra Network and contributors
 * @license   http://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU General Public License (GPL v2)
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Payzen_WC_Subscriptions_Subscriptions_Handler implements Payzen_Subscriptions_Handler_Interface
{
    /**
     * {@inheritDoc}
     * @see Payzen_Subscriptions_Handler_Interface::init_hooks()
     */
    public function init_hooks()
    {
        add_action('woocommerce_subscription_cancelled_payzensubscription', array($this, 'cancel_subscription'));
        add_action('wcs_subscription_schedule_after_billing_schedule', array($this, 'update_subscription'));
    }

    /**
     * {@inheritDoc}
     * @see Payzen_Subscriptions_Handler_Interface::cart_contains_subscription()
     */
    public function cart_contains_subscription($cart)
    {
        if (class_exists('WC_Subscriptions_Cart')) {
            return WC_Subscriptions_Cart::cart_contains_subscription();
        } else {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     * @see Payzen_Subscriptions_Handler_Interface::cart_contains_multiple_subscriptions()
     */
    public function cart_contains_multiple_subscriptions($cart)
    {
        $count = 0;

        if (! empty($cart->cart_contents ) && ! wcs_cart_contains_renewal()) {
            foreach ($cart->cart_contents as $cart_item) {
                if (WC_Subscriptions_Product::is_subscription($cart_item['data'])) {
                    $count ++;
                }
            }
        }

        return $count > 1;
    }

    /**
     * {@inheritDoc}
     * @see Payzen_Subscriptions_Handler_Interface::is_subscription_update()
     */
    public function is_subscription_update() {
        return WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
    }

    /**
     * {@inheritDoc}
     * @see Payzen_Subscriptions_Handler_Interface::get_parent_order()
     */
    public function get_parent_order($id) {
        $subscription = wcs_get_subscription($id);

        if ($subscription) {
            return $subscription->get_parent();
        }

        return false;
    }

    /**
     * {@inheritDoc}
     * @see Payzen_Subscriptions_Handler_Interface::subscription_info()
     */
    public function subscription_info($order)
    {
        $order_id = wcs_get_objects_property($order, 'id');

        $old_payment_method = get_transient('payzensubscription_change_payment_' . $order_id);
        $is_payment_change = $old_payment_method ? true : false;
        delete_transient('payzensubscription_change_payment_' . $order_id);

        // Payment method changes act on the subscription not the original order.
        if ($is_payment_change) {
            if ($old_payment_method === 'payzensubscription') {
                return false;
            }

            $subscription = wcs_get_subscription($order_id);

            // We need the subscription's total.
            if (WC_Subscriptions::is_woocommerce_pre('3.0')) {
                remove_filter('woocommerce_order_amount_total', 'WC_Subscriptions_Change_Payment_Gateway::maybe_zero_total', 11);
            } else {
                remove_filter('woocommerce_subscription_get_total', 'WC_Subscriptions_Change_Payment_Gateway::maybe_zero_total', 11);
            }
        } else {
            // Otherwise the order is $order.
            if (wcs_cart_contains_failed_renewal_order_payment() ||
                false !== WC_Subscriptions_Renewal_Order::get_failed_order_replaced_by(wcs_get_objects_property($order, 'id'))) {
                $subscriptions = wcs_get_subscriptions_for_renewal_order($order);
            } else {
                $subscriptions = wcs_get_subscriptions_for_order($order);
            }

            $subscription = reset($subscriptions); // Get first subscription
        }

        $info = $subscription ? array(
            'effect_date' => self::get_effect_date($subscription, $is_payment_change),
            'init_amount' => null,
            'init_number' => null,
            'amount' => $subscription->get_total(),
            'frequency' => self::get_frequency($subscription),
            'interval' => (int) $subscription->get_billing_interval(),
            'end_date' => self::get_end_date($subscription)
        ) : false;

        // Reattach the filter we removed earlier.
        if ($is_payment_change) {
            if (WC_Subscriptions::is_woocommerce_pre('3.0')) {
                add_filter('woocommerce_order_amount_total', 'WC_Subscriptions_Change_Payment_Gateway::maybe_zero_total', 11, 2);
            } else {
                add_filter('woocommerce_subscription_get_total', 'WC_Subscriptions_Change_Payment_Gateway::maybe_zero_total', 11, 2);
            }
        }

        return $info;
    }

    private static function get_effect_date($subscription, $is_payment_change = false)
    {
        // If it's a payment change, set effect date to next payment date.
        if ($is_payment_change) {
            $start_time = $subscription->get_time('next_payment');
        } else { // New subscription.
            $start_time = $subscription->get_time('start');
        }

        // Get free trial end date.
        $trial_end = $subscription->get_time('trial_end');

        if ($trial_end <= date('Ymd', time())) {
            // No trial period, 1st recurrence is paid in order amount.
            $start_time = $subscription->get_time('next_payment');
        } elseif ($trial_end > $start_time) {
            // Subscription starts after a trial period.
            $start_time = $trial_end;
        }

        return date('Ymd', $start_time);
    }

    private static function get_end_date($subscription)
    {
        return $subscription->get_time('end') ? date('Ymd', strtotime('-1 day', $subscription->get_time('end'))) : null;
    }

    private static function get_frequency($subscription)
    {
        $subscription_period = $subscription->get_billing_period();

        $mapping = array(
            'year' => 'YEARLY',
            'month' => 'MONTHLY',
            'week' => 'WEEKLY',
            'day' => 'DAILY'
        );

        return $mapping[$subscription_period];
    }

    /**
     * {@inheritDoc}
     * @see Payzen_Subscriptions_Handler_Interface::process_subscription()
     */
    public function process_subscription($order, $response)
    {
        $subscriptions = wcs_get_subscriptions_for_order($order);
        $subscription = reset($subscriptions); // Get first subscription.

        delete_post_meta($subscription->get_id(), 'Subscription ID');
        delete_post_meta($subscription->get_id(), 'Subscription amount');
        delete_post_meta($subscription->get_id(), 'Effect date');

        // Store subscription details.
        update_post_meta($subscription->get_id(), 'Subscription ID', $response->get('subscription'));
        update_post_meta($subscription->get_id(), 'Subscription amount', WC_Gateway_Payzen::display_amount($response->get('sub_amount'), $response->get('sub_currency')));
        update_post_meta($subscription->get_id(), 'Effect date', preg_replace('#^(\d{4})(\d{2})(\d{2})$#', '\1-\2-\3', $response->get('sub_effect_date')));

        if (WC_Gateway_Payzen::is_successful_action($response)) {
            $subscription->payment_complete();
        } else {
            $subscription->payment_failed();
        }
    }

    /**
     * {@inheritDoc}
     * @see Payzen_Subscriptions_Handler_Interface::update_subscription()
     */
    public function process_subscription_renewal($order, $response)
    {
        $subscriptions = wcs_get_subscriptions_for_order($order);
        $subscription = reset($subscriptions); // Get first subscription.
        if (wcs_get_objects_property($subscription, 'payment_method') !== 'payzensubscription') {
            // Update payment method in order.
            $payzen_subscription = new WC_Gateway_PayzenSubscription();
            if (method_exists($subscription, 'set_payment_method')) {
                $subscription->set_payment_method($payzen_subscription);
            } else {
                $subscription->payment_method = $payzen_subscription->id;
                $subscription->payment_method_title = $payzen_subscription->get_title();
            }

            $subscription->save();
        }

        if ($renewal_order_id = (int) $subscription->get_last_order('ids', 'renewal')) { // Get last generated order.
            $renewal_order = new WC_Order($renewal_order_id);

            WC_Gateway_Payzen::payzen_add_order_note($response, $renewal_order);

            $currency_code = $response->get('currency');

            delete_post_meta($renewal_order_id, 'Subscription ID');
            delete_post_meta($renewal_order_id, 'Subscription amount');
            delete_post_meta($renewal_order_id, 'Recurrence number');

            update_post_meta($renewal_order_id, 'Subscription ID', $response->get('subscription'));
            update_post_meta($renewal_order_id, 'Subscription amount', WC_Gateway_Payzen::display_amount($response->get('sub_amount'), $currency_code));
            update_post_meta($renewal_order_id, 'Recurrence number', $response->get('recurrence_number'));

            if (WC_Gateway_Payzen::is_successful_action($response)) {
                // Payment completed.
                $renewal_order->payment_complete();
            } elseif ($response->isPendingPayment()) {
                // Payment is pending.
                $renewal_order->update_status('on-hold');
            } else {
                // Payment failed.
                $renewal_order->update_status('failed');
            }
        }
    }

    /**
     * {@inheritDoc}
     * @see Payzen_Subscriptions_Handler_Interface::cancel_subscription()
     */
    public function cancel_subscription()
    {
        list($subscription) = func_get_args();

        $subscription_id = $subscription->get_id();
        $order_id = $subscription->get_parent_id() ? $subscription->get_parent_id() : $subscription_id;

        $payzen_subscription = new WC_Gateway_PayzenSubscription();
        $payzen_subscription->cancel_online_subscription($subscription_id, $order_id);
    }

    /**
     * {@inheritDoc}
     * @see Payzen_Subscriptions_Handler_Interface::update_subscription()
     */
    public function update_subscription()
    {
        if (! isset($_GET['message']) || $_GET['message'] != 1 ) {
            return;
        }

        list($subscription) = func_get_args();

        $subscription_id = $subscription->get_id();
        $order_id = $subscription->get_parent_id() ? $subscription->get_parent_id() : $subscription_id;

        $payzen_subscription = new WC_Gateway_PayzenSubscription();
        $payzen_subscription->update_online_subscription($subscription_id, $order_id);
    }

    /**
     * {@inheritDoc}
     * @see Payzen_Subscriptions_Handler_Interface::get_view_order_url()
     */
    public function get_view_order_url($subsc_id)
    {
        $subscription = wcs_get_subscription($subsc_id);
        return $subscription->get_view_order_url();
    }

    /**
     * {@inheritDoc}
     * @see Payzen_Subscriptions_Handler_Interface::get_subscription_statuses()
     */
    public function get_subscription_statuses()
    {
        if (function_exists('wcs_get_subscription_statuses')) {
            return wcs_get_subscription_statuses();
        }

        return array();
    }
}