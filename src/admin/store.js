/**
 * Tiny in-memory hand-off between screens (library selection → batch screen).
 */
const state = { targets: [], labels: {} };

export function setSelection( targets, labels = {} ) {
	state.targets = [ ...targets ];
	state.labels = { ...labels };
}

export function getSelection() {
	return { targets: [ ...state.targets ], labels: { ...state.labels } };
}

export function clearSelection() {
	state.targets = [];
	state.labels = {};
}
