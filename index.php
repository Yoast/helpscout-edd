<?php
/**
 * HelpScout EDD integration.
 *
 * This code is based in large part on an example provided by HelpScout and then modified for Easy Digital Downloads and WP.
 */

// We use core, so we include it.
require '../wp-load.php';

// Require the settings file for the secret key
require './settings.php';

class PluginHandler {
	private $input = false;

	/**
	 * Returns the requested HTTP header.
	 *
	 * @param string $header
	 * @return bool|string
	 */
	private function getHeader( $header ) {
		if ( isset( $_SERVER[$header] ) ) {
			return $_SERVER[$header];
		}
		return false;
	}

	/**
	 * Retrieve the JSON input
	 *
	 * @return bool|string
	 */
	private function getJsonString() {
		if ( $this->input === false ) {
			$this->input = @file_get_contents( 'php://input' );
		}
		return $this->input;
	}

	/**
	 * Generate the signature based on the secret key, to compare in isSignatureValid
	 *
	 * @return bool|string
	 */
	private function generateSignature() {
		$str = $this->getJsonString();
		if ( $str ) {
			return base64_encode( hash_hmac( 'sha1', $str, HELPSCOUT_SECRET_KEY, true ) );
		}
		return false;
	}

	/**
	 * Returns true if the current request is a valid webhook issued from Help Scout, false otherwise.
	 *
	 * @return boolean
	 */
	private function isSignatureValid() {
		$signature = $this->generateSignature();
		return $signature == $this->getHeader( 'HTTP_X_HELPSCOUT_SIGNATURE' );
	}

	/**
	 * Create a response.
	 *
	 * @return array
	 */
	public function getResponse() {
		$ret = array( 'html' => '' );

		if ( !$this->isSignatureValid() ) {
			return array( 'html' => 'Invalid signature' );
		}
		$data = json_decode( $this->input, true );

		// do some stuff
		$ret['html'] = $this->fetchHtml( $data );

		// Used for debugging
		// $ret['html'] = '<pre>'.print_r($data,1).'</pre>' . $ret['html'];

		return $ret;
	}

	/**
	 * Generate output for the response.
	 *
	 * @param $data
	 * @return string
	 */
	private function fetchHtml( $data ) {
		global $wpdb;

		if ( isset( $data['customer']['emails'] ) && is_array( $data['customer']['emails'] ) ) {
			$email_query = "IN (";
			foreach ( $data['customer']['emails'] as $email ) {
				$email_query .= "'" . $email . "',";
			}
			$email_query = rtrim( $email_query, ',' );
			$email_query .= ')';
		} else {
			$email_query = "= '" . $data['customer']['email'] . "'";
		}

		$query   = "SELECT pm2.post_id, pm2.meta_value, p.post_status FROM $wpdb->postmeta pm, $wpdb->postmeta pm2, $wpdb->posts p WHERE pm.meta_key = '_edd_payment_user_email' AND pm.meta_value $email_query AND pm.post_id = pm2.post_id AND pm2.meta_key = '_edd_payment_meta' AND pm.post_id = p.ID AND p.post_status NOT IN ('failed','revoked') ORDER BY pm.post_id DESC";
		$results = $wpdb->get_results( $query );

		if ( !$results ) {
			$query   = "SELECT pm.post_id, pm.meta_value, p.post_status FROM $wpdb->postmeta pm, $wpdb->posts p WHERE pm.meta_key = '_edd_payment_meta' AND pm.meta_value LIKE '%%" . $data['customer']['fname'] . "%%' AND pm.meta_value LIKE '%%" . $data['customer']['lname'] . "%%' AND pm.post_id = p.ID AND p.post_status NOT IN ('failed','revoked') ORDER BY pm.post_id DESC";
			$results = $wpdb->get_results( $query );
		}

		if ( !$results ) {
			return 'No license data found.';
		}

		$orders = array();
		foreach ( $results as $result ) {
			$order         = array();
			$order['link'] = '<a target="_blank" href="' . get_admin_url( null, 'edit.php?post_type=download&page=edd-payment-history&edd-action=edit-payment&purchase_id=' . $result->post_id ) . '">#' . $result->post_id . '</a>';

			$post = get_post( $result->post_id );

			$purchase = maybe_unserialize( $result->meta_value );

			$order['date'] = $post->post_date;
			unset( $post );

			$order['id']             = $result->post_id;
			$order['status']         = $result->post_status;
			$order['amount']         = edd_get_payment_amount( $result->post_id );
			$order['payment_method'] = edd_get_payment_gateway( $result->post_id );

			if ( 'paypal' == $order['payment_method'] ) {
				// Grab the PayPal transaction ID and link the transaction to PayPal
				$notes = edd_get_payment_notes( $result->post_id );
				foreach ( $notes as $note ) {
					if ( preg_match( '/^PayPal Transaction ID: ([^\s]+)/', $note->comment_content, $match ) )
						$order['paypal_transaction_id'] = $match[1];
				}

				$order['payment_method'] = '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_view-a-trans&id=' . $order['paypal_transaction_id'] . '" target="_blank">PayPal</a>';
			}

			$downloads = maybe_unserialize( $purchase['downloads'] );
			if ( $downloads ) {
				$license_keys = '';
				foreach ( maybe_unserialize( $purchase['downloads'] ) as $download ) {

					$id = isset( $purchase['cart_details'] ) ? $download['id'] : $download;

					$licensing = new EDD_Software_Licensing();

					if ( get_post_meta( $id, '_edd_sl_enabled', true ) ) {
						$license = $licensing->get_license_by_purchase( $order['id'], $id );
						$license_keys .= '<strong>' . str_replace( " for WordPress", "", get_the_title( $id ) ) . "</strong><br/>"
							. edd_get_price_option_name( $id, $download['options']['price_id'] ) . '<br/>'
							. get_post_meta( $license->ID, '_edd_sl_key', true ) . '<br/><br/>';
					}
				}
			}

			if ( isset( $license_keys ) )
				$order['downloads'][] = $license_keys;
			$orders[]             = $order;
		}

		$output = '';
		foreach ( $orders as $order ) {
			$output .= '<strong><i class="icon-cart"></i> ' . $order['link'] . '</strong>';
			if ( $order['status'] != 'publish' )
				$output .= ' - <span style="color:orange;font-weight:bold;">' . $order['status'] . '</span>';
			$output .= '<p><span class="muted">' . $order['date'] . '</span><br/>';
			$output .= '$' . $order['amount'] . ' - ' . $order['payment_method'] . '</p>';
			$output .= '<p><i class="icon-pointer"></i><a target="_blank" href="' . add_query_arg( array( 'edd-action' => 'email_links', 'purchase_id' => $order['id'] ), admin_url( 'edit.php?post_type=download&page=edd-payment-history' ) ) . '">' . __( 'Resend Purchase Receipt', 'edd' ) . '</a></p>';
			$output .= '<ul>';
			foreach ( $order['downloads'] as $download ) {
				$output .= '<li>' . $download . '</li>';
			}
			$output .= '</ul>';
		}

		return $output;
	}
}

$plugin = new PluginHandler();

echo json_encode( $plugin->getResponse() );