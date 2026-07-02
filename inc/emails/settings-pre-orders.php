<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Pre_Order_Email extends WC_Email {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id             = 'pre_order_schedule';
        $this->title          = __( 'Pre-order arrived', 'pre-orders-wc' );
        $this->description    = __( 'This is a pre-order email notification sent to customers when their pre-order becomes available.', 'pre-orders-wc' );

        $this->heading        = __( 'Your pre-order is ready', 'pre-orders-wc' );

        // translators: %s is the site title
        $this->subject        = __( 'Your {site_title} pre-order has arrived!', 'pre-orders-wc' );

        $this->template_base  = plugin_dir_path( __FILE__ ) . '/';
        $this->template_html  = 'customer-pre-order.php';
        $this->template_plain = 'plain/customer-pre-order.php';

        // Initialize recipient as the customer email
        $this->recipient = '';

        // Call parent constructor to load defaults
        parent::__construct();

        // Initialize form fields for admin configuration
        $this->init_form_fields();
    }

    /**
     * Initialize settings form fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'pre-orders-wc' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this email notification', 'pre-orders-wc' ),
                'default' => 'yes',
            ),
            'subject' => array(
                'title'       => __( 'Subject', 'pre-orders-wc' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => __( 'Available placeholders: {site_title}, {site_address}, {site_url}, {order_date}, {order_number}', 'pre-orders-wc' ),
                'placeholder' => $this->get_default_subject(),
                'default'     => '',
            ),
            'heading' => array(
                'title'       => __( 'Email heading', 'pre-orders-wc' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => __( 'Available placeholders: {site_title}, {site_address}, {site_url}, {order_date}, {order_number}', 'pre-orders-wc' ),
                'placeholder' => $this->get_default_heading(),
                'default'     => '',
            ),
            'additional_content' => array(
                'title'       => __( 'Additional content', 'pre-orders-wc' ),
                'type'        => 'textarea',
                'description' => __( 'Text to appear below the main email content. Available placeholders: {site_title}, {site_address}, {site_url}, {order_date}, {order_number}', 'pre-orders-wc' ),
                'desc_tip'    => true,
                'default'     => '',
                'css'         => 'width: 400px; hieght: 75px',
            ),
            'email_type' => array(
                'title'       => __( 'Email type', 'pre-orders-wc' ),
                'type'        => 'select',
                'description' => __( 'Choose which format of email to send.', 'pre-orders-wc' ),
                'default'     => 'html',
                'class'       => 'email_type wc-enhanced-select',
                'options'     => $this->get_email_type_options(),
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Trigger the email.
     */
    public function trigger( $order_id ) {
      
        $this->setup_locale();
    
        $this->object = wc_get_order( $order_id );
    
        if ( $this->object ) {
            $this->recipient = $this->object->get_billing_email();
    
            // Retrieve store address
            $store_address = WC()->countries->get_base_address();
            $store_address_2 = WC()->countries->get_base_address_2();
            $store_city = WC()->countries->get_base_city();
            $store_postcode = WC()->countries->get_base_postcode();
            $store_country = WC()->countries->get_base_country();
            $store_state = WC()->countries->get_base_state();
            $store_address_full = trim( 
                sprintf(
                    '%s %s, %s, %s, %s, %s',
                    $store_address,
                    $store_address_2,
                    $store_city,
                    $store_state,
                    $store_country,
                    $store_postcode
                )
            );
    
            // Replace placeholders
            $placeholders = array(
                '{site_name}'    => get_bloginfo( 'name' ),
                '{site_url}'     => home_url(),
                '{order_number}' => $this->object->get_order_number(),
                '{order_date}'   => wc_format_datetime( $this->object->get_date_created() ),
                '{site_address}' => $store_address_full,
            );
    
            $this->subject = str_replace( array_keys( $placeholders ), $placeholders, $this->get_subject() );
            $this->heading = str_replace( array_keys( $placeholders ), $placeholders, $this->get_heading() );

        } else {
            $this->restore_locale();
            return;
        }
    
        if ( $this->is_enabled() && $this->recipient ) {
            $result = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
    
        }
    
        $this->restore_locale();
    }
    
    /**
     * Get content HTML.
     */
    public function get_content_html() {
        ob_start();
        wc_get_template( $this->template_html, array(
            'order'              => $this->object,
            'email_heading'      => $this->get_heading(),
            'additional_content' => $this->get_option( 'additional_content', '' ),
            'sent_to_admin'      => false,
            'plain_text'         => false,
            'email'              => $this,
        ), '', $this->template_base );
        return ob_get_clean();
    }

    /**
     * Get content plain text.
     */
    public function get_content_plain() {
        ob_start();
        wc_get_template( $this->template_plain, array(
            'order'              => $this->object,
            'email_heading'      => $this->get_heading(),
            'additional_content' => $this->get_option( 'additional_content', '' ),
            'sent_to_admin'      => false,
            'plain_text'         => true,
            'email'              => $this,
        ), '', $this->template_base );
        return ob_get_clean();
    }

    /**
     * Get default subject.
     */
    public function get_default_subject() {
        return __( 'Your {site_title} pre-order has arrived!', 'pre-orders-wc' );
    }

    /**
     * Get default heading.
     */
    public function get_default_heading() {
        return __( 'Your pre-order is ready!', 'pre-orders-wc' );
    }

    /**
     * Get recipient for display in email settings or email sending.
     *
     * @return string
     */
	public function get_recipient() {
        // If we're in the admin settings, return "Customer" for display purposes
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if (is_admin() && $screen && 'woocommerce_page_wc-settings' === $screen->id) {
            return __( 'Customer', 'pre-orders-wc' );
        }

        // Otherwise, return the actual recipient email
        return $this->recipient;
    }
}
