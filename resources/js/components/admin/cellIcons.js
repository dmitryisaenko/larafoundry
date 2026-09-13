/**
 * A short set of common glyphs for host extra-column badges (phase 7 seam).
 *
 * This list is a CONVENIENCE, not a limit. A host may name one of these glyphs,
 * or ship its own as data — `['icon' => ['path' => 'M4 3h10l6 6...']]` — and
 * shipping your own is the ordinary route, not a workaround. What a switch looks
 * like in one application's console is not the package's business, so do NOT add
 * glyphs here for a single host's needs: this stays a short shared set, and
 * everything else travels with the cell.
 *
 * Only path DATA ever travels, and it is bound to the `d` attribute — never as
 * markup. See CellIcon.vue.
 *
 * Lives apart from CellIcon.vue so the table can ASK whether a name is known
 * (and fall back to the badge caption) without mounting a component first.
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

/**
 * The ceiling on a host-supplied path. Not a security boundary — path data is an
 * attribute value, never markup — but a 10 000-character `d` repeated down fifty
 * rows has stopped being a glyph, so it degrades to the caption instead.
 */
export const MAX_ICON_PATH_LENGTH = 4096;

// Four numbers separated by spaces. Anything else takes the default: a free
// string in an attribute buys nothing here and invites a conversation.
const VIEW_BOX_PATTERN = /^-?\d+(\.\d+)?( -?\d+(\.\d+)?){3}$/;

export const DEFAULT_VIEW_BOX = '0 0 24 24';

/**
 * Resolve a badge's `icon` — a name from the set above, or the host's own glyph
 * as data — into what CellIcon needs to draw. Returns null when there is nothing
 * drawable, and the caller degrades to the badge caption (the same thing an
 * unknown name has always done).
 */
export function resolveCellIcon(icon) {
    if (typeof icon === 'string') {
        return hasCellIcon(icon)
            ? { path: CELL_ICON_PATHS[icon], viewBox: DEFAULT_VIEW_BOX, fill: 'currentColor', strokeWidth: null }
            : null;
    }

    // Anything that is not a plain object (a number, an array, null) is as
    // unknown as a misspelled name: degrade, never break the row.
    if (icon === null || typeof icon !== 'object' || Array.isArray(icon)) {
        return null;
    }

    const path = icon.path;

    if (typeof path !== 'string' || path === '' || path.length > MAX_ICON_PATH_LENGTH) {
        return null;
    }

    // Narrowed to a real, positive number, like the other fields are narrowed:
    // only a validated value ever reaches an attribute. This also keeps a falsy
    // `0` (or an empty string) from producing an invisible glyph — a zero-width
    // stroke over a suppressed fill draws nothing at all.
    const rawStroke = typeof icon.stroke_width === 'number' || typeof icon.stroke_width === 'string'
        ? Number(icon.stroke_width)
        : Number.NaN;
    const strokeWidth = Number.isFinite(rawStroke) && rawStroke > 0 && rawStroke <= 100
        ? rawStroke
        : null;

    // An outline glyph carries its weight in the stroke, so `fill` defaults to
    // none once a stroke width is given.
    const fill = icon.fill === 'none' || icon.fill === 'currentColor'
        ? icon.fill
        : (strokeWidth !== null ? 'none' : 'currentColor');

    return {
        path,
        viewBox: typeof icon.view_box === 'string' && VIEW_BOX_PATTERN.test(icon.view_box)
            ? icon.view_box
            : DEFAULT_VIEW_BOX,
        fill,
        strokeWidth,
    };
}
