// CCAD — petites interactions côté client
document.addEventListener('DOMContentLoaded', () => {
  // Lignes de tableau cliquables
  document.querySelectorAll('tr[data-href]').forEach(tr => {
    tr.classList.add('clickable');
    tr.addEventListener('click', e => {
      if (e.target.closest('a, button, input, form')) return;
      window.location = tr.dataset.href;
    });
  });

  // Confirmation des actions sensibles
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => { if (!confirm(el.dataset.confirm)) e.preventDefault(); });
  });

  // Département → communes
  document.querySelectorAll('select[data-communes]').forEach(dep => {
    const target = document.getElementById(dep.dataset.communes);
    const map = JSON.parse(dep.dataset.map || '{}');
    dep.addEventListener('change', () => {
      target.innerHTML = '';
      (map[dep.value] || '').split(',').forEach(c => {
        if (!c) return;
        const o = document.createElement('option'); o.value = o.textContent = c; target.appendChild(o);
      });
    });
  });

  // Plan choisi → cotisation et couverture
  document.querySelectorAll('select[data-plan]').forEach(sel => {
    const update = () => {
      const opt = sel.selectedOptions[0];
      if (!opt) return;
      const prime = document.getElementById('prime'), cap = document.getElementById('capital');
      if (prime) prime.value = opt.dataset.prime || '';
      if (cap) cap.value = opt.dataset.capital || '';
    };
    sel.addEventListener('change', update); update();
  });

  // Aperçu de la photo
  document.querySelectorAll('input[type=file][data-preview]').forEach(inp => {
    inp.addEventListener('change', () => {
      const f = inp.files[0], box = document.getElementById(inp.dataset.preview);
      if (!f || !box) return;
      const img = document.createElement('img');
      img.className = 'photo'; img.src = URL.createObjectURL(f);
      box.replaceWith(img); img.id = inp.dataset.preview;
    });
  });

  // Lignes dynamiques (membres / bénéficiaires)
  document.querySelectorAll('[data-add-row]').forEach(btn => {
    btn.addEventListener('click', () => {
      const list = document.getElementById(btn.dataset.addRow);
      const tpl = document.getElementById(btn.dataset.template);
      const max = parseInt(list.dataset.max || '99', 10);
      if (list.children.length >= max) { alert('Nombre maximum atteint (' + max + ').'); return; }
      list.appendChild(tpl.content.cloneNode(true));
      updateParts();
    });
  });
  document.addEventListener('click', e => {
    const rm = e.target.closest('[data-remove-row]');
    if (rm) { rm.closest('.form-row').remove(); updateParts(); }
  });
  document.addEventListener('input', e => { if (e.target.matches('.part-input')) updateParts(); });
  function updateParts() {
    document.querySelectorAll('[data-parts-total]').forEach(out => {
      const list = document.getElementById(out.dataset.partsTotal);
      let t = 0; list.querySelectorAll('.part-input').forEach(i => { t += parseFloat(i.value) || 0; });
      out.textContent = t + ' %';
      out.className = 'badge ' + (Math.abs(t - 100) < 0.01 ? 'badge-success' : 'badge-warning');
    });
  }
  updateParts();

  // Ouvrir / fermer un formulaire de modification (ligne ou bloc)
  document.querySelectorAll('[data-toggle-row]').forEach(btn => {
    btn.addEventListener('click', () => {
      const el = document.getElementById(btn.dataset.toggleRow);
      if (!el) return;
      el.hidden = !el.hidden;
      if (!el.hidden) { const f = el.querySelector('input:not([type=hidden]), select'); if (f) f.focus(); }
    });
  });

  // Nom du fichier choisi
  document.querySelectorAll('[data-file-name]').forEach(inp => {
    inp.addEventListener('change', () => {
      const span = inp.parentElement.querySelector('span');
      if (span && inp.files[0]) span.textContent = inp.files[0].name.length > 28 ? inp.files[0].name.slice(0, 25) + '…' : inp.files[0].name;
      if (inp.files[0] && inp.files[0].size > 5 * 1024 * 1024) { alert('Ce fichier dépasse 5 Mo.'); inp.value = ''; if (span) span.textContent = 'Choisir un fichier'; }
    });
  });

  // Afficher / masquer un mot de passe
  document.querySelectorAll('[data-pw-toggle]').forEach(btn => {
    btn.addEventListener('click', () => {
      const inp = document.getElementById(btn.dataset.pwToggle);
      const show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Masquer' : 'Afficher';
      inp.focus();
    });
  });

  // Force du nouveau mot de passe
  document.querySelectorAll('[data-pw-strength]').forEach(inp => {
    const meter = document.getElementById(inp.dataset.pwStrength);
    const rules = document.querySelector('[data-pw-rules="' + inp.id + '"]');
    const check = () => {
      const v = inp.value;
      const r = { len: v.length >= 10, case: /[a-zà-ÿ]/.test(v) && /[A-ZÀ-Þ]/.test(v), digit: /\d/.test(v) };
      if (rules) rules.querySelectorAll('[data-rule]').forEach(li => li.classList.toggle('ok', r[li.dataset.rule]));
      let score = Object.values(r).filter(Boolean).length + (v.length >= 14 ? 1 : 0) + (/[^A-Za-z0-9]/.test(v) ? 1 : 0);
      if (!v) score = 0;
      meter.dataset.level = score <= 1 ? 'faible' : score <= 3 ? 'moyen' : 'fort';
      meter.firstElementChild.style.width = Math.min(100, score * 20) + '%';
    };
    inp.addEventListener('input', check);
    check();
  });

  // Encaissement : montant = prime × mois, mode habituel de l'assuré
  const primeSel = document.querySelector('[data-prime-select]');
  if (primeSel) {
    const form = primeSel.form;
    const mois = form.querySelector('[data-mois]');
    const montant = form.querySelector('[data-montant]');
    const objet = form.querySelector('[name=objet]');
    const devLabel = form.querySelector('[data-devise-label]');
    const modeSel = form.querySelector('[data-mode-select]');
    const calc = (changedAssure) => {
      const opt = primeSel.selectedOptions[0];
      if (!opt || !opt.value) return;
      if (devLabel) devLabel.textContent = opt.dataset.devise || '';
      if (objet.value === 'Cotisation') montant.value = (parseFloat(opt.dataset.prime) || 0) * (parseInt(mois.value, 10) || 1);
      if (changedAssure && opt.dataset.mode) {
        const m = modeSel.querySelector('option[data-code="' + opt.dataset.mode + '"]');
        if (m) modeSel.value = m.value;
      }
    };
    primeSel.addEventListener('change', () => calc(true));
    mois.addEventListener('input', () => calc(false));
    objet.addEventListener('change', () => { mois.disabled = objet.value !== 'Cotisation'; if (objet.value !== 'Cotisation') montant.value = ''; calc(false); });
    calc(true);
  }
});
