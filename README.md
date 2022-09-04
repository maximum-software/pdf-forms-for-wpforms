# PDF Forms Filler for WPForms

Build WPForms forms from PDF forms. Get PDFs auto-filled and attached to email messages and/or website responses on form submission.

## Description

[![PDF Forms Filler Intro](https://img.youtube.com/vi/PhcPZwDXlh8/0.jpg)](https://www.youtube.com/watch?v=PhcPZwDXlh8 "PDF Forms Filler Intro")

This plugin allows WPForms users to add PDF attachments filled with form submission data to notifications and confirmations of WPForms.

If the PDF attachment has a PDF form, the plugin allows users to add fields to the WPForms form and/or link them to fields in the PDF. The plugin also allows the attached PDF files to be embedded with images supplied by the WPForms fields. The filled PDF files can be saved on the web server.

When your website visitor submits your WPForms form, the form in the PDF file is filled with the form information, images are embedded and the resulting PDF file is attached to the WPForms email message. The resulting PDF file can also be downloaded by your website visitors if this option is enabled in your form's options.

An external web API (https://pdf.ninja) is used for filling PDF forms (free usage has limitations).

Requirements:
* PHP 5.5 or newer
* WordPress 5.4 or newer
* WPForms 1.6.9 or newer
* Chrome 60, Firefox 56 (or equivalent) or newer

Known problems:
* Some third party plugins may break the functionality of this plugin (see a list below). Try troubleshooting the problem by disabling likely plugins that may cause issues, such as plugins that modify WordPress or WPForms in radical ways.
* Some image optimization plugins optimize PDFs and strip PDF forms from PDF files. This may cause your existing forms to break at a random point in the future (when PDF file cache times out at the API).
* The no longer used by default version of the API (v1) produces PDFs which may not render properly in some PDF readers and with some UTF-8 (non-latin) characters, checkboxes and radio buttons.

Known incompatible plugins:
* [Imagify](https://wordpress.org/plugins/imagify/) (strips forms from PDF files)
* [ShortPixel Image Optimizer](https://wordpress.org/plugins/shortpixel-image-optimiser/) (strips forms from PDF files)

## Installation

1. Install the [WPForms](https://wordpress.org/plugins/wpforms-lite/) plugin.
2. Upload this plugin's folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
3. Activate the plugin through the 'Plugins' screen in WordPress.
4. Start using the 'PDF Forms' section in the WPForms editor under settings.

## Screenshots

![PDF Forms section is available to access PDF attachments interface](assets/screenshot-1.png?raw=true)

![PDF attachment interface that allows users to attach PDF files and set attachment options](assets/screenshot-2.png?raw=true)

![Field Mapper Tool that allows users to generate and map fields, smart tags and values](assets/screenshot-3.png?raw=true)

![Image Embedding Tool that allows users to embed images into PDFs](assets/screenshot-4.png?raw=true)

![Filled PDF file](assets/screenshot-5.png?raw=true)

## Special Thanks

Special thanks to the following sponsors of this plugin,

[![BrowserStack](assets/BrowserStack.png)](https://www.browserstack.com/)

[BrowserStack](https://www.browserstack.com/)
