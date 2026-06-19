</main><!-- .page (header.php 에서 열림) -->

<footer class="sitefooter">
  <p>© <?= date('Y') ?> MyBlog · 학교 실습 프로젝트</p>
</footer>

<div class="ui-toast" data-toast hidden></div>
<div class="ui-modal" data-confirm-modal hidden>
  <div class="ui-modal__box" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <h2 id="confirmTitle">확인할게요</h2>
    <p data-confirm-message></p>
    <div class="ui-modal__actions">
      <button type="button" class="btn-ghost-dark" data-confirm-cancel>취소</button>
      <button type="button" class="btn-primary" data-confirm-ok>확인</button>
    </div>
  </div>
</div>

<script>
(function () {
  var toast = document.querySelector('[data-toast]');
  var flash = document.body.getAttribute('data-flash-toast');

  window.showToast = function (message, isError) {
    if (!toast || !message) return;
    toast.textContent = message;
    toast.classList.toggle('is-error', Boolean(isError));
    toast.hidden = false;
    clearTimeout(toast._timer);
    toast._timer = setTimeout(function () { toast.hidden = true; }, 2300);
  };
  if (flash) window.showToast(flash, false);

  var modal = document.querySelector('[data-confirm-modal]');
  var modalMessage = document.querySelector('[data-confirm-message]');
  var ok = document.querySelector('[data-confirm-ok]');
  var cancel = document.querySelector('[data-confirm-cancel]');
  var resolver = null;

  window.confirmAction = function (message) {
    if (!modal || !modalMessage) return Promise.resolve(window.confirm(message));
    modalMessage.textContent = message || '계속 진행할까요?';
    modal.hidden = false;
    return new Promise(function (resolve) { resolver = resolve; });
  };

  function closeConfirm(result) {
    if (!modal) return;
    modal.hidden = true;
    if (resolver) resolver(result);
    resolver = null;
  }

  if (ok) ok.addEventListener('click', function () { closeConfirm(true); });
  if (cancel) cancel.addEventListener('click', function () { closeConfirm(false); });
  if (modal) modal.addEventListener('click', function (e) {
    if (e.target === modal) closeConfirm(false);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal && !modal.hidden) closeConfirm(false);
  });

  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-confirm]');
    if (!form || form.dataset.confirmReady === '1' || form.dataset.ajaxAction) return;
    e.preventDefault();
    window.confirmAction(form.getAttribute('data-confirm')).then(function (ok) {
      if (!ok) return;
      form.dataset.confirmReady = '1';
      form.submit();
    });
  });
})();
</script>
</body>
</html>
