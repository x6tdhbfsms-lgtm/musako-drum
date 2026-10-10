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
