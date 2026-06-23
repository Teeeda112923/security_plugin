/**
 * AJAX refresh for the dashboard widget.
 * Dynamic data supplied by wp_localize_script() as wscWidgetData.
 */
function wscRefresh( btn ) {
	var widget = document.getElementById( 'wsc-widget' );
	if ( ! widget ) { return; }
	btn.disabled    = true;
	btn.textContent = wscWidgetData.refreshingText;
	var xhr = new XMLHttpRequest();
	xhr.open( 'POST', wscWidgetData.ajaxUrl );
	xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
	xhr.onload = function () {
		if ( 200 === xhr.status ) { widget.outerHTML = xhr.responseText; }
		btn.disabled = false;
	};
	xhr.send( 'action=wsc_refresh&nonce=' + btn.dataset.nonce );
}
