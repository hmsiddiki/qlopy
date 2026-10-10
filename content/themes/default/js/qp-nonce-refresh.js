(function () {
  'use strict';

  /*
   * Opt-in usage:
   *
   * window.QPNonceRefresh = { endpoint: '/ajax.php' };
   *
   * <form data-qp-nonce-action="add_post" data-qp-nonce-ttl="7200">
   *   <input type="hidden" name="nonce" data-qp-nonce value="<?= qp_create_nonce('add_post') ?>">
   * </form>
   *
   * Register the matching action server-side:
   * qp_register_nonce_refresh_action('add_post', 7200);
   */
  var config = window.QPNonceRefresh;
  if (!config || typeof config.endpoint !== 'string' || config.endpoint === '') {
    return;
  }

  function refresh(form) {
    var action = form.getAttribute('data-qp-nonce-action');
    var field = form.querySelector('[data-qp-nonce]');
    if (!action || !field) {
      return Promise.reject(new Error('A refreshable form requires an action and nonce field.'));
    }

    var body = new URLSearchParams({
      action: 'qp_refresh_nonce',
      nonce_action: action
    });

    return fetch(config.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-QP-Nonce-Refresh': '1'
      },
      body: body.toString()
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('Nonce refresh request failed with status ' + response.status + '.');
      }
      return response.json();
    }).then(function (payload) {
      if (!payload || payload.status !== 'success' || typeof payload.nonce !== 'string') {
        throw new Error('Nonce refresh response was invalid.');
      }
      field.value = payload.nonce;
      schedule(form);
      form.dispatchEvent(new CustomEvent('qpnonce:refreshed', { detail: payload }));
      return payload;
    });
  }

  function schedule(form) {
    var ttl = Number(form.getAttribute('data-qp-nonce-ttl') || 7200);
    if (!Number.isFinite(ttl) || ttl <= 0) {
      return;
    }
    window.setTimeout(function () {
      refresh(form).catch(function (error) {
        console.warn('Qlopy nonce refresh failed.', error);
        form.dispatchEvent(new CustomEvent('qpnonce:refresherror', { detail: error }));
      });
    }, Math.max(1000, Math.floor(ttl * 0.75 * 1000)));
  }

  window.qpRefreshNonce = refresh;

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-qp-nonce-action]').forEach(schedule);
  });
}());
