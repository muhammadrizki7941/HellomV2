// Editor phone preview only (rendered with editor => true, shown in a sandboxed iframe):
// a tap selects the block instead of following links; the editor highlights the selected
// block and keeps the scroll position when the preview is re-rendered.
(function () {
  var parentWindow = window.parent;
  function send(message) { message.hl = message.hl || 'event'; parentWindow.postMessage(message, '*'); }

  document.addEventListener('click', function (e) {
    var block = e.target.closest ? e.target.closest('[data-hl-block]') : null;
    e.preventDefault();
    e.stopPropagation();
    send({ hl: 'select', id: block ? block.getAttribute('data-hl-block') : null });
  }, true);
  document.addEventListener('submit', function (e) { e.preventDefault(); }, true);

  var ticking = false;
  window.addEventListener('scroll', function () {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () { ticking = false; send({ hl: 'scroll', y: window.scrollY }); });
  }, { passive: true });

  window.addEventListener('message', function (e) {
    if (e.source !== parentWindow || !e.data) return;
    var data = e.data;
    if (data.hl === 'restore') window.scrollTo(0, data.y || 0);
    if (data.hl === 'highlight') {
      Array.prototype.forEach.call(document.querySelectorAll('.hl-sel'), function (n) { n.classList.remove('hl-sel'); });
      if (!data.id) return;
      var wrap = document.querySelector('[data-hl-block="' + (window.CSS && CSS.escape ? CSS.escape(data.id) : data.id) + '"]');
      var target = wrap && wrap.firstElementChild;
      if (!target) return;
      target.classList.add('hl-sel');
      if (data.scroll) target.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
  });

  send({ hl: 'ready' });
})();
