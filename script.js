// Nav mobile toggle
document.querySelectorAll('.nav-toggle').forEach(btn => {
  btn.addEventListener('click', () => {
    const links = btn.closest('.nav').querySelector('.nav-links');
    links.classList.toggle('open');
    btn.textContent = links.classList.contains('open') ? '✕' : '☰';
  });
});

// Lead forms
document.querySelectorAll('.lead-form').forEach(form => {
  form.addEventListener('submit', e => {
    e.preventDefault();
    const phone = form.querySelector('input[type="tel"]').value.trim();
    if (!phone) return;
    const successEl = form.closest('.form-wrap')?.querySelector('.form-success')
      || form.parentElement.querySelector('.form-success');
    if (successEl) {
      form.style.display = 'none';
      successEl.style.display = 'block';
    }
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
      const offset = 80;
      const top = target.getBoundingClientRect().top + window.scrollY - offset;
      window.scrollTo({ top, behavior: 'smooth' });
    }
  });
});
