/* Скрипты ролевых лендингов: /perevozchiki, /gruzootpravitelyam, /gruzopoluchatelyam.
   Общие для трёх страниц — потому и вынесены из <script> в файл.
   Каждый блок сначала проверяет, есть ли его элементы на странице:
   лендинги отличаются составом, и лишнего обработчика быть не должно. */

// Счётчик в шапке: дней до 01.09.2026.
// Текст держим коротким: он стоит в общей шапке рядом с телефоном и кнопкой,
// а она не переносится — лишние символы отжимают пункты меню.
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

// Плавающее меню по странице: подсветка текущего раздела и маркер под ним.
//
// Активным считается последний раздел, чей верх уже прошёл линию в трети
// экрана сверху. Считаем по scroll, а не по IntersectionObserver: у нас
// есть короткие разделы, которые целиком помещаются в экран вместе с
// соседями, и наблюдатель на них мигает между двумя пунктами.
(function () {
  var bar = document.getElementById('pageNav');
  if (!bar) return;
  var track = bar.querySelector('.pv-pagenav-inner');
  var marker = bar.querySelector('.pv-pagenav-marker');
  var links = [].slice.call(bar.querySelectorAll('a[href^="#"]:not(.pv-pagenav-cta)'));
  var sections = links.map(function (a) {
    return document.getElementById(a.getAttribute('href').slice(1));
  });
  if (!links.length) return;

  var lead = document.getElementById('lead');
  var current = -1;

  function moveMarker(link) {
    if (!marker) return;
    marker.style.width = link.offsetWidth + 'px';
    marker.style.transform = 'translateX(' + link.offsetLeft + 'px)';
    marker.classList.add('ready');
  }

  // При горизонтальной прокрутке активный пункт может оказаться за краем —
  // подтягиваем его в видимую часть, но только когда меню на экране.
  function revealLink(link) {
    if (!track || !bar.classList.contains('visible')) return;
    var left = link.offsetLeft - (track.clientWidth - link.offsetWidth) / 2;
    track.scrollTo({ left: Math.max(0, left), behavior: 'smooth' });
  }

  function setActive(i) {
    if (i === current) return;
    current = i;
    links.forEach(function (a, n) {
      var on = n === i;
      a.classList.toggle('active', on);
      if (on) a.setAttribute('aria-current', 'true');
      else a.removeAttribute('aria-current');
    });
    if (i < 0) { if (marker) marker.classList.remove('ready'); return; }
    moveMarker(links[i]);
    revealLink(links[i]);
  }

  function update() {
    var line = window.innerHeight / 3;
    var found = -1;
    for (var i = 0; i < sections.length; i++) {
      if (sections[i] && sections[i].getBoundingClientRect().top <= line) found = i;
    }

    // Меню живёт ровно столько, сколько мы находимся в разделах: появляется,
    // когда до первого из них доскроллили, и уходит на форме заявки, где
    // навигация вверх уже мешает кнопке отправки.
    // Привязка именно к разделам, а не к высоте героя: иначе клик по первому
    // пункту доводил страницу до границы, на которой меню само себя пряталось,
    // и нажать второй пункт было уже нечем.
    var atLead = lead ? lead.getBoundingClientRect().top < window.innerHeight * 0.8 : false;
    bar.classList.toggle('visible', found >= 0 && !atLead);

    setActive(found);
  }

  var ticking = false;
  window.addEventListener('scroll', function () {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () { update(); ticking = false; });
  }, { passive: true });

  // Ширина подписей зависит от шрифта и от вёрстки — пересчитываем маркер,
  // когда меняется размер окна или когда наконец подгрузился шрифт.
  window.addEventListener('resize', function () {
    if (current >= 0) moveMarker(links[current]);
  });
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(function () { if (current >= 0) moveMarker(links[current]); });
  }
  update();
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
