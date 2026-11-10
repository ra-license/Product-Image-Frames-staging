=== R&A Product Image Frames ===
Contributors: ramarketing
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPL-2.0-or-later

Keeps product photos the same size and lined up, without painting bars into the photos.

== Description ==

Manufacturer photos come in every shape. Category pages look best when every
photo sits in a box of the same size. Smart Image Resize did that by painting
padding into a copy of each photo. Since its 1.18.0 update, that padding
can come out black.

This plugin does the same job a safer way:

* On category and product pages, it shows each product's original photo,
  the one you uploaded, which is always clean.
* The page draws a white box in the shape your store already uses, and the
  photo sits centered inside it. Nothing is cropped.
* No new image files are made and no pixels are painted. Turning the plugin
  off puts every page back exactly as it was.

It only uses WordPress's own tools. It doesn't depend on Smart Image Resize,
so that plugin's updates can't break it.

== Installation ==

1. Plugins > Add New > Upload Plugin > choose ra-product-image-frames-0.1.1.zip > Install Now > Activate.
2. Clear the page cache: WP Rocket > Clear Cache, then CDN Cache > Purge Everything.
3. Open a category page in a private window. Every photo should sit in a white box.

To compare with the old look while you're logged in as an admin, add
?rapif=off to the end of any page address.

== Known trade-off in 0.1.0 ==

Original photos are bigger files than the old copies, so category pages load
more data until version 0.2.0 adds light, compressed copies.

== Changelog ==

= 0.1.1 - 2026-11-10 =
* Fix: category and product-page frames now keep the store's shape (4:3 on
  StarFine) even when the image size isn't marked as cropped.

= 0.1.0 - 2026-11-10 =
* First version. Shows clean original product photos centered in white
  frames on category, product and gallery images, replacing the padded copies
  that came out with black bars.
