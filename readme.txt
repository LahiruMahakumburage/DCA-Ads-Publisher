=== DCA Ads Publisher ===
Contributors: Dylan AAWS(Lahiru Mahakumburage)
Tags: adsanity, advertising, remote ads, ad rotation
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 2.1.0

Publishes AdSanity ads from DurhamContests.com on connected WordPress websites without iframes.

== Version 2.1.0 ==
* Rotates ads according to the shortcode speed value.
* Randomly selects from all published ads in the selected AdSanity group.
* Avoids immediately repeating the currently displayed ad when alternatives exist.
* Uses cache-busting requests for reliable rotation.
* Keeps the dca_ads shortcode and Dylan AAWS author details.

Example:
[dca_ads group="14" speed="5" align="center" width="468px" height="60px"]
