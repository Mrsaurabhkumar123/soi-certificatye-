(() => {
  'use strict';

  const root = document.getElementById('designer');
  if (!root) return;

  const initial = JSON.parse(root.dataset.initial);
  let layout = initial.layout;
  let variableSchema = initial.variable_schema;
  let selectedId = null;
  let drag = null;
  let dirty = false;
  let previewMode = false;
  let guides = [];
  const undoStack = [];
  const redoStack = [];
  const canvas = document.getElementById('canvas');
  const status = document.getElementById('status');
  const fields = {
    value: document.getElementById('prop-value'),
    x: document.getElementById('prop-x'),
    y: document.getElementById('prop-y'),
    w: document.getElementById('prop-w'),
    h: document.getElementById('prop-h'),
    font_size: document.getElementById('prop-font-size'),
    align: document.getElementById('prop-align')
  };

  const clone = (value) => JSON.parse(JSON.stringify(value));
  const setDirty = () => {
    dirty = true;
    status.textContent = 'Unsaved draft changes';
  };
  const checkpoint = () => {
    undoStack.push(clone(layout));
    if (undoStack.length > 40) undoStack.shift();
    redoStack.length = 0;
    updateHistoryButtons();
  };
  const updateHistoryButtons = () => {
    document.getElementById('undo').disabled = undoStack.length === 0;
    document.getElementById('redo').disabled = redoStack.length === 0;
  };
  const elementValue = (element) => {
    if (element.type === 'variable') {
      const preview = element.key === 'recipient_name'
        ? document.getElementById('sample-name').value
        : element.key === 'course_name'
          ? document.getElementById('sample-course').value
          : `{{${element.key}}}`;
      return previewMode ? preview : `{{${element.key}}}`;
    }
    if (element.type === 'qr') return 'Verification QR';
    if (element.type === 'rectangle') return '';
    return element.value || '';
  };
  const render = () => {
    const page = layout.page || {size: 'A4', orientation: 'landscape'};
    let dimensions = page.size === 'Letter' ? [792, 612] : [842, 595];
    if (page.size === 'custom') {
      const factor = page.unit === 'mm' ? 72 / 25.4 : page.unit === 'in' ? 72 : 1;
      dimensions = [Number(page.width) * factor, Number(page.height) * factor];
    }
    let [width, height] = dimensions;
    if (page.orientation === 'portrait' && width > height) [width, height] = [height, width];
    if (page.orientation === 'landscape' && height > width) [width, height] = [height, width];
    canvas.style.width = `${width}px`;
    canvas.style.height = `${height}px`;
    canvas.replaceChildren();
    (layout.elements || []).forEach((element) => {
      const node = document.createElement('div');
      node.className = `designer-element element-${element.type}${element.id === selectedId ? ' selected' : ''}`;
      node.dataset.id = element.id;
      node.style.left = `${element.x}px`;
      node.style.top = `${element.y}px`;
      node.style.width = `${element.w || element.size}px`;
      node.style.height = `${element.h || element.size}px`;
      node.style.zIndex = String(element.z_index || 0);
      node.style.fontSize = `${element.font_size || 14}px`;
      node.style.textAlign = ['left', 'center', 'right'].includes(element.align) ? element.align : 'left';
      node.textContent = elementValue(element);
      node.setAttribute('role', 'button');
      node.setAttribute('aria-label', `${element.type} element ${element.id}`);
      node.tabIndex = 0;
      const handle = document.createElement('span');
      handle.className = 'resize-handle';
      handle.setAttribute('aria-hidden', 'true');
      node.append(handle);
      canvas.append(node);
    });
    guides.forEach((guide) => {
      const line = document.createElement('div');
      line.className = `designer-guide guide-${guide.axis}`;
      line.style[guide.axis === 'x' ? 'left' : 'top'] = `${guide.position}px`;
      line.setAttribute('aria-hidden', 'true');
      canvas.append(line);
    });
    syncInspector();
  };
  const snapToPeers = (element, left, top) => {
    const width = Number(element.w || element.size || 0);
    const height = Number(element.h || element.size || 0);
    const xPoints = [left, left + width / 2, left + width];
    const yPoints = [top, top + height / 2, top + height];
    let bestX = null;
    let bestY = null;
    (layout.elements || []).filter((peer) => peer.id !== element.id).forEach((peer) => {
      const peerWidth = Number(peer.w || peer.size || 0);
      const peerHeight = Number(peer.h || peer.size || 0);
      const peerX = [peer.x, peer.x + peerWidth / 2, peer.x + peerWidth];
      const peerY = [peer.y, peer.y + peerHeight / 2, peer.y + peerHeight];
      xPoints.forEach((point, pointIndex) => peerX.forEach((target, targetIndex) => {
        const distance = Math.abs(point - target);
        if (distance <= 6 && (!bestX || distance < bestX.distance)) {
          bestX = {distance, offset: target - point, position: target, pointIndex, targetIndex};
        }
      }));
      yPoints.forEach((point, pointIndex) => peerY.forEach((target, targetIndex) => {
        const distance = Math.abs(point - target);
        if (distance <= 6 && (!bestY || distance < bestY.distance)) {
          bestY = {distance, offset: target - point, position: target, pointIndex, targetIndex};
        }
      }));
    });
    guides = [];
    if (bestX) {
      left += bestX.offset;
      guides.push({axis: 'x', position: bestX.position});
    }
    if (bestY) {
      top += bestY.offset;
      guides.push({axis: 'y', position: bestY.position});
    }
    return {left, top};
  };
  const selected = () => layout.elements.find((element) => element.id === selectedId) || null;
  const syncInspector = () => {
    const element = selected();
    document.getElementById('properties').hidden = !element;
    document.getElementById('empty-selection').hidden = Boolean(element);
    if (!element) return;
    fields.value.value = element.type === 'variable' ? element.key : (element.value || '');
    ['x', 'y'].forEach((key) => { fields[key].value = element[key]; });
    fields.w.value = element.w || element.size || 0;
    fields.h.value = element.h || element.size || 0;
    fields.font_size.value = element.font_size || 14;
    fields.align.value = element.align || 'left';
  };
  const choose = (node) => {
    selectedId = node.dataset.id;
    render();
  };

  canvas.addEventListener('pointerdown', (event) => {
    const node = event.target.closest('.designer-element');
    if (!node) {
      selectedId = null;
      render();
      return;
    }
    selectedId = node.dataset.id;
    canvas.querySelectorAll('.designer-element').forEach((item) => item.classList.toggle('selected', item.dataset.id === selectedId));
    syncInspector();
    if (previewMode) return;
    const element = selected();
    checkpoint();
    const resizing = event.target.classList.contains('resize-handle');
    drag = {element, resizing, x: event.clientX, y: event.clientY, left: element.x, top: element.y, w: element.w || element.size, h: element.h || element.size};
    canvas.setPointerCapture(event.pointerId);
  });
  canvas.addEventListener('pointermove', (event) => {
    if (!drag) return;
    const bounds = canvas.getBoundingClientRect();
    const scaleX = bounds.width / canvas.offsetWidth;
    const scaleY = bounds.height / canvas.offsetHeight;
    const dx = (event.clientX - drag.x) / scaleX;
    const dy = (event.clientY - drag.y) / scaleY;
    if (drag.resizing) {
      const w = Math.max(10, Math.round((drag.w + dx) / 5) * 5);
      const h = Math.max(10, Math.round((drag.h + dy) / 5) * 5);
      if (drag.element.type === 'qr') drag.element.size = Math.max(w, h);
      else { drag.element.w = w; drag.element.h = h; }
    } else {
      const snapped = snapToPeers(
        drag.element,
        Math.max(0, Math.round((drag.left + dx) / 5) * 5),
        Math.max(0, Math.round((drag.top + dy) / 5) * 5)
      );
      drag.element.x = snapped.left;
      drag.element.y = snapped.top;
    }
    setDirty();
    render();
  });
  const stopDrag = () => { drag = null; guides = []; render(); };
  canvas.addEventListener('pointerup', stopDrag);
  canvas.addEventListener('pointercancel', stopDrag);
  canvas.addEventListener('click', (event) => {
    const node = event.target.closest('.designer-element');
    if (node) choose(node);
  });

  Object.entries(fields).forEach(([key, input]) => {
    input.addEventListener('input', () => {
      const element = selected();
      if (!element || previewMode) return;
      if (input.dataset.checkpointed !== 'true') {
        checkpoint();
        input.dataset.checkpointed = 'true';
      }
      if (key === 'value') {
        if (element.type === 'variable') element.key = input.value;
        else if (element.type !== 'qr' && element.type !== 'rectangle') element.value = input.value;
      } else if (['x', 'y', 'w', 'h', 'font_size'].includes(key)) {
        const value = Number(input.value);
        if (!Number.isFinite(value)) return;
        if (key === 'w' || key === 'h') {
          if (element.type === 'qr') element.size = Math.max(1, value);
          else element[key] = Math.max(1, value);
        } else element[key] = value;
      } else element[key] = input.value;
      setDirty();
      render();
    });
  });

  Object.values(fields).forEach((input) => {
    input.addEventListener('blur', () => { delete input.dataset.checkpointed; });
  });

  document.getElementById('bring-forward').addEventListener('click', () => {
    const element = selected();
    if (!element || previewMode) return;
    checkpoint();
    element.z_index = Math.max(0, ...layout.elements.map((item) => Number(item.z_index || 0))) + 1;
    setDirty();
    render();
  });
  document.getElementById('send-backward').addEventListener('click', () => {
    const element = selected();
    if (!element || previewMode) return;
    checkpoint();
    element.z_index = Math.min(0, ...layout.elements.map((item) => Number(item.z_index || 0))) - 1;
    setDirty();
    render();
  });

  document.querySelectorAll('[data-add]').forEach((button) => {
    button.addEventListener('click', () => {
      if (previewMode) return;
      checkpoint();
      const type = button.dataset.add;
      const element = {
        id: `el_${crypto.randomUUID().replaceAll('-', '').slice(0, 16)}`,
        type, x: 100, y: 100, w: type === 'qr' ? undefined : 240,
        h: type === 'qr' ? undefined : 48, z_index: layout.elements.length + 1
      };
      if (type === 'text') Object.assign(element, {value: 'New text', font_size: 18});
      if (type === 'variable') {
        Object.assign(element, {key: 'recipient_name', font_size: 22});
        if (!variableSchema.some((field) => field.key === 'recipient_name')) {
          variableSchema.push({key: 'recipient_name', label: 'Recipient name', type: 'short_text', required: false});
        }
      }
      if (type === 'qr') Object.assign(element, {size: 85});
      layout.elements.push(element);
      selectedId = element.id;
      setDirty();
      render();
    });
  });

  document.getElementById('delete-element').addEventListener('click', () => {
    if (!selected() || previewMode) return;
    checkpoint();
    layout.elements = layout.elements.filter((element) => element.id !== selectedId);
    selectedId = null;
    setDirty();
    render();
  });
  document.getElementById('undo').addEventListener('click', () => {
    if (!undoStack.length) return;
    redoStack.push(clone(layout));
    layout = undoStack.pop();
    setDirty();
    updateHistoryButtons();
    render();
  });
  document.getElementById('redo').addEventListener('click', () => {
    if (!redoStack.length) return;
    undoStack.push(clone(layout));
    layout = redoStack.pop();
    setDirty();
    updateHistoryButtons();
    render();
  });
  document.getElementById('preview').addEventListener('click', (event) => {
    previewMode = !previewMode;
    event.currentTarget.textContent = previewMode ? 'Exit preview' : 'Preview';
    document.querySelectorAll('[data-add], #delete-element, #bring-forward, #send-backward').forEach((button) => { button.disabled = previewMode; });
    render();
    status.textContent = previewMode ? 'Preview mode — draft unchanged.' : (dirty ? 'Unsaved draft changes' : 'Draft loaded');
  });
  ['sample-name', 'sample-course'].forEach((id) => {
    document.getElementById(id).addEventListener('input', () => { if (previewMode) render(); });
  });
  document.getElementById('save').addEventListener('click', async () => {
    status.textContent = 'Saving draft…';
    try {
      const response = await fetch(root.dataset.saveUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
          template_id: Number(root.dataset.templateId),
          layout,
          variable_schema: variableSchema
        })
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error?.message || 'Draft could not be saved.');
      dirty = false;
      status.textContent = `Draft v${result.data.version_number} saved. Publishing remains a separate action.`;
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Draft could not be saved.';
    }
  });
  window.addEventListener('beforeunload', (event) => {
    if (dirty) { event.preventDefault(); event.returnValue = ''; }
  });

  updateHistoryButtons();
  render();
  status.textContent = 'Draft loaded';
})();
