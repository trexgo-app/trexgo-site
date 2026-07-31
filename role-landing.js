/* Скрипты ролевых лендингов: /perevozchiki, /gruzootpravitelyam, /gruzopoluchatelyam.
   Общие для трёх страниц — потому и вынесены из <script> в файл.
   Каждый блок сначала проверяет, есть ли его элементы на странице:
   лендинги отличаются составом, и лишнего обработчика быть не должно. */

// Счётчик в шапке: дней до 01.09.2026.
// Текст держим коротким: шапка не переносится, каждый лишний символ
// отжимает якоря (см. role-landing.css, блок .pv-anchors).
(function () {
  var badge = document.getElementById('deadlineBadge');
  if (!badge) return;
  var deadline = new Date(2026, 8, 1); // 1 сентября 2026
  var now = new Date();
  var days = Math.ceil((deadline - now) / 86400000);
  if (days <= 0) {
    badge.textContent = 'ЭТрН уже обязательна';
    return;
  }
  var t = days % 100, o = days % 10, word;
  if (t > 10 && t < 20) word = 'дней';
  else if (o === 1) word = 'день';
  else if (o >= 2 && o <= 4) word = 'дня';
  else word = 'дней';
  badge.textContent = 'До ЭТрН: ' + days + ' ' + word;
})();

// Шапка прячется при скролле вниз и возвращается при скролле вверх
(function () {
  var nav = document.getElementById('pvNav');
  if (!nav) return;
  var lastY = 0;
  window.addEventListener('scroll', function () {
    var y = window.scrollY;
    if (y > 120 && y > lastY) nav.classList.add('hidden');
    else nav.classList.remove('hidden');
    lastY = y;
  }, { passive: true });
})();

// Мобильная плашка с кнопкой: появляется, когда кнопка из первого экрана ушла
(function () {
  var bar = document.getElementById('stickyBar');
  var heroCta = document.querySelector('.pv-hero .btn-orange');
  if (!bar || !heroCta || !('IntersectionObserver' in window)) return;
  var leadSection = document.getElementById('lead');
  if (!leadSection) return;
  var leadVisible = false;
  new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (e.target === heroCta) {
        bar.classList.toggle('visible', !e.isIntersecting && !leadVisible);
      } else if (e.target === leadSection) {
        leadVisible = e.isIntersecting;
        if (leadVisible) bar.classList.remove('visible');
      }
    });
  }).observe(heroCta);
  new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      leadVisible = e.isIntersecting;
      if (leadVisible) bar.classList.remove('visible');
      else if (heroCta.getBoundingClientRect().bottom < 0) bar.classList.add('visible');
    });
  }).observe(leadSection);
})();

// Появление секций при скролле.
// Класс .pv-reveal стоит в разметке, но прячет блок только вместе с .js-reveal
// на <html>: без JS и без IntersectionObserver секции видны сразу.
(function () {
  var items = document.querySelectorAll('.pv-reveal');
  if (!items.length || !('IntersectionObserver' in window)) return;
  document.documentElement.classList.add('js-reveal');
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      e.target.classList.add('shown');
      io.unobserve(e.target);
    });
  }, { rootMargin: '0px 0px -12% 0px' });
  items.forEach(function (el) { io.observe(el); });
})();
