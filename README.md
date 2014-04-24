WXR Validator
=============

A WP-CLI command to validate WordPress WXR files.

Currently, this will only validate images. Here's a summary of what it does:

* It collects all "attachment" posts in the WXR
* It collects all HTML image references elsewhere in the WXR, e.g. an image url in an &lt;img&gt; tag.
  * *Note:* It identifies these as any image URL wrapped in quotes. It's possible that some references may not be caught, e.g. inline CSS where an image is only wrapped in parentheses.
* For each unique URL found (removing variations), it checks to see that each url is valid and responds with an HTTP response code of 200.


