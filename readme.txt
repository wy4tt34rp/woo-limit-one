=== Woo Limit One Purchase ===
Contributors: ko
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later

Prevents customers from purchasing selected WooCommerce products more than once.

== Description ==

Woo Limit One Purchase adds two controls to WooCommerce products:

* Can only purchase once
* Purchased text

Version 2.0 uses WooCommerce hooks and server-side validation. It does not
replace WooCommerce templates.

== Changelog ==

= 2.0.0 =

* Rebuilt for current WooCommerce releases.
* Removed four WooCommerce 2.1-era template overrides.
* Preserved the existing product metadata and purchased-message behavior.
* Added server-side validation for classic, AJAX, grouped, and Store API cart requests.
* Added checkout validation for returning guest customers identified by billing email.
* Added variation inheritance from the parent product.
* Added indexed purchase-history checks for large legacy order databases while preserving imported account and email history.
* Declared WooCommerce HPOS compatibility.
