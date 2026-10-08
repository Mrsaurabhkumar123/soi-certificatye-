(() => {
  'use strict';

  const form = document.querySelector('form[action$="/manage/forms/create"]');
  if (!form) return;

  const template = form.querySelector('#form_template');
  const list = form.querySelector('#form-field-list');
  const addButton = form.querySelector('#form-add-field');
  const mappingInput = form.querySelector('#form-field-mapping');
  const schemaInput = form.querySelector('#form-field-schema');
  const fieldNamePattern = /^[a-z][a-z0-9_]{0,63}$/;
  let availableVariables = [];

  const normalizeName = (key) => {
    const normalized = key.toLowerCase().replace(/[^a-z0-9_]/g, '_');
    return /^[a-z]/.test(normalized) ? normalized.slice(0, 64) : `field_${normalized}`.slice(0, 64);
  };
  const inputType = (variable) => ({
    email: 'email',
    date: 'date',
    enum: 'enum'
  }[variable.type] || 'text');
  const rows = () => Array.from(list.querySelectorAll('.form-field-row'));
  const selectedKeys = (except = null) => new Set(
    rows().filter((row) => row !== except).map((row) => row.querySelector('[data-field-target]').value)
  );

  const refreshAvailability = () => {
    rows().forEach((row) => {
      const target = row.querySelector('[data-field-target]');
      const current = target.value;
      Array.from(target.options).forEach((option) => {
        option.disabled = option.value !== current && selectedKeys(row).has(option.value);
      });
    });
    const requiredTargets = availableVariables.filter((variable) => variable.required);
    addButton.disabled = availableVariables.every((variable) => selectedKeys().has(variable.key));
    rows().forEach((row) => {
      const required = row.querySelector('[data-field-required]');
      const target = availableVariables.find((variable) => variable.key === row.querySelector('[data-field-target]').value);
      required.disabled = Boolean(target && target.required);
      if (required.disabled) required.checked = true;
    });
    if (!rows().length) {
      const message = document.createElement('p');
      message.className = 'text-muted';
      message.textContent = requiredTargets.length
        ? 'Add the required template fields to continue.'
        : 'Add at least one field. Recipient name is required for certificate issuance.';
      list.replaceChildren(message);
    }
  };

  const createRow = (variable, includeRequired = false) => {
    if (!rows().length) list.replaceChildren();
    const row = document.createElement('div');
    row.className = 'form-field-row';
    const nameLabel = document.createElement('label');
    nameLabel.textContent = 'Field key';
    const name = document.createElement('input');
    name.type = 'text';
    name.required = true;
    name.maxLength = 64;
    name.pattern = '[a-z][a-z0-9_]{0,63}';
    name.value = normalizeName(variable.key);
    name.dataset.fieldName = '';
    nameLabel.append(name);

    const labelLabel = document.createElement('label');
    labelLabel.textContent = 'Applicant-facing label';
    const label = document.createElement('input');
    label.type = 'text';
    label.required = true;
    label.maxLength = 128;
    label.value = variable.label || variable.key;
    label.dataset.fieldLabel = '';
    labelLabel.append(label);

    const targetLabel = document.createElement('label');
    targetLabel.textContent = 'Template variable';
    const target = document.createElement('select');
    target.dataset.fieldTarget = '';
    availableVariables.forEach((item) => {
      const option = document.createElement('option');
      option.value = item.key;
      option.textContent = `${item.label || item.key} ({{${item.key}}})`;
      option.selected = item.key === variable.key;
      target.append(option);
    });
    targetLabel.append(target);

    const typeLabel = document.createElement('label');
    typeLabel.textContent = 'Input type';
    const type = document.createElement('input');
    type.type = 'text';
    type.readOnly = true;
    type.value = inputType(variable);
    type.dataset.fieldType = '';
    typeLabel.append(type);

    const requiredLabel = document.createElement('label');
    requiredLabel.className = 'form-field-required';
    const required = document.createElement('input');
    required.type = 'checkbox';
    required.checked = Boolean(variable.required || includeRequired);
    required.disabled = Boolean(variable.required);
    required.dataset.fieldRequired = '';
    requiredLabel.append(required, document.createTextNode(' Required'));

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-danger btn-sm';
    remove.textContent = 'Remove field';
    remove.disabled = Boolean(variable.required || includeRequired);
    remove.addEventListener('click', () => {
      row.remove();
      refreshAvailability();
    });
    target.addEventListener('change', () => {
      const selected = availableVariables.find((item) => item.key === target.value);
      if (!selected) return;
      name.value = normalizeName(selected.key);
      label.value = selected.label || selected.key;
      type.value = inputType(selected);
      required.checked = Boolean(selected.required);
      remove.disabled = Boolean(selected.required || (includeRequired && selected.key === 'recipient_name'));
      refreshAvailability();
    });

    row.append(nameLabel, labelLabel, targetLabel, typeLabel, requiredLabel, remove);
    list.append(row);
    refreshAvailability();
  };

  const loadTemplate = () => {
    const selected = template.selectedOptions[0];
    availableVariables = selected ? JSON.parse(selected.dataset.variables || '[]') : [];
    list.replaceChildren();
    availableVariables.forEach((variable) => {
      createRow(variable, variable.key === 'recipient_name');
    });
    refreshAvailability();
  };

  template.addEventListener('change', loadTemplate);
  addButton.addEventListener('click', () => {
    const next = availableVariables.find((variable) => !selectedKeys().has(variable.key));
    if (next) createRow(next);
  });
  form.addEventListener('submit', (event) => {
    const currentRows = rows();
    const names = currentRows.map((row) => row.querySelector('[data-field-name]').value.trim());
    if (currentRows.length === 0 || names.some((name) => !fieldNamePattern.test(name))
      || new Set(names).size !== names.length
      || !currentRows.some((row) => row.querySelector('[data-field-target]').value === 'recipient_name')) {
      event.preventDefault();
      window.alert('Add valid, uniquely named fields including recipient name.');
      return;
    }

    const mapping = {};
    const schema = [];
    currentRows.forEach((row, index) => {
      const name = names[index];
      const target = row.querySelector('[data-field-target]').value;
      mapping[name] = target;
      schema.push({
        name,
        label: row.querySelector('[data-field-label]').value.trim(),
        type: row.querySelector('[data-field-type]').value,
        required: row.querySelector('[data-field-required]').checked
      });
    });
    mappingInput.value = JSON.stringify(mapping);
    schemaInput.value = JSON.stringify(schema);
  });

  loadTemplate();
})();
