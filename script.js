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

// The original site forms stay visible while the server-side receiver is being
// prepared. Enabling delivery later requires only changing this flag after
// /api/leads.php is deployed and verified against the MySQL database.
const LEAD_FORM_CONFIG = {
  enabled: false,
  endpoint: '/api/leads.php',
  successGoals: {
    lead: 'lead_submit_success',
    subscribe: 'subscription_submit_success'
  }
};
const METRIKA_COUNTER_ID = 111364095;

const reachMetrikaGoal = (goal, params = {}) => {
  if (typeof window.ym === 'function') {
    window.ym(METRIKA_COUNTER_ID, 'reachGoal', goal, params);
  }
};

const normalizePhone = value => {
  const digits = value.replace(/\D/g, '');
  if (digits.length === 10) return `+7${digits}`;
  if (digits.length === 11 && digits.startsWith('8')) return `+7${digits.slice(1)}`;
  return digits ? `+${digits}` : '';
};

const createRequestId = () => {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
};

const getTrackingParams = () => {
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

const getFormStatus = (form, index) => {
  let status = form.parentElement.querySelector(`.form-status[data-form-index="${index}"]`);
  if (!status) {
    status = document.createElement('p');
    status.className = 'form-status';
    status.dataset.formIndex = index;
    status.id = `form-status-${index}`;
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    form.insertAdjacentElement('afterend', status);
  }
  return status;
};

const showFormStatus = (status, message, state = 'info') => {
  status.textContent = message;
  status.dataset.state = state;
  status.classList.add('is-visible');
};

document.querySelectorAll('.lead-form').forEach((form, index) => {
  const phoneField = form.querySelector('input[type="tel"]');
  const submitButton = form.querySelector('button[type="submit"]');
  const status = getFormStatus(form, index);

  if (phoneField) {
    phoneField.setAttribute('aria-describedby', status.id);
    phoneField.setAttribute('autocomplete', 'tel');
    phoneField.addEventListener('input', () => {
      phoneField.classList.remove('is-invalid');
      phoneField.removeAttribute('aria-invalid');
      status.classList.remove('is-visible');
    });
  }

  form.addEventListener('submit', async event => {
    event.preventDefault();

    const rawPhone = phoneField?.value.trim() || '';
    const normalizedPhone = normalizePhone(rawPhone);
    const phoneDigits = normalizedPhone.replace(/\D/g, '');

    if (phoneDigits.length < 10 || phoneDigits.length > 15) {
      phoneField?.classList.add('is-invalid');
      phoneField?.setAttribute('aria-invalid', 'true');
      showFormStatus(status, 'Проверьте номер телефона: нужно указать не менее 10 цифр.', 'error');
      phoneField?.focus();
      return;
    }

    if (!LEAD_FORM_CONFIG.enabled) {
      showFormStatus(
        status,
        'Форма готовится к подключению. Пока позвоните нам: +7 985 075-76-75.',
        'info'
      );
      return;
    }

    form.dataset.requestId ||= createRequestId();
    const consentField = form.querySelector('input[name="consent_pd"]');
    const payload = {
      request_id: form.dataset.requestId,
      form_kind: form.dataset.formKind || 'lead',
      phone: normalizedPhone,
      page_url: window.location.href,
      referrer: document.referrer,
      consent_pd: consentField ? consentField.checked : null,
      ...getTrackingParams()
    };

    form.classList.add('is-loading');
    if (submitButton) submitButton.disabled = true;
    showFormStatus(status, 'Отправляем заявку…', 'info');

    try {
      const response = await fetch(LEAD_FORM_CONFIG.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || result.ok === false) {
        throw new Error(result.error || `HTTP ${response.status}`);
      }

      const successEl = form.closest('.form-wrap')?.querySelector('.form-success')
        || form.parentElement.querySelector('.form-success');
      if (successEl) {
        form.style.display = 'none';
        status.classList.remove('is-visible');
        successEl.style.display = 'block';
      }
      delete form.dataset.requestId;
      const successGoal = LEAD_FORM_CONFIG.successGoals[payload.form_kind];
      if (successGoal) {
        reachMetrikaGoal(successGoal, {
          page: window.location.pathname,
          form_kind: payload.form_kind
        });
      }
    } catch (error) {
      showFormStatus(
        status,
        'Не удалось отправить заявку. Позвоните нам: +7 985 075-76-75.',
        'error'
      );
    } finally {
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
