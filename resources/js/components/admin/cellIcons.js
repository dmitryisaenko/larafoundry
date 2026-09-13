/**
 * The closed glyph map for host extra-column badges (phase 7 seam).
 *
 * Lives apart from CellIcon.vue so the table can ASK whether a name is known
 * (and fall back to the badge caption) without mounting a component first. The
 * host names a glyph; it never ships path data — an unknown name simply is not
 * in this map.
 */

// Single-path 24x24 glyphs, at the weight of a letter caption next to them.
export const CELL_ICON_PATHS = {
    // Magnifier.
    search: 'M11 4a7 7 0 104.2 12.6l4.1 4.1a1 1 0 001.4-1.4l-4.1-4.1A7 7 0 0011 4zm0 2a5 5 0 110 10 5 5 0 010-10z',
    // Headphones.
    audio: 'M12 3a8 8 0 00-8 8v5a3 3 0 003 3h1a1 1 0 001-1v-5a1 1 0 00-1-1H6v-1a6 6 0 1112 0v1h-2a1 1 0 00-1 1v5a1 1 0 001 1h1a3 3 0 003-3v-5a8 8 0 00-8-8z',
};

export function hasCellIcon(name) {
    return Object.prototype.hasOwnProperty.call(CELL_ICON_PATHS, name);
}
