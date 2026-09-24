/**
 * @file js/BlogFormHandler.js
 *
 * @package plugins.generic.blog
 */
(function($) {
	$.pkp.controllers.form.blog = $.pkp.controllers.form.blog || {};
	$.pkp.controllers.form.blog.BlogFormHandler = function($formElement, options) {
		this.parent($formElement, options);
	};
	$.pkp.classes.Helper.inherits(
		$.pkp.controllers.form.blog.BlogFormHandler,
		$.pkp.controllers.form.AjaxFormHandler
	);
}(jQuery));
