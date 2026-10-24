document.querySelectorAll('[data-calendar-date]').forEach((input) => {
    input.addEventListener('change', () => {
        if (input.value && input.validity.valid) {
            input.form?.requestSubmit();
        }
    });
});

document.querySelectorAll('[data-transfer-calendar]').forEach((calendar) => {
    const weeks = [...calendar.querySelectorAll('[data-transfer-week]')];
    const radios = [...calendar.querySelectorAll('[name="requested_starts_at"]')];
    const previous = calendar.querySelector('[data-week-prev]');
    const next = calendar.querySelector('[data-week-next]');
    const heading = calendar.querySelector('[data-week-heading]');
    const summary = calendar.querySelector('[data-transfer-selection]');
    const submit = calendar.querySelector('[data-transfer-submit]');
    const specialHandling = calendar.querySelector('[name="special_handling"]');
    const reason = calendar.querySelector('[name="reason"]');
    const updateReasonRequirement = () => {
        reason.required = specialHandling.checked;
        calendar.querySelector('[data-transfer-reason-label]').textContent = specialHandling.checked
            ? '特例の理由（必須）' : '理由（任意）';
    };
    specialHandling.addEventListener('change', updateReasonRequirement);
    updateReasonRequirement();
    let weekIndex = 0;

    const showWeek = (index) => {
        weekIndex = index;
        weeks.forEach((week, i) => { week.hidden = i !== index; });
        previous.disabled = index === 0;
        next.disabled = index === weeks.length - 1;
        heading.textContent = weeks[index].dataset.weekTitle;
    };
    const updateSelection = () => {
        const selected = radios.find((radio) => radio.checked);
        submit.disabled = !selected;
        summary.textContent = selected
            ? '選択中：' + selected.dataset.slotSummary
            : '週間の表から希望の空き枠を選択してください。';
    };

    calendar.querySelector('[data-week-controls]').hidden = false;
    previous.addEventListener('click', () => showWeek(Math.max(0, weekIndex - 1)));
    next.addEventListener('click', () => showWeek(Math.min(weeks.length - 1, weekIndex + 1)));
    radios.forEach((radio) => radio.addEventListener('change', updateSelection));
    const selectedWeek = radios.find((radio) => radio.checked)?.closest('[data-transfer-week]');
    showWeek(Math.max(0, weeks.indexOf(selectedWeek)));
    updateSelection();
});

document.querySelectorAll('[data-staff-admission]').forEach((form) => {
    const course = form.elements.namedItem('course_id');
    course.addEventListener('change', () => {
        const selected = course.selectedOptions[0];
        form.elements.namedItem('lesson_minutes').value = selected.dataset.minutes;
        const venue = form.elements.namedItem('venue_id');
        const matching = [...venue.options].find((option) => option.dataset.room === selected.dataset.room);
        if (matching) venue.value = matching.value;
        if (selected.dataset.room === 'music_room') form.elements.namedItem('pricing_category').value = 'standard';
    });
});

// Keep the regular lesson selectors compact and consistent with the monthly count.
document.querySelectorAll('[data-staff-admission]').forEach((form) => {
    const pattern = form.elements.namedItem('regular_week_pattern');
    if (!pattern) return;
    const count = form.elements.namedItem('monthly_lesson_count');
    const type = form.elements.namedItem('lesson_type');
    const weekday = form.elements.namedItem('weekday');
    const time = form.elements.namedItem('starts_at_time');
    const update = () => {
        const regular = type.value === 'regular';
        const n = Number(count.value);
        [...pattern.options].forEach((option) => {
            option.hidden = Boolean(option.dataset.count) && Number(option.dataset.count) !== n;
            option.disabled = option.hidden;
        });
        if (pattern.selectedOptions[0]?.disabled) pattern.value = '';
        [pattern, weekday, time].forEach((input) => { input.disabled = !regular; });
        const weeks = pattern.value || (n === 2 ? '1,3' : Array.from({length: Math.max(0, Math.min(4, n))}, (_, i) => i + 1).join(','));
        const label = weeks.split(',').filter(Boolean).map((week) => '第' + week).join('・');
        form.querySelector('[data-schedule-summary]').textContent = regular
            ? '月' + count.value + '回 · ' + label + ' ' + (weekday.value === '' ? '曜日未選択' : weekday.selectedOptions[0].text) + ' ' + (time.value || '時間未選択')
            : 'フレックス：予約ごとに日時を調整します。';
    };
    [pattern, count, type, weekday, time].forEach((input) => input.addEventListener('change', update));
    update();
});
