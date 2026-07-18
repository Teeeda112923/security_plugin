/**
 * AJAX refresh for the dashboard widget.
 * Dynamic data supplied by wp_localize_script() as cnscWidgetData.
 */
function cnscRefresh( btn ) {
	var widget = document.getElementById( 'wsc-widget' );
	if ( ! widget ) { return; }
	btn.disabled    = true;
	btn.textContent = cnscWidgetData.refreshingText;
	var xhr = new XMLHttpRequest();
	xhr.open( 'POST', cnscWidgetData.ajaxUrl );
	xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
	xhr.onload = function () {
		if ( 200 === xhr.status ) { widget.outerHTML = xhr.responseText; }
		btn.disabled = false;
	};
	xhr.send( 'action=cnsc_refresh&nonce=' + btn.dataset.nonce );
}
