<?php
/* Copyright (C) 2013-2014 Cédric Salvador <csalvador@gpcsolutions.fr>
 * Copyright (C) 2015 Maxime Lafourcade <mlafourcade@gpcsolutions.fr>
 * Copyright (C) 2015 Raphaël Doursenaud <rdoursenaud@gpcsolutions.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Tax management
 *
 * Dolibarr and WooCommerce tax implementations differ vastly.
 * We declare specific WooCommerce tax classes and rates to be used in Dolibarr syncing.
 *
 * @package DoliWoo
 */

/**
 * Tax management
 */
class Doliwoo_WC_Tax extends WC_Tax {

	/**
	 * Get the tax class associated with a VAT rate
	 *
	 * @param float $tax_rate A product VAT rate
	 *
	 * @return string The first tax class corresponding to the input VAT rate
	 */
	public function get_tax_class( $tax_rate ) {
		$tax_classes = $this->get_tax_classes();

		// Add missing standard rate
		$tax_classes[] = '';

		foreach ( $tax_classes as $k => $class ) {
			$rates = $this->get_rates( $class );
			$rates_values = array_values( $rates );
			if ( !empty($rates_values[0]['rate']) && $rates_values[0]['rate'] == $tax_rate ) {
				// Use the first class found
				return $class;
			}
		}
		// No class found, use the standard rate
		return '';
	}

	/**
	 * Create tax classes for Dolibarr tax rates
	 *
	 * @return void
	 */
	public function create_custom_tax_classes() {
		global $wpdb;
		$tax_name = __( 'VAT', 'doliwoo' );

		$default_country = substr( strtolower( get_option( 'woocommerce_default_country' ) ), 0, 2 );

		/** @var array $declared_rates The contry's VAT rate */
		include( 'tax_rates/' . $default_country . '.php' );

		$db_taxes = $wpdb->get_results(
			'SELECT tax_rate_id
, tax_rate_country
, tax_rate
, tax_rate_name
, tax_rate_priority
, tax_rate_order
, tax_rate_class FROM ' . $wpdb->prefix . 'woocommerce_tax_rates;',
			ARRAY_A
		);

		// Struture database results
		$database_rates = array();
		foreach ( $db_taxes as $tax ) {
			$tax_id                    = $tax['tax_rate_id'];
			$database_rates[ $tax_id ] = $tax;
			unset( $database_rates[ $tax_id ]['tax_rate_id'] );
		}

		// Insert missing classes
		$declared_classes = wp_list_pluck( $declared_rates, 'tax_rate_class' );
		$database_classes = wp_list_pluck( $database_rates, 'tax_rate_class' );
		foreach ( $declared_classes as $key => $class ) {
			if ( ! in_array( $class, $database_classes ) ) {
				$to_create = $declared_rates[ $key ];
				$this->insert_tax( $to_create );
			}
		}

		// Update existing tax classes if declared rates have changed
		foreach ( $declared_rates as $declared_rate ) {
			foreach ( $database_rates as $tax_rate_id => $database_rate ) {
				if ( $declared_rate['tax_rate_class']
				     == $database_rate['tax_rate_class']
				) {
					// _assoc is important. It allows strict checking to take 0 into account!
					if ( array_diff_assoc( $declared_rate, $database_rate ) ) {
						$this->update_tax(
							$tax_rate_id,
							$declared_rate
						);
					}
				}
			}
		}

		// Now declare the classes
		$declared_classes = array_map( 'ucfirst', $declared_classes );
		$classes_names    = implode( "\n", $declared_classes );
		$existing_classes = get_option( 'woocommerce_tax_classes' );
		update_option(
			'woocommerce_tax_classes',
			$existing_classes . $classes_names
		);
	}

	/**
	 * Save tax rates
	 *
	 * @param array $tax_rate Rate description
	 *
	 * @return int
	 */
	public function insert_tax( $tax_rate ) {
		global $wpdb;

		$wpdb->insert( $wpdb->prefix . 'woocommerce_tax_rates', $tax_rate );

		return $wpdb->insert_id;
	}

	/**
	 * Update tax rates
	 *
	 * @param int $tax_rate_id Element to update
	 * @param array $tax_rate Rate description
	 *
	 * @return false|int
	 */
	public function update_tax( $tax_rate_id, $tax_rate ) {
		global $wpdb;

		return $wpdb->update(
			$wpdb->prefix . 'woocommerce_tax_rates',
			$tax_rate,
			array(
				'tax_rate_id' => $tax_rate_id,
			)
		);
	}
}
