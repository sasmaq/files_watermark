export const t = (app, str, vars = {}) => {
    return Object.entries(vars).reduce(
        (s, [k, v]) => s.replace(`{${k}}`, v),
        str,
    )
}
export const n = (app, singular, plural, count) => (count === 1 ? singular : plural)
