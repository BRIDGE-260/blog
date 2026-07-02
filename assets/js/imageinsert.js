/**
 * Inline media editor helper for write.php and modify.php.
 * Images serialize as [[img:id|width]], videos as [[video:id|width]].
 */
(function () {
  var editor = document.getElementById('editor');
  var fileInput = document.querySelector('input[name="images[]"]');
  var tray = document.getElementById('imgTray');
  var hidden = document.getElementById('contentField');
  var form = editor && editor.closest('form');
  if (!editor || !tray || !hidden || !form) return;

  function mediaTypeFromFile(file) {
    if (file.type && file.type.indexOf('video/') === 0) return 'video';
    if (file.type && file.type.indexOf('image/') === 0) return 'image';
    var name = (file.name || '').toLowerCase();
    if (/\.(mp4|webm|mov|m4v)$/.test(name)) return 'video';
    return 'image';
  }

  function defaultWidth(type) {
    return type === 'video' ? 70 : 30;
  }

  function tokenType(token) {
    return token.indexOf('video:') === 0 ? 'video' : 'image';
  }

  function makeMedia(url, token, width, type) {
    type = type || tokenType(token);
    var node = document.createElement(type === 'video' ? 'video' : 'img');
    node.src = url;
    node.className = 'editor-img editor-media' + (type === 'video' ? ' editor-video' : '');
    node.setAttribute('data-token', token);
    node.setAttribute('data-default-width', String(defaultWidth(type)));
    node.draggable = true;
    node.contentEditable = 'false';
    node.style.width = (width || defaultWidth(type)) + '%';
    if (type === 'video') {
      node.controls = true;
      node.muted = true;
      node.preload = 'metadata';
    }
    return node;
  }

  function br() {
    return document.createElement('br');
  }

  function isMedia(n) {
    return n && n.nodeType === 1 && n.classList.contains('editor-img');
  }

  function isResizeUi(n) {
    return n && n.nodeType === 1 && n.classList
      && (n.classList.contains('img-resize-box') || n.classList.contains('img-resize-handle'));
  }

  function nextMeaningful(n) {
    while (isResizeUi(n)) n = n.nextSibling;
    while (n && n.nodeType === 3 && n.nodeValue.trim() === '') n = n.nextSibling;
    while (isResizeUi(n)) n = n.nextSibling;
    return n;
  }

  function prevMeaningful(n) {
    while (isResizeUi(n)) n = n.previousSibling;
    while (n && n.nodeType === 3 && n.nodeValue.trim() === '') n = n.previousSibling;
    while (isResizeUi(n)) n = n.previousSibling;
    return n;
  }

  function addLineBreaksAround(node) {
    var prev = prevMeaningful(node.previousSibling);
    var next = nextMeaningful(node.nextSibling);
    if (prev && !isMedia(prev) && prev.tagName !== 'BR') node.parentNode.insertBefore(br(), node);
    if (next && !isMedia(next) && next.tagName !== 'BR') node.parentNode.insertBefore(br(), node.nextSibling);
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
    addLineBreaksAround(node);
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
      if (p) {
        range = document.createRange();
        range.setStart(p.offsetNode, p.offset);
      }
    }
    if (range) {
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
    }
  }

  function insertNearPoint(node, e) {
    var targetMedia = e.target && e.target.classList && e.target.classList.contains('editor-img') ? e.target : null;
    if (targetMedia === node) {
      selectMedia(node);
      return;
    }
    if (targetMedia) {
      var r = targetMedia.getBoundingClientRect();
      if (e.clientX < r.left + r.width / 2) {
        targetMedia.parentNode.insertBefore(node, targetMedia);
      } else {
        targetMedia.parentNode.insertBefore(node, targetMedia.nextSibling);
      }
      selectMedia(node);
      return;
    }
    placeCaretAtPoint(e.clientX, e.clientY);
    insertNodeAtCaret(node);
    selectMedia(node);
  }

  var dragItem = null;

  function makeTrayItem(url, token, label, type) {
    var item = document.createElement('div');
    item.className = 'imgtray__item';
    item.draggable = true;
    item.title = '본문으로 드래그하거나 클릭하세요';

    var preview = document.createElement(type === 'video' ? 'video' : 'img');
    preview.src = url;
    if (type === 'video') {
      preview.muted = true;
      preview.preload = 'metadata';
    }

    var sp = document.createElement('span');
    sp.textContent = label;
    item.appendChild(preview);
    item.appendChild(sp);

    item.addEventListener('dragstart', function (e) {
      dragItem = { type: 'tray', url: url, token: token, mediaType: type, el: item };
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', token);
    });
    item.addEventListener('click', function () {
      insertNodeAtCaret(makeMedia(url, token, null, type));
      item.remove();
    });
    return item;
  }

  function buildTray() {
    tray.innerHTML = '';
    if (fileInput && fileInput.files) {
      Array.prototype.forEach.call(fileInput.files, function (file, idx) {
        var type = mediaTypeFromFile(file);
        var token = (type === 'video' ? 'video:new' : 'img:new') + idx;
        tray.appendChild(makeTrayItem(URL.createObjectURL(file), token, String(idx + 1), type));
      });
    }
  }
  if (fileInput) fileInput.addEventListener('change', buildTray);

  editor.addEventListener('dragover', function (e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
  });
  editor.addEventListener('drop', function (e) {
    e.preventDefault();
    if (!dragItem) return;
    var node = dragItem.type === 'media' ? dragItem.el : makeMedia(dragItem.url, dragItem.token, null, dragItem.mediaType);
    insertNearPoint(node, e);
    if (dragItem.type === 'tray' && dragItem.el) dragItem.el.remove();
    dragItem = null;
  });

  document.addEventListener('dragover', function (e) {
    if (!dragItem) return;
    var margin = 90;
    if (e.clientY < margin) window.scrollBy(0, -22);
    else if (e.clientY > window.innerHeight - margin) window.scrollBy(0, 22);
  });

  var selected = null;
  var resizeBox = document.createElement('span');
  resizeBox.className = 'img-resize-box';
  ['nw', 'ne', 'sw', 'se'].forEach(function (pos) {
    var handle = document.createElement('span');
    handle.className = 'img-resize-handle ' + pos;
    handle.setAttribute('data-pos', pos);
    handle.title = '드래그해서 크기 조절';
    handle.contentEditable = 'false';
    resizeBox.appendChild(handle);
  });

  function positionResizeBox() {
    if (!selected || !resizeBox.parentNode) return;
    var mediaRect = selected.getBoundingClientRect();
    var editorRect = editor.getBoundingClientRect();
    resizeBox.style.left = (mediaRect.left - editorRect.left + editor.scrollLeft) + 'px';
    resizeBox.style.top = (mediaRect.top - editorRect.top + editor.scrollTop) + 'px';
    resizeBox.style.width = mediaRect.width + 'px';
    resizeBox.style.height = mediaRect.height + 'px';
  }

  function selectMedia(node) {
    selected = node;
    editor.querySelectorAll('.editor-img').forEach(function (item) {
      item.classList.remove('sel');
    });
    resizeBox.remove();
    editor.classList.toggle('has-img-selection', !!node);
    if (node) {
      node.classList.add('sel');
      editor.appendChild(resizeBox);
      positionResizeBox();
    }
  }

  editor.addEventListener('dragstart', function (e) {
    if (e.target.classList && e.target.classList.contains('editor-img')) {
      selectMedia(e.target);
      dragItem = { type: 'media', el: e.target };
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', e.target.getAttribute('data-token') || 'media');
    }
  });
  editor.addEventListener('dragend', function () {
    dragItem = null;
  });
  editor.addEventListener('click', function (e) {
    if (e.target.classList && e.target.classList.contains('editor-img')) selectMedia(e.target);
    else selectMedia(null);
  });
  document.addEventListener('keydown', function (e) {
    if (!selected) return;
    if (e.key === 'Delete' || e.key === 'Backspace') {
      var active = document.activeElement;
      if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA')) return;
      e.preventDefault();
      selected.remove();
      selectMedia(null);
    }
  });

  resizeBox.addEventListener('mousedown', function (e) {
    if (!selected) return;
    var handle = e.target.closest('.img-resize-handle');
    if (!handle) return;
    e.preventDefault();
    e.stopPropagation();
    selected.draggable = false;
    var editorWidth = editor.clientWidth || 1;
    var startX = e.clientX;
    var startWidth = selected.getBoundingClientRect().width;
    var pos = handle.getAttribute('data-pos') || 'se';

    function onMove(ev) {
      var direction = pos.indexOf('w') >= 0 ? -1 : 1;
      var px = Math.max(80, Math.min(editorWidth, startWidth + (ev.clientX - startX) * direction));
      var percent = Math.max(15, Math.min(100, Math.round(px / editorWidth * 100)));
      selected.style.width = percent + '%';
      positionResizeBox();
    }

    function onUp() {
      selected.draggable = true;
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
    }

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  });
  editor.addEventListener('scroll', positionResizeBox);
  window.addEventListener('resize', positionResizeBox);

  function serialize(node) {
    var out = '';
    node.childNodes.forEach(function (n) {
      if (n.nodeType === 3) {
        out += n.nodeValue;
      } else if (n.nodeType === 1) {
        var tag = n.tagName.toLowerCase();
        if (isResizeUi(n)) return;
        if ((tag === 'img' || tag === 'video') && n.getAttribute('data-token')) {
          var token = n.getAttribute('data-token');
          var fallback = parseInt(n.getAttribute('data-default-width') || (tag === 'video' ? '70' : '30'), 10);
          var width = parseInt(n.style.width, 10);
          var suffix = (width && width !== fallback) ? '|' + width : '';
          var prev = prevMeaningful(n.previousSibling);
          var next = nextMeaningful(n.nextSibling);
          out += (isMedia(prev) ? '' : '\n') + '[[' + token + suffix + ']]' + (isMedia(next) ? '' : '\n');
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
    var value = serialize(editor).replace(/\n{3,}/g, '\n\n').replace(/^\s+|\s+$/g, '');
    hidden.value = value;
    if (value === '') {
      e.preventDefault();
      if (window.showToast) window.showToast('내용을 입력해주세요.', true);
      editor.focus();
    }
  });

  buildTray();
})();
