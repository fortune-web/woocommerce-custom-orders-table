<?php
/**
 * Tests for the WC_Order_Data_Store_Custom_Table class.
 *
 * @package Woocommerce_Order_Tables
 * @author  Liquid Web
 */

class DataStoreTest extends TestCase {

	/**
	 * Fire the necessary actions to bootstrap WordPress.
	 *
	 * @before
	 */
	public function init() {
		do_action( 'init' );
	}

	/**
	 * Remove any closures that have been assigned to filters.
	 *
	 * @after
	 */
	public function remove_filter_callbacks() {
		remove_all_filters( 'woocommerce_shop_order_search_fields' );
	}

	public function test_create() {
		$instance  = new WC_Order_Data_Store_Custom_Table();
		$order     = $this->factory()->order->create_and_get();
		$order_key = 'my_custom_order_key';

		add_filter( 'woocommerce_generate_order_key', function () use ( $order_key ) {
			return $order_key;
		} );

		$instance->create( $order );

		$this->assertEquals( 'wc_' . $order_key, $order->get_order_key() );
	}

	public function test_update_post_meta_for_new_order() {
		$order = new WC_Order( wp_insert_post( array(
			'post_type' => 'product',
		) ) );
		$order->set_currency( 'USD' );
		$order->set_prices_include_tax( false );
		$order->set_customer_ip_address( '127.0.0.1' );
		$order->set_customer_user_agent( 'PHPUnit' );

		$this->invoke_update_post_meta( $order, true );

		$row = $this->get_order_row( $order->get_id() );

		$this->assertEquals( 'USD', $row['currency'] );
		$this->assertEquals( '127.0.0.1', $row['customer_ip_address'] );
		$this->assertEquals( 'PHPUnit', $row['customer_user_agent'] );
	}

	/**
	 * When inserting rows into the database, $wpdb->prepare() can't accept WC_DateTime objects.
	 */
	public function test_update_post_meta_casts_dates_as_strings() {
		$order = new WC_Order( wp_insert_post( array(
			'post_type' => 'product',
		) ) );
		$time  = time();
		$order->set_date_paid( $time );
		$order->set_date_completed( $time );

		$this->invoke_update_post_meta( $order, true );

		$row = $this->get_order_row( $order->get_id() );

		$this->assertEquals( $time, strtotime( $row['date_paid'] ) );
		$this->assertEquals( $time, strtotime( $row['date_completed'] ) );
	}

	public function test_search_orders_can_search_by_order_id() {
		$instance = new WC_Order_Data_Store_Custom_Table();

		$this->assertEquals(
			array( 123 ),
			$instance->search_orders( 123 ),
			'When given a numeric value, search_orders() should include that order ID.'
		);
	}

	public function test_search_orders_can_check_post_meta() {
		$instance = new WC_Order_Data_Store_Custom_Table();
		$order    = $this->factory()->order->create();
		$term     = uniqid( 'search term ' );

		add_post_meta( $order, 'some_custom_meta_key', $term );

		add_filter( 'woocommerce_shop_order_search_fields', function () {
			return array( 'some_custom_meta_key' );
		} );

		$this->assertEquals(
			array( $order ),
			$instance->search_orders( $term ),
			'If post meta keys are specified, they should also be searched.'
		);
	}

	/**
	 * Same as test_search_orders_can_check_post_meta(), but the filter is never added.
	 */
	public function test_search_orders_only_checks_post_meta_if_specified() {
		$instance = new WC_Order_Data_Store_Custom_Table();
		$order    = $this->factory()->order->create();
		$term     = uniqid( 'search term ' );

		add_post_meta( $order, 'some_custom_meta_key', $term );

		$this->assertEmpty(
			$instance->search_orders( $term ),
			'Only search post meta if keys are provided.'
		);
	}

	public function test_search_orders_checks_table_for_product_item_matches() {
		$instance = new WC_Order_Data_Store_Custom_Table();
		$product  = $this->factory()->product->create_and_get();
		$order    = $this->factory()->order->create_and_get();
		$order->add_product( $product );
		$order->save();

		$this->assertEquals(
			array( $order->get_id() ),
			$instance->search_orders( $product->get_name() ),
			'Order searches should extend to the names of product items.'
		);
	}

	public function test_search_orders_checks_table_for_product_item_matches_with_like_comparison() {
		$instance = new WC_Order_Data_Store_Custom_Table();
		$product  = $this->factory()->product->create_and_get( array(
			'post_title' => 'foo bar baz',
		) );
		$order    = $this->factory()->order->create_and_get();
		$order->add_product( $product );
		$order->save();

		$this->assertEquals(
			array( $order->get_id() ),
			$instance->search_orders( 'bar' ),
			'Product items should be searched using a LIKE comparison and wildcards.'
		);
	}

	/**
	 * @dataProvider order_type_provider()
	 */
	public function test_get_order_type( $order_type ) {
		$instance = new WC_Order_Data_Store_Custom_Table();
		$order    = $this->factory()->order->create( array(
			'post_type' => $order_type,
		) );

		$this->assertEquals(
			$order_type,
			$instance->get_order_type( $order )
		);
	}

	/**
	 * Provide a list of all available order types.
	 */
	public function order_type_provider() {
		$types = array();

		foreach ( wc_get_order_types() as $type ) {
			$types[ $type ] = array( $type );
		}

		return $types;
	}

	/**
	 * Shortcut for setting up reflection methods + properties for update_post_meta().
	 *
	 * @param WC_Order $order    The order object, passed by reference.
	 * @param bool     $creating Optional. The value 'creating' property in the new instance.
	 *                           Default is false.
	 */
	protected function invoke_update_post_meta( &$order, $creating = false ) {
		$instance = new WC_Order_Data_Store_Custom_Table();

		$property = new ReflectionProperty( $instance, 'creating' );
		$property->setAccessible( true );
		$property->setValue( $instance, (bool) $creating );

		$method   = new ReflectionMethod( $instance, 'update_post_meta' );
		$method->setAccessible( true );
		$method->invokeArgs( $instance, array( &$order ) );
	}
}
