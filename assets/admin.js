(function ($) {
  'use strict';

  function toggleMode() {
    var mode = $('#dca-mode').val();
    $('.dca-client-row').toggle(mode === 'client');
    $('.dca-server-row').toggle(mode === 'server');
  }

  function shortcode() {
    var group = $('#dca-group').val() || '';
    var speed = Math.max(5, parseInt($('#dca-speed').val(), 10) || 15);
    var align = $('#dca-align').val() || 'center';
    var width = $('#dca-width').val() || '100%';
    var height = $('#dca-height').val() || 'auto';
    var code = '[dca_ads' + (group ? ' group="' + group + '"' : '') + ' speed="' + speed + '" align="' + align + '" width="' + width + '" height="' + height + '"]';
    $('#dca-shortcode').val(code);
    $('#dca-preview').html(group ? '<code>' + $('<div>').text(code).html() + '</code><p>Save settings, then place this shortcode on a page to view the live ad.</p>' : '<p>Select an ad group.</p>');
  }

  function loadGroups() {
    $('#dca-group-status').text('Loading...').removeClass('error success');
    $.post(DCAAdsAdmin.ajaxUrl, { action: 'dca_ads_get_groups', nonce: DCAAdsAdmin.nonce })
      .done(function (r) {
        if (!r.success) throw new Error(r.data && r.data.message ? r.data.message : 'Unable to load groups.');
        var $select = $('#dca-group').empty().append('<option value="">Select a group</option>');
        (r.data.groups || []).forEach(function (g) {
          $select.append($('<option>').val(g.id).text(g.name + ' (' + g.count + ')'));
        });
        $('#dca-group-status').text('Connected — ' + (r.data.groups || []).length + ' group(s) found.').addClass('success');
        shortcode();
      })
      .fail(function (xhr) {
        var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Unable to load groups.';
        $('#dca-group').html('<option value="">Unable to load groups</option>');
        $('#dca-group-status').text(msg).addClass('error');
      });
  }

  $(function () {
    toggleMode();
    loadGroups();
    $('#dca-mode').on('change', toggleMode);
    $('#dca-group,#dca-speed,#dca-align,#dca-width,#dca-height').on('change input', shortcode);
    $('#dca-copy').on('click', function () {
      navigator.clipboard.writeText($('#dca-shortcode').val());
      $(this).text('Copied!');
      var btn = this;
      setTimeout(function () { $(btn).text('Copy to Clipboard'); }, 1200);
    });
    $('#dca-test').on('click', function () {
      var $result = $('#dca-test-result').text(' Testing...').removeClass('error success');
      $.post(DCAAdsAdmin.ajaxUrl, { action: 'dca_ads_test_connection', nonce: DCAAdsAdmin.nonce })
        .done(function (r) {
          if (r.success) $result.text(' ' + r.data.message).addClass('success');
          else $result.text(' ' + (r.data && r.data.message ? r.data.message : 'Connection failed.')).addClass('error');
        })
        .fail(function (xhr) {
          var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Connection failed.';
          $result.text(' ' + msg).addClass('error');
        });
    });
  });
})(jQuery);
