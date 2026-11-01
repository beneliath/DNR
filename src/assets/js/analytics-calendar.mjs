const pad = value => String(value).padStart(2, '0');

export const isoCalendarDate = (year, month, day) => `${String(year).padStart(4, '0')}-${pad(month)}-${pad(day)}`;

export function parseCalendarDate(value, max = '') {
  const text = String(value).trim();
  const iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
  const us = /^(\d{1,2})\s*\/\s*(\d{1,2})\s*\/\s*(\d{4})$/.exec(text);
  if (!iso && !us) return '';
  const [year, month, day] = iso
    ? [Number(iso[1]), Number(iso[2]), Number(iso[3])]
    : [Number(us[3]), Number(us[1]), Number(us[2])];
  if (year < 1) return '';
  const date = new Date(0);
  date.setUTCFullYear(year, month - 1, day);
  const normalized = isoCalendarDate(year, month, day);
  if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return '';
  return max && normalized > max ? '' : normalized;
}

export function formatCalendarDate(value) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  return match ? `${match[2]}/${match[3]}/${match[1]}` : '';
}

export function calendarDates(year, month) {
  const first = new Date(0);
  first.setUTCFullYear(year, month, 1);
  first.setUTCDate(1 - first.getUTCDay());
  return Array.from({ length: 42 }, (_, index) => {
    const date = new Date(first);
    date.setUTCDate(first.getUTCDate() + index);
    return {
      iso: isoCalendarDate(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate()),
      day: date.getUTCDate(),
      outside: date.getUTCMonth() !== month,
      date,
    };
  });
}
