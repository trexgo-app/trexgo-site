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

// Lead and subscription forms + их цели в Метрике.
const LEADS_ENDPOINT = 'api/leads';
const METRIKA_COUNTER_ID = 112278172;
const LEAD_SUCCESS_GOALS = {
  lead: 'lead_submit_success',
  subscription: 'subscription_submit_success'
};

const reachMetrikaGoal = (goal, params = {}) => {
  if (typeof window.ym === 'function') {
    window.ym(METRIKA_COUNTER_ID, 'reachGoal', goal, params);
  }
};


// Маска российского номера: +7 (999) 123-45-67. Ввод только цифр,
// префикс +7 не стирается — пользователь набирает 10 цифр после него.
const RU_PHONE_DIGITS = 10;

const formatRuPhone = digits => {
  const d = digits.slice(0, RU_PHONE_DIGITS);
  let out = '+7';
  if (d.length) out += ' (' + d.slice(0, 3);
  if (d.length >= 3) out += ')';
  if (d.length > 3) out += ' ' + d.slice(3, 6);
  if (d.length > 6) out += '-' + d.slice(6, 8);
  if (d.length > 8) out += '-' + d.slice(8, 10);
  return out;
};

// Из произвольного ввода достаём именно «хвост» из 10 цифр:
// ведущие 7 или 8 — это код страны, а не часть номера.
const extractRuDigits = value => {
  let d = value.replace(/\D/g, '');
  if (d.startsWith('7') || d.startsWith('8')) d = d.slice(1);
  return d.slice(0, RU_PHONE_DIGITS);
};

const attachRuPhoneMask = field => {
  if (!field || field.dataset.maskAttached) return;
  field.dataset.maskAttached = '1';
  field.setAttribute('inputmode', 'tel');
  field.setAttribute('maxlength', '18');

  const apply = () => {
    const digits = extractRuDigits(field.value);
    field.value = digits ? formatRuPhone(digits) : '';
  };

  field.addEventListener('focus', () => {
    if (!field.value) field.value = '+7 ';
  });
  field.addEventListener('input', apply);
  field.addEventListener('blur', () => {
    if (field.value.replace(/\D/g, '').length <= 1) field.value = '';
  });
  field.addEventListener('paste', () => setTimeout(apply, 0));
};

const normalizeLeadPhone = value => {
  let digits = value.replace(/\D/g, '');
  if (digits.length === 10) digits = `7${digits}`;
  if (digits.length === 11 && digits.startsWith('8')) digits = `7${digits.slice(1)}`;
  return digits ? `+${digits}` : '';
};

const createLeadRequestId = () => {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  const bytes = new Uint8Array(16);
  if (window.crypto?.getRandomValues) {
    window.crypto.getRandomValues(bytes);
  } else {
    for (let i = 0; i < bytes.length; i += 1) bytes[i] = Math.floor(Math.random() * 256);
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

const leadTrackingParams = () => {
  const params = new URLSearchParams(window.location.search);
  return {
    utm_source: params.get('utm_source') || '',
    utm_medium: params.get('utm_medium') || '',
    utm_campaign: params.get('utm_campaign') || '',
    utm_content: params.get('utm_content') || '',
    utm_term: params.get('utm_term') || '',
    yclid: params.get('yclid') || ''
  };
};

const leadFormStatus = (form, index) => {
  let status = form.parentElement.querySelector(`.form-status[data-form-index="${index}"]`);
  if (!status) {
    status = document.createElement('p');
    status.className = 'form-status';
    status.dataset.formIndex = index;
    status.id = `form-status-${index}`;
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    if (form.classList.contains('inline-subscribe-form')) {
      form.appendChild(status);
    } else {
      form.insertAdjacentElement('afterend', status);
    }
  }
  return status;
};

const showLeadFormStatus = (status, message, state = 'info') => {
  status.textContent = message;
  status.dataset.state = state;
  status.classList.add('is-visible');
};

document.querySelectorAll('.lead-form').forEach((form, index) => {
  const phoneField = form.querySelector('input[type="tel"]');
  const emailField = form.querySelector('input[type="email"]');
  const submitButton = form.querySelector('button[type="submit"]');
  const status = leadFormStatus(form, index);
  const startedAt = Math.floor(Date.now() / 1000);

  const honeypot = document.createElement('input');
  honeypot.type = 'text';
  honeypot.name = 'website';
  honeypot.tabIndex = -1;
  honeypot.autocomplete = 'off';
  honeypot.className = 'form-honeypot';
  honeypot.setAttribute('aria-hidden', 'true');
  form.appendChild(honeypot);

  [phoneField, emailField].filter(Boolean).forEach(field => {
    field.setAttribute('aria-describedby', status.id);
    field.addEventListener('input', () => {
      field.classList.remove('is-invalid');
      field.removeAttribute('aria-invalid');
      status.classList.remove('is-visible');
    });
  });
  phoneField?.setAttribute('autocomplete', 'tel');
  attachRuPhoneMask(phoneField);
  emailField?.setAttribute('autocomplete', 'email');

  form.addEventListener('submit', async event => {
    event.preventDefault();

    const declaredFormKind = form.dataset.formKind
      || (form.classList.contains('inline-subscribe-form') ? 'subscribe' : 'lead');
    const formKind = declaredFormKind === 'subscribe' ? 'subscription' : 'lead';
    const normalizedPhone = normalizeLeadPhone(phoneField?.value.trim() || '');
    const phoneDigits = normalizedPhone.replace(/\D/g, '');
    const email = emailField?.value.trim() || '';

    const ruDigits = extractRuDigits(phoneField?.value || '');
    if (phoneField && ruDigits.length !== RU_PHONE_DIGITS) {
      phoneField.classList.add('is-invalid');
      phoneField.setAttribute('aria-invalid', 'true');
      showLeadFormStatus(status, 'Введите номер полностью: +7 и 10 цифр.', 'error');
      phoneField.focus();
      return;
    }
    if (formKind === 'lead' && !normalizedPhone) {
      showLeadFormStatus(status, 'Укажите номер телефона.', 'error');
      phoneField?.focus();
      return;
    }
    if (formKind === 'subscription' && !normalizedPhone && !email) {
      showLeadFormStatus(status, 'Укажите телефон или электронную почту.', 'error');
      (emailField || phoneField)?.focus();
      return;
    }

    form.dataset.requestId ||= createLeadRequestId();
    const fields = Object.fromEntries(new FormData(form).entries());
    const payload = {
      ...fields,
      request_id: form.dataset.requestId,
      form_kind: formKind,
      form_started_at: startedAt,
      phone: normalizedPhone,
      email,
      source: form.dataset.source || 'website',
      page_url: window.location.href,
      referrer: document.referrer,
      ...leadTrackingParams()
    };

    form.classList.add('is-loading');
    if (submitButton) submitButton.disabled = true;
    showLeadFormStatus(status, 'Отправляем…');

    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 12000);
    try {
      const response = await fetch(LEADS_ENDPOINT, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        signal: controller.signal
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || result.ok !== true) {
        if (response.status === 422 && result.message) {
          throw new Error(result.message);
        }
        throw new Error('Не удалось отправить заявку.');
      }

      const redirectTo = form.dataset.redirect;
      if (redirectTo) {
        const successGoalEarly = LEAD_SUCCESS_GOALS[formKind];
        if (successGoalEarly) reachMetrikaGoal(successGoalEarly, { form_kind: formKind });
        window.location.assign(redirectTo);
        return;
      }

      const successEl = form.closest('.form-wrap')?.querySelector('.form-success')
        || form.parentElement.querySelector('.form-success');
      if (successEl) {
        form.style.display = 'none';
        status.classList.remove('is-visible');
        successEl.style.display = 'block';
      } else {
        showLeadFormStatus(status, 'Готово. Мы свяжемся с вами.', 'success');
      }
      delete form.dataset.requestId;
      const successGoal = LEAD_SUCCESS_GOALS[formKind];
      if (successGoal) {
        reachMetrikaGoal(successGoal, {
          page: window.location.pathname,
          form_kind: formKind
        });
      }
    } catch (error) {
      const message = error.name === 'AbortError'
        ? 'Сервер не ответил вовремя. Попробуйте ещё раз — повторная отправка не создаст дубль.'
        : (error.message || 'Не удалось отправить заявку.');
      showLeadFormStatus(
        status,
        `${message} Позвоните нам: +7 985 075-76-75.`,
        'error'
      );
    } finally {
      window.clearTimeout(timeout);
      form.classList.remove('is-loading');
      if (submitButton) submitButton.disabled = false;
    }
  });
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

// Messenger transitions are useful secondary conversions while the form is
// disabled, but they stay separate from a successfully delivered lead.
document.querySelectorAll('a[href^="https://wa.me/"], a[href^="https://max.ru/"]').forEach(link => {
  link.addEventListener('click', () => {
    const messenger = link.href.startsWith('https://wa.me/') ? 'whatsapp' : 'max';
    reachMetrikaGoal('messenger_click', {
      page: window.location.pathname,
      messenger,
      placement: link.getAttribute('aria-label') || link.className || 'messenger-link'
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


// Модальное окно заявки: открывают элементы с data-modal="lead".
const leadModal = document.getElementById('leadModal');
if (leadModal) {
  let lastFocused = null;

  const openLeadModal = () => {
    lastFocused = document.activeElement;
    leadModal.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    leadModal.querySelector('input')?.focus();
  };

  const closeLeadModal = () => {
    leadModal.classList.remove('is-open');
    document.body.style.overflow = '';
    lastFocused?.focus();
  };

  document.querySelectorAll('[data-modal="lead"]').forEach(trigger => {
    trigger.addEventListener('click', event => {
      event.preventDefault();
      openLeadModal();
    });
  });

  // Кнопка закрытия — `.modal-cancel`: крестик из модалки убран, осталась
  // только текстовая «Закрыть» внизу формы.
  leadModal.querySelector('.modal-cancel')?.addEventListener('click', closeLeadModal);
  leadModal.addEventListener('click', event => {
    if (event.target === leadModal) closeLeadModal();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && leadModal.classList.contains('is-open')) closeLeadModal();
  });
}
