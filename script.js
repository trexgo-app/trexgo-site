// Nav mobile toggle
document.querySelectorAll('.nav-toggle').forEach(btn => {
  btn.addEventListener('click', () => {
    const links = btn.closest('.nav').querySelector('.nav-links');
    links.classList.toggle('open');
    btn.textContent = links.classList.contains('open') ? '✕' : '☰';
  });
});

// Выпадающий список в меню («Кому подходит»)
document.querySelectorAll('.nav-sub-toggle').forEach(btn => {
  btn.addEventListener('click', e => {
    e.stopPropagation();
    const open = btn.getAttribute('aria-expanded') === 'true';
    document.querySelectorAll('.nav-sub-toggle').forEach(b => b.setAttribute('aria-expanded', 'false'));
    btn.setAttribute('aria-expanded', String(!open));
  });
});

const closeNavSubs = () => {
  document.querySelectorAll('.nav-sub-toggle').forEach(b => b.setAttribute('aria-expanded', 'false'));
};
document.addEventListener('click', closeNavSubs);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNavSubs(); });

// Lead forms are collected by Yandex Forms. Answers are stored in the form's
// response table and the connected Metrika counter receives ya-forms_* events.
const YANDEX_LEAD_FORM_URL = 'https://forms.yandex.ru/u/6a74dbdc6d2d732057ffb6d9/?iframe=1';
const METRIKA_COUNTER_ID = 111364095;

const reachMetrikaGoal = (goal, params = {}) => {
  if (typeof window.ym === 'function') {
    window.ym(METRIKA_COUNTER_ID, 'reachGoal', goal, params);
  }
};

document.querySelectorAll('.lead-form').forEach((form, index) => {
  const frame = document.createElement('iframe');
  frame.src = YANDEX_LEAD_FORM_URL;
  frame.name = `ya-form-trexgo-${index}`;
  frame.title = 'Форма заявки TrexGo';
  frame.className = `yandex-lead-form${form.classList.contains('inline-subscribe-form') ? ' inline-subscribe-form' : ''}`;
  frame.loading = 'lazy';
  frame.setAttribute('frameborder', '0');

  // The iframe navigates once more only after Yandex Forms accepts the answer
  // and opens its success page. This keeps the conversion on trexgo.ru.
  let initialLoadComplete = false;
  let conversionSent = false;
  frame.addEventListener('load', () => {
    if (initialLoadComplete && !conversionSent) {
      conversionSent = true;
      reachMetrikaGoal('lead_submit_success', {
        page: window.location.pathname,
        form_index: index
      });
    }
    initialLoadComplete = true;
  });

  const row = form.querySelector('.form-row');
  if (row) {
    row.replaceWith(frame);
  } else {
    form.replaceWith(frame);
  }
});

// Phone clicks are a separate high-intent conversion in Yandex Metrika.
document.querySelectorAll('a[href^="tel:"]').forEach(link => {
  link.addEventListener('click', () => {
    reachMetrikaGoal('phone_click', {
      page: window.location.pathname,
      placement: link.className || 'phone-link'
    });
  });
});

// FAQ accordion
document.querySelectorAll('details.faq-item').forEach(item => {
  item.addEventListener('toggle', () => {
    if (item.open) {
      document.querySelectorAll('details.faq-item').forEach(other => {
        if (other !== item) other.removeAttribute('open');
      });
    }
  });
});

// Smooth anchor scroll with offset
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const target = document.querySelector(a.getAttribute('href'));
    if (target) {
      e.preventDefault();
      // Отступ под шапку. На ролевых лендингах под ней висит ещё и плавающее
      // меню по странице — там заголовок раздела иначе уезжает под таблетку.
      // 160 вместо 140: у последних разделов страница упирается в конец и
      // доезжает не до конца, съедая запас.
      const offset = document.getElementById('pageNav') ? 160 : 80;
      const top = target.getBoundingClientRect().top + window.scrollY - offset;
      window.scrollTo({ top, behavior: 'smooth' });
    }
  });
});
