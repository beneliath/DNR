export function ruleValueAttributes(definition, operator) {
    if (['last_days', 'not_last_days'].includes(operator)) return {type: 'number', step: 1, min: 1, max: 36500};
    if (definition.kind === 'number') return {type: 'number', step: 'any'};
    if (definition.kind === 'date' || (['event', 'engagement'].includes(definition.kind) && ['before', 'after'].includes(operator))) {
        return {type: 'text', 'data-date-picker': 'date', placeholder: 'YYYY-MM-DD'};
    }
    if (definition.field_type === 'birthday' && ['is', 'is_not'].includes(operator)) {
        return {type: 'text', 'data-date-picker': 'birthday', placeholder: 'MM-DD'};
    }
    return {type: 'text'};
}
