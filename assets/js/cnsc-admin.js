/**
 * AJAX refresh for the dedicated admin page.
 * Dynamic data supplied by wp_localize_script() as cnscAdminData.
 */
function cnscAdminRefresh( btn ) {
	var body = document.getElementById( 'wsc-admin-body' );
	if ( ! body ) { return; }
	var originalHtml = btn.innerHTML;
	var fail = function () {
		btn.disabled  = false;
		btn.innerHTML = originalHtml;
		window.alert( cnscAdminData.errorText );
	};
	btn.disabled    = true;
	btn.textContent = cnscAdminData.refreshingText;
	var xhr = new XMLHttpRequest();
	xhr.open( 'POST', cnscAdminData.ajaxUrl );
	xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
	xhr.onload = function () {
		// 権限・nonceエラー時は "-1" や "0" が返るため、診断結果のHTMLかを確認する。
		if ( 200 === xhr.status && -1 !== xhr.responseText.indexOf( 'wsc-admin-body' ) ) {
			body.outerHTML = xhr.responseText;
		} else {
			fail();
		}
	};
	xhr.onerror = fail;
	xhr.send( 'action=cnsc_admin_refresh&nonce=' + encodeURIComponent( btn.dataset.nonce ) );
}
