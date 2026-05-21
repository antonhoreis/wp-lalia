(function($){'use strict';
$(document).ready(function(){
  var $apiKeyField = $('#wp_sso_api_key');
  if($apiKeyField.length){
    var $toggleBtn = $('<button type="button" class="button button-secondary wp-sso-toggle-api-key"><span class="dashicons dashicons-visibility"></span></button>');
    $apiKeyField.after($toggleBtn);
    $apiKeyField.attr('type','password');
    $toggleBtn.on('click', function(e){ e.preventDefault(); if($apiKeyField.attr('type')==='password'){ $apiKeyField.attr('type','text'); $(this).find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden'); } else { $apiKeyField.attr('type','password'); $(this).find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility'); } });
  }
  var $domainField = $('#wp_sso_other_website_domain');
  if($domainField.length){
    $domainField.on('blur', function(){ var domain=$(this).val(); domain=domain.replace(/^https?:\/\//,''); domain=domain.replace(/\/$/,''); $(this).val(domain); var pattern=/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/i; if(domain && !pattern.test(domain)){ $(this).css('border-color','#dc3232'); if(!$(this).next('.wp-sso-error').length){ $(this).after('<span class="wp-sso-error" style="color:#dc3232; display:block; margin-top:5px;">Please enter a valid domain name</span>'); } } else { $(this).css('border-color',''); $(this).next('.wp-sso-error').remove(); } });
  }
  $('form').on('submit', function(){ var apiKey=$('#wp_sso_api_key').val(); var domain=$('#wp_sso_other_website_domain').val(); var errorUrl=$('#wp_sso_error_page_url').val(); if(!apiKey){ alert('Please enter the API Key'); $('#wp_sso_api_key').focus(); return false; } if(!domain){ alert('Please enter the Other Website Domain'); $('#wp_sso_other_website_domain').focus(); return false; } if(!errorUrl){ alert('Please enter the Error Page URL'); $('#wp_sso_error_page_url').focus(); return false; } return true; });
  if($('.wp-list-table.sso-logs').length){ setInterval(function(){ if(!document.hidden && !$(':focus').length){ location.reload(); } }, 30000); }
  $('.wp-sso-clear-logs').on('click', function(e){ if(!confirm('Are you sure you want to clear all logs? This action cannot be undone.')){ e.preventDefault(); } });
});
})(jQuery);


