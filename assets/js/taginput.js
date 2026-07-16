// taginput.js — 태그 칩 입력.
// 글자 입력 후 Enter(또는 ,) 를 누르면 회색 칩으로 들어가고, × 로 제거.
// 실제 값은 숨김 input(name=tags)에 "#a #b" 형태로 동기화 → PHP는 기존대로 파싱.
document.querySelectorAll('.taginput').forEach(function (box) {
  const field  = box.querySelector('.taginput__field');
  const hidden = box.querySelector('input[type="hidden"]');
  let tags = (hidden.value || '').replace(/#/g, '').split(/\s+/).filter(Boolean);

  function render() {
    hidden.value = tags.map(t => '#' + t).join(' ');
    box.querySelectorAll('.chip-tag').forEach(c => c.remove());
    tags.forEach(function (t, i) {
      const chip = document.createElement('span');
      chip.className = 'chip-tag';
      chip.textContent = '#' + t;
      const x = document.createElement('button');
      x.type = 'button';
      x.textContent = '×';
      x.addEventListener('click', function () { tags.splice(i, 1); render(); });
      chip.appendChild(x);
      box.insertBefore(chip, field);
    });
  }
  function norm(v) {
    return v.toLocaleLowerCase();
  }
  function add(v) {
    v = v.replace(/#/g, '').trim();
    if (v && !tags.some(t => norm(t) === norm(v))) { tags.push(v); render(); }
  }

  field.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      add(field.value);
      field.value = '';
    } else if (e.key === 'Backspace' && field.value === '' && tags.length) {
      tags.pop();
      render();
    }
  });
  field.addEventListener('blur', function () {
    if (field.value.trim()) { add(field.value); field.value = ''; }
  });
  box.addEventListener('bridge:set-tags', function (event) {
    const incoming = Array.isArray(event.detail) ? event.detail : [];
    incoming.forEach(add);
  });

  render();
});
