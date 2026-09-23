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
