=== PDF Forms Filler for WPForms ===
Version: 1.1.11
Stable tag: 1.1.11
Requires at least: 5.4
Tested up to: 6.7
Requires PHP: 5.5
Tags: pdf, form, wpforms, email, download
Plugin URI: https://pdfformsfiller.org/
Author: Maximum.Software
Author URI: https://maximum.software/
Contributors: maximumsoftware
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Build WPForms from PDF forms. Get PDFs filled automatically and attached to email messages and/or website responses on form submissions.

== Description ==

[youtube http://www.youtube.com/watch?v=PhcPZwDXlh8]

This plugin allows WPForms users to add PDF attachments filled with form submission data to notifications and confirmations of WPForms.

If the PDF attachment has a PDF form, the plugin allows users to add fields to the WPForms form and/or link them to fields in the PDF. The plugin also allows the attached PDF files to be embedded with images supplied by the WPForms fields. The filled PDF files can be saved on the web server.

When your website visitor submits your WPForms form, the form in the PDF file is filled with the form information, images are embedded and the resulting PDF file is attached to the WPForms notification. The resulting PDF file can also be downloaded by your website visitors if this option is enabled in your form's options.

What makes this plugin special is its approach to preparing PDF files. It is not generating PDF documents from scratch. It modifies the original PDF document that was prepared using third party software and supplied to the plugin. This allows users the freedom to design exactly what they need and use their pre-existing documents.

An external web API (https://pdf.ninja) is used for filling PDF forms (free usage has limitations).

Please see [Pdf.Ninja Terms of Use](https://pdf.ninja/#terms) and [Pdf.Ninja Privacy Policy](https://pdf.ninja/#privacy).

Requirements:
 * PHP 5.5 or newer
 * WordPress 5.4 or newer
 * WPForms 1.6.9 or newer
 * Chrome 63, Firefox 58 (or equivalent) or newer

Known incompatible plugins:
* [Imagify](https://wordpress.org/plugins/imagify/) (strips forms from PDF files)
* [ShortPixel Image Optimizer](https://wordpress.org/plugins/shortpixel-image-optimiser/) (strips forms from PDF files)

Special thanks to the following sponsors of this plugin:
 * [BrowserStack](https://www.browserstack.com/)

## Installation

1. Install the [WPForms](https://wordpress.org/plugins/wpforms-lite/) plugin.
2. Upload this plugin's folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
3. Activate the plugin through the 'Plugins' screen in WordPress.
4. Start using the 'PDF Forms' section in the WPForms editor under settings.

== Changelog ==

= 1.1.11 =

* Release date: December 28, 2024

* Added 'delete all value mappings' button
* Fixed value mapping dropdowns going off-screen caused by very long PDF field option labels
* Fixed localization issues
* Updated language files
* Other bug fixes and improvements

= 1.1.10 =

* Release date: November 11, 2024

* Minor fixes

= 1.1.9 =

* Release date: October 3, 2024

* Bug fixes

= 1.1.8 =

* Release date: May 30, 2024

* Added multiple choice option concatenation when mapped to a text field
* Fixed a bug with value mapping's WPForms field drop-down when custom value wasn't loading in correctly
* Added support for WPForms multiple choice field's choices' 'value' setting
* Fixed an issue with automatic value mapping generation that occurred when options list elements are strings
* Fixed an issue: extra lines and spaces in smart-tag field mappings are causing value mapping mismatches
* Other minor changes

= 1.1.7 =

* Release date: January 16, 2024

* Removed needless file name prefix in confirmation downloads
* Fixed possible issues with API communication caused by non-alphanumeric characters in request boundary
* Other minor fixes and improvements

= 1.1.6 =

* Release date: November 9, 2023

* Fixed issues in page snapshot code
* Improved remote attachment support
* Fixed icon file
* Auto-resize smart-tags textarea
* Fixed an issue with text attachment options not being saved in certain cases
* Other minor fixes and improvements

= 1.1.5 =

* Release date: August 9, 2023

* Fixed a notification attachment failure due to asynchronous notifications
* Fixed an issue that was causing too many page snapshots to be generated
* Minor bug fixes and improvements

= 1.1.4 =

* Release date: June 22, 2023

* Fixed a bug: form settings sometimes are not saved

= 1.1.3 =

* Release date: May 17, 2023

* Added a workaround for GLOB_BRACE flag not being available on some non GNU systems

= 1.1.2 =

* Release date: May 6, 2023

* Bug fixes and improvements

= 1.1.1 =

* Release date: December 2, 2022

* Ensure support for WPForms 1.8.0
* Minor warning message clarification

= 1.1.0 =

* Release date: December 2, 2022

* Some fixes were applied that affect the filling process logic. Please check your forms after the update to make sure everything is working as expected if you think they might be affected!

* Fixed an issue with PDF fields not being cleared with empty CF7 field values (affects prefilled fields in the original PDF file)
* Fixed an issue: value mappings get applied recursively (affects field value mappings that have matching CF7/PDF values)
* Bug fix: value mapping fail to work with null values
* Improved labeling of empty value mapping options
* Improved PDF attachment affecting action detection
* Fixed German translation
* Updated language files
* Other fixes and improvements

= 1.0.0 =

* Release date: September 9, 2022

* Initial release

== Frequently Asked Questions ==

= Does this plugin allow my website users to edit PDF files? =

No. This plugin adds features to the [WPForms](https://wordpress.org/plugins/wpforms-lite/) interface in the WordPress Admin Panel only.

= Does this plugin require special software installation on the web server? =

No. The plugin uses core WordPress and WPForms features only. No special software or PHP extensions are needed. Working with PDF files is done through [Pdf.Ninja API](https://pdf.ninja). It is recommended to have a working SSL/TLS certificate validation with cURL.

= How are WPForms form fields mapped to PDF form fields? =

The field mapper tool allows you to map fields individually and, when needed, generate new WPForms fields on the fly. WPForms fields can be mapped to multiple PDF fields. Mappings can be associated with a specific PDF attachment or all PDF attachments. Field value mappings can also be created, allowing filled PDF fields to be filled with data that differs from the originally filled values.

= My fields are not getting filled, what is wrong? =

Make sure the mapping exists in the list of mappings and the field names match.

If you attached an updated PDF file and your mappings were associated with the old attachment ID then those mappings will be deleted and you will need to recreate them.

Sometimes PDF form fields have validation scripts which prevent value with an incorrect format to be filled in. Date PDF fields must be [formatted with a smart tag](https://wpforms.com/developers/how-to-customize-date-format-in-the-date-smart-tag/).

= How do I update the attached PDF file without attaching a new version and losing attachment ID associated mappings and embeds? =

Try using the [Enable Media Replace plugin](https://wordpress.org/plugins/enable-media-replace/) to replace the PDF file in-place in the Media Library.

= My checkboxes and/or radio buttons are not getting filled, what is wrong? =

Make sure your PDF checkbox/radio field's exported value matches the value of the WPForms form's checkbox value. Usually, it is "On" or "Yes". If you need to display a different value in the WPForms form, you will need to create a value mapping.

WPForms allows you to have multiselect checkboxes, however, PDFs can't have multiple values with checkbox fields. You either need to switch to using a listbox in your PDF or rename your checkboxes such that each has a unique name and then map them appropriately.

Some PDF viewers don't render checkboxes correctly in some PDF files. You may be able to solve this issue by recreating the PDF in a different PDF editor. If you are still using Pdf.Ninja API v1, switching to v2 may resolve your issue.

= How do I remove the watermark in the filled PDF files? =

Please see the [Pdf.Ninja API website](https://pdf.ninja).

== Screenshots ==

1. PDF Forms section is available to access PDF attachments interface
2. PDF attachment interface that allows users to attach PDF files and set attachment options
3. Field Mapper Tool that allows users to generate and map fields, smart tags and values
4. Image Embedding Tool that allows users to embed images into PDFs
5. Filled PDF file
