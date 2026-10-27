/* Click Hive Calls: inbox playback and status changes. */
(function () {
  'use strict';
  if (!window.CHC) return;

  var headers = { 'X-WP-Nonce': CHC.nonce };
  var current = null; // { audio, url, button }

  function stopCurrent() {
    if (!current) return;
    current.audio.pause();
    URL.revokeObjectURL(current.url);
    current.audio.remove();
    current.button.hidden = false;
    current = null;
  }

  // Play: fetch the audio with the user's session, play it from memory.
  // No playable link ever exists in the page, so nothing can be copied or shared.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.chc-play');
    if (!btn) return;
    var id = btn.getAttribute('data-id');
    stopCurrent();
    var label = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = CHC.text.loading;

    fetch(CHC.root + id + '/audio', { headers: headers, credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        if (r.status === 410) throw new Error(CHC.text.expired);
        if (!r.ok) throw new Error(CHC.text.noplay);
        return r.blob();
      })
      .then(function (blob) {
        var url = URL.createObjectURL(blob);
        var audio = document.createElement('audio');
        audio.controls = true;
        audio.src = url;
        audio.className = 'chc-audio';
        btn.hidden = true;
        btn.parentNode.appendChild(audio);
        // First play: clear the Unheard marker and lower the tab count.
        if (btn.classList.contains('chc-unheard')) {
          btn.classList.remove('chc-unheard');
          var tag = btn.parentNode.querySelector('.chc-unheard-tag');
          if (tag) tag.remove();
          var count = document.querySelector('.chc-views [data-view="unheard"] .chc-view-count');
          if (count) {
            var n = Math.max(0, parseInt(count.textContent, 10) - 1);
            count.textContent = n;
            if (!n) count.closest('a').classList.remove('chc-has-unheard');
          }
        }
        current = { audio: audio, url: url, button: btn };
        audio.play().catch(function () {});
      })
      .catch(function (err) {
        window.alert(err.message || CHC.text.noplay);
      })
      .then(function () {
        btn.disabled = false;
        btn.innerHTML = label;
      });
  });

  // Details: show or hide the row under a call.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.chc-toggle');
    if (!btn) return;
    var details = document.getElementById(btn.getAttribute('aria-controls'));
    if (!details) return;
    var open = details.hidden;
    details.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.closest('tr').classList.toggle('chc-expanded', open);
  });

  // Status and category: save as soon as it changes.
  document.addEventListener('change', function (e) {
    var sel = e.target.closest('.chc-field');
    if (!sel) return;
    var row = sel.closest('tr');
    var body = {};
    body[sel.getAttribute('data-field')] = sel.value;
    sel.disabled = true;

    fetch(CHC.root + row.getAttribute('data-id'), {
      method: 'POST',
      headers: Object.assign({ 'Content-Type': 'application/json' }, headers),
      credentials: 'same-origin',
      body: JSON.stringify(body),
    })
      .then(function (r) {
        if (!r.ok) throw new Error();
        return r.json();
      })
      .then(function (data) {
        var by = row.querySelector('.chc-by');
        if (by && data.handled_by && body.status !== undefined) by.textContent = data.handled_by;
        if (body.status !== undefined) {
          var first = sel.options[0] && sel.options[0].value;
          row.classList.toggle('chc-open', sel.value === first);
          var badge = document.querySelector('.chc-count .pending-count');
          if (badge) {
            badge.textContent = data.open_count;
            badge.parentNode.style.display = data.open_count ? '' : 'none';
          }
        }
        sel.classList.add('chc-saved');
        setTimeout(function () { sel.classList.remove('chc-saved'); }, 1200);
      })
      .catch(function () {
        window.alert(CHC.text.failed);
      })
      .then(function () {
        sel.disabled = false;
      });
  });

  // Delete (administrators only): remove the call and its details row.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.chc-delete');
    if (!btn) return;
    if (!window.confirm(CHC.text.confirm)) return;
    var id = btn.getAttribute('data-id');
    btn.disabled = true;
    fetch(CHC.root + id, { method: 'DELETE', headers: headers, credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error();
        return r.json();
      })
      .then(function () {
        var row = document.querySelector('tr[data-id="' + id + '"]');
        var details = document.getElementById('chc-details-' + id);
        if (details) details.remove();
        if (row) row.remove();
      })
      .catch(function () {
        btn.disabled = false;
        window.alert(CHC.text.nodelete);
      });
  });
})();
