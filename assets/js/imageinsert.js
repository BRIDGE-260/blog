/**
 * imageinsert.js — 본문 위지윅(WYSIWYG) 이미지 삽입 (write.php / modify.php 공용).
 *
 *   1) 파일 선택 → 아래 미리보기 트레이에 썸네일이 뜸.
 *   2) 미리보기를 본문(#editor, contenteditable)으로 드래그(또는 클릭)하면
 *      그 자리에 실제 사진이 들어가고, 미리보기에선 사라짐(잘라내기).
 *   3) 드래그하지 않은 사진은 글에 안 들어감.
 *   4) 폼 제출 시 본문을 직렬화: 텍스트는 그대로, 이미지는 [[img:newK]] 토큰으로 hidden(content)에 담음.
 *      (K = 파일 선택 순서. 서버가 실제 업로드 후 [[img:실제id]] 로 치환)
 *   - 기존 이미지(수정 화면): 트레이 data-existing=[{id,url}] → 드롭 시 [[img:실제id]] 토큰 이미지로 삽입.
 */
(function () {
  var editor    = document.getElementById('editor');
  var fileInput = document.querySelector('input[name="images[]"]');
  var tray      = document.getElementById('imgTray');
  var hidden    = document.getElementById('contentField');
  var form      = editor && editor.closest('form');
  if (!editor || !tray || !hidden || !form) return;

  // ── 본문에 들어갈 이미지 노드 만들기 ──
  // token: 새 파일이면 "new{idx}", 기존 이미지면 실제 id(숫자)
  function makeImg(url, token, width) {
    var im = document.createElement('img');
    im.src = url;
    im.className = 'editor-img';
    im.setAttribute('data-token', token);
    im.contentEditable = 'false';
    im.style.width = (width || 30) + '%';   // 기본 30% — 클릭해서 조절 가능
    return im;
  }

  function insertNodeAtCaret(node) {
    editor.focus();
    var sel = window.getSelection();
    var range;
    if (sel && sel.rangeCount && editor.contains(sel.anchorNode)) {
      range = sel.getRangeAt(0);
    } else {
      range = document.createRange();
      range.selectNodeContents(editor);
      range.collapse(false);
    }
    range.deleteContents();
    range.insertNode(node);
    // 이미지 뒤에 캐럿 이동
    range.setStartAfter(node);
    range.collapse(true);
    sel.removeAllRanges();
    sel.addRange(range);
  }

  function placeCaretAtPoint(x, y) {
    var range = null;
    if (document.caretRangeFromPoint) {
      range = document.caretRangeFromPoint(x, y);
    } else if (document.caretPositionFromPoint) {
      var p = document.caretPositionFromPoint(x, y);
      if (p) { range = document.createRange(); range.setStart(p.offsetNode, p.offset); }
    }
    if (range) {
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
    }
  }

  // 현재 드래그 중인 미리보기 (커스텀 dataTransfer MIME 호환성 회피)
  var dragItem = null;

  // ── 미리보기 트레이 만들기 ──
  function makeTrayItem(url, token, label) {
    var item = document.createElement('div');
    item.className = 'imgtray__item';
    item.draggable = true;
    item.title = '본문으로 드래그하거나 클릭하세요';
    var img = document.createElement('img'); img.src = url;
    var sp = document.createElement('span'); sp.textContent = label;
    item.appendChild(img); item.appendChild(sp);

    item.addEventListener('dragstart', function (e) {
      dragItem = { url: url, token: token, el: item };
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', token);   // 일부 브라우저는 데이터가 있어야 드래그 시작
    });
    item.addEventListener('click', function () {
      insertNodeAtCaret(makeImg(url, token));
      item.remove();   // 잘라내기
    });
    return item;
  }

  // 기존 이미지(수정 화면)
  var existing = [];
  try { existing = JSON.parse(tray.getAttribute('data-existing') || '[]'); } catch (e) {}

  function buildTray() {
    tray.innerHTML = '';
    existing.forEach(function (im) {
      tray.appendChild(makeTrayItem(im.url, String(im.id), '기존'));
    });
    if (fileInput && fileInput.files) {
      Array.prototype.forEach.call(fileInput.files, function (file, idx) {
        tray.appendChild(makeTrayItem(URL.createObjectURL(file), 'new' + idx, String(idx + 1)));
      });
    }
  }
  if (fileInput) fileInput.addEventListener('change', buildTray);

  // ── 본문 드롭 ──
  editor.addEventListener('dragover', function (e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; });
  editor.addEventListener('drop', function (e) {
    e.preventDefault();
    if (!dragItem) return;
    placeCaretAtPoint(e.clientX, e.clientY);
    insertNodeAtCaret(makeImg(dragItem.url, dragItem.token));
    if (dragItem.el) dragItem.el.remove();   // 잘라내기
    dragItem = null;
  });

  // ── 드래그 중 화면 가장자리에서 자동 스크롤(휠이 안 먹는 문제 대응) ──
  document.addEventListener('dragover', function (e) {
    if (!dragItem) return;
    var m = 90;
    if (e.clientY < m) window.scrollBy(0, -22);
    else if (e.clientY > window.innerHeight - m) window.scrollBy(0, 22);
  });

  // ── 본문 이미지 클릭 → 크기 조절 / 삭제 ──
  var resizer = document.getElementById('imgResize');
  var slider  = resizer ? resizer.querySelector('input[type="range"]') : null;
  var delBtn  = resizer ? resizer.querySelector('button') : null;
  var selImg  = null;

  function selectImg(img) {
    selImg = img;
    editor.querySelectorAll('.editor-img').forEach(function (i) { i.classList.remove('sel'); });
    if (img) {
      img.classList.add('sel');
      if (slider) slider.value = parseInt(img.style.width, 10) || 30;
      if (resizer) resizer.classList.add('on');
    } else if (resizer) {
      resizer.classList.remove('on');
    }
  }
  editor.addEventListener('click', function (e) {
    if (e.target.classList && e.target.classList.contains('editor-img')) selectImg(e.target);
    else selectImg(null);
  });
  if (slider) slider.addEventListener('input', function () {
    if (selImg) selImg.style.width = slider.value + '%';
  });
  if (delBtn) delBtn.addEventListener('click', function () {
    if (selImg) { selImg.remove(); selectImg(null); }
  });

  // ── 직렬화: 본문 → 텍스트 + [[img:token]] ──
  function serialize(node) {
    var out = '';
    node.childNodes.forEach(function (n) {
      if (n.nodeType === 3) {                     // 텍스트
        out += n.nodeValue;
      } else if (n.nodeType === 1) {              // 요소
        var tag = n.tagName.toLowerCase();
        if (tag === 'img' && n.getAttribute('data-token')) {
          var w = parseInt(n.style.width, 10);
          var suffix = (w && w !== 30) ? '|' + w : '';   // 기본 30% 는 생략
          out += '\n[[img:' + n.getAttribute('data-token') + suffix + ']]\n';
        } else if (tag === 'br') {
          out += '\n';
        } else if (tag === 'div' || tag === 'p') {
          out += '\n' + serialize(n);
        } else {
          out += serialize(n);
        }
      }
    });
    return out;
  }

  form.addEventListener('submit', function (e) {
    var s = serialize(editor).replace(/\n{3,}/g, '\n\n').replace(/^\s+|\s+$/g, '');
    hidden.value = s;
    if (s === '') {
      e.preventDefault();
      alert('내용을 입력해주세요.');
      editor.focus();
    }
  });

  buildTray();
})();
