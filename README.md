# PDF Forms Filler for WPForms

Build WPForms forms from PDF forms. Get PDFs auto-filled and attached to email messages and/or website responses on form submission.

## Description

[![PDF Forms Filler Intro](https://img.youtube.com/vi/PhcPZwDXlh8/0.jpg)](https://www.youtube.com/watch?v=PhcPZwDXlh8 "PDF Forms Filler Intro")

This plugin allows WPForms users to add PDF attachments filled with form submission data to notifications and confirmations of WPForms.

If the PDF attachment has a PDF form, the plugin allows users to add fields to the WPForms form and/or link them to fields in the PDF. The plugin also allows the attached PDF files to be embedded with images supplied by the WPForms fields. The filled PDF files can be saved on the web server.

When your website visitor submits your WPForms form, the form in the PDF file is filled with the form information, images are embedded and the resulting PDF file is attached to the WPForms email message. The resulting PDF file can also be downloaded by your website visitors if this option is enabled in your form's options.

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
