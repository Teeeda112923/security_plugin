<?php
/**
 * 診断オーケストレーター
 *
 * @package CyberNote_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs all diagnostic categories and returns combined results.
 */
class CNSC_Diagnostics {

	/**
	 * Run all checks and return results grouped by category.
	 *
	 * @return array
	 */
	public function run() {
		$cat_a  = ( new CNSC_Category_A() )->run();
		$cat_b  = ( new CNSC_Category_B() )->run();
		$all    = array_merge( $cat_a, $cat_b );
		$issues = count(
			array_filter(
				$all,
				function ( $r ) {
					return 'good' !== $r['status'];
				}
			)
		);

		return array(
			'a'       => $cat_a,
			'b'       => $cat_b,
			'summary' => array(
				'total'  => count( $all ),
				'issues' => $issues,
			),
		);
	}
}
