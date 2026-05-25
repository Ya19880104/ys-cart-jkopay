/**
 * 街口支付（JKoPay）後台測試連線 — v2.39.5
 *
 * 對應 PHP 端：
 *   - 模板：templates/admin/gateways/jkopay-settings.php（含 #ys-ec-jkopay-test-connection 按鈕）
 *   - 端點：POST /wp-json/ys-ecommerce-headless/v1/admin/jkopay/test-connection
 *   - 控制器：YSJkopayTestConnectionController
 *
 * 流程：
 *   1. 使用者按下「測試連線」 → 顯示「測試中...」
 *   2. fetch POST 帶 X-WP-Nonce
 *   3. 解析 response.success / auth_ok 顯示綠色 ✓ 或紅色 ✗ 標記
 *   4. 顯示 round-trip 時間 + test/prod 標記
 */

(function () {
    'use strict';

    var btn = document.getElementById( 'ys-ec-jkopay-test-connection' );
    var status = document.getElementById( 'ys-ec-jkopay-test-status' );

    if ( ! btn || ! status ) {
        return;
    }

    /**
     * 取得 REST nonce — 優先序：
     *   1. wpApiSettings.nonce（WP 註冊 'wp-api' script 後注入）
     *   2. window.ysJkopayTestConnection.nonce（PHP 端 wp_localize_script 注入）
     */
    function getNonce() {
        if ( window.wpApiSettings && window.wpApiSettings.nonce ) {
            return window.wpApiSettings.nonce;
        }
        if ( window.ysJkopayTestConnection && window.ysJkopayTestConnection.nonce ) {
            return window.ysJkopayTestConnection.nonce;
        }
        return '';
    }

    /**
     * 取得 REST 端點 root；若 PHP 端有注入（例如 multisite path），使用之；否則 fallback
     */
    function getEndpoint() {
        if ( window.ysJkopayTestConnection && window.ysJkopayTestConnection.endpoint ) {
            return window.ysJkopayTestConnection.endpoint;
        }
        return '/wp-json/ys-ecommerce-headless/v1/admin/jkopay/test-connection';
    }

    /**
     * 渲染狀態
     *
     * @param {string} html
     */
    function render( html ) {
        status.innerHTML = html;
    }

    /**
     * 失敗訊息渲染（紅色）
     */
    function renderFail( title, detail ) {
        var detailHtml = detail ? ' <span style="color:#6b7280;">(' + detail + ')</span>' : '';
        render( '<span style="color:#dc2626;font-weight:600;">✗ ' + escape( title ) + '</span>' + detailHtml );
    }

    /**
     * 成功訊息渲染（綠色）
     */
    function renderPass( rtMs, isTestMode ) {
        var modeLabel = isTestMode ? 'TEST' : 'PROD';
        render(
            '<span style="color:#16a34a;font-weight:600;">✓ 連線正常</span>' +
            ' <span style="color:#6b7280;">(' + rtMs + 'ms, ' + modeLabel + ')</span>'
        );
    }

    /**
     * 字串 escape（防 XSS — server response 不可信）
     */
    function escape( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#39;' );
    }

    btn.addEventListener( 'click', function () {
        btn.disabled = true;
        render( '<span style="color:#6b7280;">測試中...</span>' );

        var nonce = getNonce();
        if ( ! nonce ) {
            renderFail( '無法取得 nonce', '請重新整理頁面再試' );
            btn.disabled = false;
            return;
        }

        fetch( getEndpoint(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': nonce,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: '{}'
        } )
        .then( function ( resp ) {
            // 即使 4xx 也要解 body（後端會帶 error 欄位）
            return resp.json().then( function ( data ) {
                return { httpOk: resp.ok, status: resp.status, data: data };
            } );
        } )
        .then( function ( result ) {
            var data = result.data || {};

            if ( data.error === 'incomplete_config' ) {
                var missing = Array.isArray( data.missing ) ? data.missing.join( ', ' ) : '未知欄位';
                renderFail( '設定不完整', '缺：' + missing );
                return;
            }

            if ( data.error === 'exception' ) {
                renderFail( '測試發生例外', data.message || '請查看 log' );
                return;
            }

            if ( data.success && data.auth_ok ) {
                renderPass( data.rt_ms || 0, !! data.test_mode );
                return;
            }

            // 非 auth_ok（HTTP 4xx/5xx 或 response code 命中黑名單）
            var http = data.http_status || result.status || 0;
            var code = data.response_code || '—';
            renderFail( '認證失敗', 'HTTP ' + http + ', code: ' + code );
        } )
        .catch( function ( err ) {
            renderFail( '網路錯誤', err && err.message ? err.message : 'unknown' );
        } )
        .then( function () {
            btn.disabled = false;
        } );
    } );
}());
