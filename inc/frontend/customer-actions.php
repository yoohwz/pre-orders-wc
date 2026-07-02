<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class YoOhw_Pre_Orders_WC_Customer_Actions {

    public function __construct() {
        if (get_option('yoess_pre_order_customer_actions') === 'yes') {
            add_action( 'woocommerce_before_thankyou', [ $this, 'display_customer_action_buttons' ] );
            add_action( 'woocommerce_before_checkout_form', [ $this, 'display_customer_action_buttons_on_checkout' ] );
            add_action( 'woocommerce_checkout_after_customer_details', [ $this, 'output_stock_status_hidden_field' ] );

            add_action( 'woocommerce_checkout_order_created', [ $this, 'maybe_save_stock_status_action_on_checkout' ], 20, 1 );
            add_action( 'wp_ajax_handle_stock_status_action', [ $this, 'handle_stock_status_action' ] );
            add_action( 'wp_ajax_nopriv_handle_stock_status_action', [ $this, 'handle_stock_status_action' ] );

            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        }
    }

    /**
     * Display buttons on the order details page
     */
    public function display_customer_action_buttons( $order_id ) {
        // Get the order object from the ID:
        $order = wc_get_order( $order_id );
        if ( ! $order || ! ( $order instanceof WC_Order ) ) {
            return;
        }

        // Check if we’ve already recorded a customer action for this order:
        $customer_action_meta = $order->get_meta( '_stock_status_customer_action', true );
        if ( $customer_action_meta ) {
            // Instead of returning silently, show a notice:
            if ( 'all_at_once' === $customer_action_meta ) {
                ?>
                <div class="stock-status-customer-notice">
                    <p>
                        <?php esc_html_e( 'You have previously requested to receive all items at once. If you change your mind, please contact support for assistance.', 'pre-orders-wc' ); ?>
                    </p>
                </div>
                <?php
            } elseif ( 'separately' === $customer_action_meta ) {
                ?>
                <div class="stock-status-customer-notice">
                    <p>
                        <?php esc_html_e( 'You have previously requested to receive items separately. If you change your mind, please contact support for assistance.', 'pre-orders-wc' ); ?>
                    </p>
                </div>
                <?php
            }
            return; // Stop here, because we don’t need to show buttons again
        }

        // See if the order contains both an 'onpreorder' product and at least one other-status product
        $has_onpreorder   = false;
        $has_other_status = false;

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            $stock_status = $product->get_stock_status();

            if ( 'onpreorder' === $stock_status ) {
                $has_onpreorder = true;
            } else {
                $has_other_status = true;
            }
        }

        if ( $has_onpreorder && $has_other_status ) {
            ?>
            <div class="stock-status-customer-actions">
                <p>
                    <?php esc_html_e( 'Your order contains the pre-order item(s). Please let us know what type of receiving you like:', 'pre-orders-wc' ); ?>
                </p>
	                <button
	                    id="receive-all-products"
	                    class="button button-customer-actions"
	                    data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
	                    data-order-key="<?php echo esc_attr( $order->get_order_key() ); ?>">
	                    <?php esc_html_e( 'I want to receive all items at once', 'pre-orders-wc' ); ?>
	                </button>
	                <button
	                    id="receive-separately"
	                    class="button button-customer-actions"
	                    data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
	                    data-order-key="<?php echo esc_attr( $order->get_order_key() ); ?>">
	                    <?php esc_html_e( 'I want to receive it separately', 'pre-orders-wc' ); ?>
	                </button>
            </div>
            <?php
        }
    }

    public function display_customer_action_buttons_on_checkout( $checkout ) {
        // Make sure the cart exists
        if ( ! WC()->cart ) {
            return;
        }

        $has_onpreorder   = false;
        $has_other_status = false;

        // Loop through each cart item, check stock status
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product_id = $cart_item['product_id'];
            $product    = wc_get_product( $product_id );

            if ( ! $product ) {
                continue;
            }

            $stock_status = $product->get_stock_status();

            if ( $stock_status === 'onpreorder' ) {
                $has_onpreorder = true;
            } else {
                $has_other_status = true;
            }
        }

        // Only show buttons if there’s at least one preorder item AND at least one non-preorder item
        if ( $has_onpreorder && $has_other_status ) {
            ?>
            <div class="stock-status-customer-actions">
                <p>
                    <?php esc_html_e( 'Your cart contains pre-order item(s). Please let us know how you’d like to receive everything:', 'pre-orders-wc' ); ?>
                </p>
                <button
                    id="receive-all-products-checkout"
                    class="button button-customer-actions"
                    type="button"
                >
                    <?php esc_html_e( 'I want to receive all items at once', 'pre-orders-wc' ); ?>
                </button>
                <button
                    id="receive-separately-checkout"
                    class="button button-customer-actions"
                    type="button"
                >
                    <?php esc_html_e( 'I want to receive it separately', 'pre-orders-wc' ); ?>
                </button>
            </div>
            <?php
        }
    }    

    public function output_stock_status_hidden_field() {
        ?>
        <input
            type="hidden"
            name="stock_status_customer_action"
            id="stock_status_customer_action"
            value=""
        />
        <?php
    }

    /**
     * When the customer checks out, save their “stock_status_customer_action” value (if set)
     * as order meta, send admin notification, and add an order note.
     */
    public function maybe_save_stock_status_action_on_checkout( $order ) {
        if ( isset( $_POST['stock_status_customer_action'] ) && ! empty( $_POST['stock_status_customer_action'] ) ) {
            $checkout_nonce = isset( $_POST['woocommerce-process-checkout-nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) ) : '';
            if ( ! $checkout_nonce || ! wp_verify_nonce( $checkout_nonce, 'woocommerce-process_checkout' ) ) {
                return;
            }

            $action_type = sanitize_text_field( wp_unslash( $_POST['stock_status_customer_action'] ) );
            if ( ! $this->is_allowed_action_type( $action_type ) ) {
                return;
            }

            $order_id = $order->get_id();

            $this->record_customer_action( $order, $action_type );

            $customer_first_name = $order->get_billing_first_name();
            $order_url           = admin_url( 'post.php?post=' . $order_id . '&action=edit' );

            $this->notify_admin_customer_request( $customer_first_name, $order_id, $order_url, $action_type );

            if ( 'all_at_once' === $action_type ) {
                $order->add_order_note( __( 'Customer requested to receive all products at once.', 'pre-orders-wc' ) );
            } else {
                $order->add_order_note( __( 'Customer requested to receive products separately.', 'pre-orders-wc' ) );
            }
        }
    }

    /**
     * Handle AJAX action for stock status
     */
    public function handle_stock_status_action() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'stock_status_action_nonce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Nonce verification failed.', 'pre-orders-wc' ) ] );
        }

        if ( ! isset( $_POST['order_id'] ) || ! isset( $_POST['action_type'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid request.', 'pre-orders-wc' ) ] );
        }

        $order_id    = absint( wp_unslash( $_POST['order_id'] ) );
        $action_type = sanitize_key( wp_unslash( $_POST['action_type'] ) );
        $order_key   = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';

        if ( ! $this->is_allowed_action_type( $action_type ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid action type.', 'pre-orders-wc' ) ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'pre-orders-wc' ) ] );
        }

        if ( ! $this->current_request_can_update_order( $order, $order_key ) ) {
            wp_send_json_error( [ 'message' => __( 'You are not allowed to update this order.', 'pre-orders-wc' ) ] );
        }

        if ( $order->get_meta( '_stock_status_customer_action', true ) ) {
            wp_send_json_error( [ 'message' => __( 'A delivery preference has already been recorded for this order.', 'pre-orders-wc' ) ] );
        }

        $customer_first_name = $order->get_billing_first_name();
        $order_url           = admin_url( 'post.php?post=' . $order_id . '&action=edit' );

        if ( 'all_at_once' === $action_type ) {
            $this->notify_admin_customer_request( $customer_first_name, $order_id, $order_url, 'all_at_once' );
            $this->record_customer_action( $order, $action_type );
            $order->add_order_note( __( 'Customer requested to receive all products at once.', 'pre-orders-wc' ) );
            wp_send_json_success( [ 'message' => __( 'Thank you for letting us know. You will receive all items at once.', 'pre-orders-wc' ) ] );
        } elseif ( 'separately' === $action_type ) {
            $this->notify_admin_customer_request( $customer_first_name, $order_id, $order_url, 'separately' );
            $this->record_customer_action( $order, $action_type );
            $order->add_order_note( __( 'Customer requested to receive products separately.', 'pre-orders-wc' ) );
            wp_send_json_success( [ 'message' => __( 'Thank you for letting us know. You will receive items separately.', 'pre-orders-wc' ) ] );
        }

        wp_send_json_error( [ 'message' => __( 'Invalid action type.', 'pre-orders-wc' ) ] );
    }

    private function is_allowed_action_type( $action_type ) {
        return in_array( $action_type, [ 'all_at_once', 'separately' ], true );
    }

    private function current_request_can_update_order( $order, $order_key ) {
        if ( is_user_logged_in() ) {
            $customer_id = (int) $order->get_customer_id();
            if ( $customer_id && get_current_user_id() === $customer_id ) {
                return true;
            }
        }

        return $order_key && hash_equals( (string) $order->get_order_key(), (string) $order_key );
    }

    private function record_customer_action( $order, $action_type ) {
        $order->update_meta_data( '_stock_status_customer_action', $action_type );
        $order->save();
    }

    /**
     * Notify admin about the customer's choice
     */
    private function notify_admin_customer_request( $customer_name, $order_id, $order_url, $action_type ) {
        $admin_email = get_option( 'admin_email' );
        $mailer = WC()->mailer();
        $email = new WC_Email();

        $heading = __( 'Customer delivery request', 'pre-orders-wc' );
	        $action_message = ( 'all_at_once' === $action_type )
	            ? __( 'requested to receive all items at once.', 'pre-orders-wc' )
	            : __( 'requested to receive items separately.', 'pre-orders-wc' );

	        $message = sprintf(
	            /* translators: 1: customer name, 2: order ID, 3: customer action text, 4: order edit URL. */
	            __( 'Hi there,<br><br>Customer <strong>%1$s</strong> (Order ID: #%2$d) %3$s<br><br>You can view the order details here:<br><br><a href="%4$s">Go to Edit Order page</a><br><br>Thank you.', 'pre-orders-wc' ),
	            esc_html( $customer_name ),
	            $order_id,
	            $action_message,
            esc_url( $order_url )
        );

        // Wrap the message in WooCommerce's email template
        $wrapped_message = $mailer->wrap_message( $heading, $message );

        // Inline the styles
        $styled_message = $email->style_inline( $wrapped_message );

        // Email headers
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        // Send the email
	        wp_mail(
	            $admin_email,
	            /* translators: %d: order ID. */
	            sprintf( __( 'Customer delivery request on order #%d', 'pre-orders-wc' ), $order_id ),
	            $styled_message,
	            $headers
        );
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        if ( is_account_page() || is_checkout() || is_order_received_page() ) {
            wp_enqueue_script( 'pre-orders-wc-customer-actions', plugin_dir_url( __FILE__ ) . '../../js/customer-actions.js', [ 'jquery' ], YOOHW_PRE_ORDERS_WC_VERSION, true );
            wp_localize_script( 'pre-orders-wc-customer-actions', 'stockStatusActions', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'stock_status_action_nonce' ),

                'i18n'     => [
                    'invalid_action'    => __( 'Invalid action. Please try again.', 'pre-orders-wc' ),
                    'confirm_all'       => __( 'Got it! We’ll send all items at once when your order is placed.', 'pre-orders-wc' ),
                    'confirm_separately'=> __( 'Got it! We’ll ship items separately when your order is placed.', 'pre-orders-wc' ),
                    'unexpected_error'  => __( 'An unexpected error occurred.', 'pre-orders-wc' ),
                    'ajax_error'        => __( 'Something went wrong. Please try again.', 'pre-orders-wc' ),
                    'unable_record'     => __( 'Unable to record your choice. Please refresh and try again.', 'pre-orders-wc' ),
                ],
            ] );
        }
    }
}

new YoOhw_Pre_Orders_WC_Customer_Actions();
