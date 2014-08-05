/**
 * Created with JetBrains PhpStorm.
 * User: nikolai
 * Date: 8/22/13
 * Time: 9:51 PM
 * To change this template use File | Settings | File Templates.
 */

jQuery(document).ready(function($) {
	var $panel = $('#banklink-properties');

	$panel.find('.nav-tab-wrapper a').click(function (event){
		event.preventDefault();

		var $this = $(this),
			anchor = $this.attr('href');

		$panel.find('h2.nav-tab-wrapper > a').removeClass('nav-tab-active');
		$this.addClass('nav-tab-active');

		$panel.find('div[id^="tabs-banklink-"]').hide();
		$panel.find(anchor).show();
	});

	$panel.find('.nav-tab-wrapper a').first().addClass('nav-tab-active');
	$panel.find('div[id^="tabs-banklink-"]').first().show();
});
