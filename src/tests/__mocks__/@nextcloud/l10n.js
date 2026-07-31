export const t = (app, str, vars = {}) => {
	return Object.entries(vars).reduce(
		(s, [k, v]) => s.replace(`{${k}}`, v),
		str,
	)
}
// Mirrors the real n(): picks a form, then substitutes %n with the count and any named
// variables. A mock that left %n in place would let a string ship with the count missing
// and every assertion still pass.
export const n = (app, singular, plural, count, vars = {}) => {
	const picked = (count === 1 ? singular : plural).replace(/%n/g, count)
	return Object.entries(vars).reduce(
		(s, [k, v]) => s.replace(`{${k}}`, v),
		picked,
	)
}
