(function () {
  var el = document.getElementById('fv-top-redirect');
  if (!el) {
    return;
  }
  var url = el.getAttribute('data-url');
  if (!url) {
    return;
  }
  var target = window.top && window.top !== window ? window.top : window;
  target.location.href = url;
})();
