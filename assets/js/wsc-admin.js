/**
 * AJAX refresh for the dedicated admin page.
 * Dynamic data supplied by wp_localize_script() as wscAdminData.
 */
function wscAdminRefresh( btn ) {
	var body = document.getElementById( 'wsc-admin-body' );
	if ( ! body ) { return; }
	btn.disabled    = true;
	btn.textContent = wscAdminData.refreshingText;
	var xhr = new XMLHttpRequest();
	xhr.open( 'POST', wscAdminData.ajaxUrl );
	xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
	xhr.onload = function () {
		if ( 200 === xhr.status ) { body.outerHTML = xhr.responseText; }
	};
	xhr.send( 'action=wsc_admin_refresh&nonce=' + btn.dataset.nonce );
}
