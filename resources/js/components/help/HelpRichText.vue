<script setup>
/**
 * The help editor's writing surface (v0.48.0): bold, italic, underline, strike,
 * headings, paragraph alignment, lists, quotes, links and pictures.
 *
 * Built on Tiptap, which the core does NOT ship: this component is deliberately
 * kept OUT of the barrel (like QrScanner) so a host that never edits the help pays
 * nothing for it. A host that shows the core's help editor installs
 * `@tiptap/vue-3`, `@tiptap/starter-kit`, `@tiptap/extension-image` and
 * `@tiptap/extension-text-align` (v3).
 *
 * The toolbar offers ONLY what survives the server's HtmlSanitizer: a button that
 * produced anything outside its allowlist would work on screen and lose the
 * formatting on save. Alignment is therefore offered on paragraphs only (the
 * allowlist grants `style` to `p` and not to headings or list items), and links
 * take http(s) and mailto only.
 *
 * A picture is UPLOADED and a `[[img:ref]]` marker goes into the text, never the
 * bytes (a `data:` URI is stripped on save) and never the address (the marker is
 * resolved when the help is read, so a replaced file reaches every translation).
 *
 * `v-model` is the HTML. The editor is not recreated when it changes from outside
 * (a translation arriving, a language switched): content is pushed in only when it
 * actually differs, so the caret is not thrown to the end on every keystroke.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import TextAlign from '@tiptap/extension-text-align';

const props = defineProps({
    modelValue: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    /** Where a picture is POSTed (field `image`); empty - no picture button. */
    uploadUrl: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'uploaded']);

const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit.configure({
            codeBlock: false,
            horizontalRule: false,
            // Link and Underline come WITH StarterKit in Tiptap 3 - configured here, not
            // registered again (a second Link left the first, openOnClick: true, in charge).
            link: {
                openOnClick: false,
                protocols: ['http', 'https', 'mailto'],
            },
        }),
        // Spelled out because it is the whole rule: a pasted data: picture would
        // vanish on save without a word.
        Image.configure({ allowBase64: false }),
        TextAlign.configure({ types: ['paragraph'] }),
    ],
    editable: ! props.disabled,
    onUpdate: ({ editor: e }) => emit('update:modelValue', e.getHTML()),
});

watch(() => props.modelValue, (next) => {
    if (! editor.value) return;

    // `{ emitUpdate: false }` as an OBJECT: Tiptap 3 ignores a bare `false`, and the
    // push would write `<p></p>` back into a language that was merely looked at.
    if (next !== editor.value.getHTML()) {
        editor.value.commands.setContent(next || '', { emitUpdate: false });
    }
});

// `false`: Tiptap emits an update here by default, and every server call flips
// `disabled` - the model would get the editor's normalised HTML without a keystroke.
watch(() => props.disabled, (off) => editor.value?.setEditable(! off, false));

onBeforeUnmount(() => editor.value?.destroy());

const HEADINGS = [1, 2, 3, 4, 5, 6];

/** The level under the caret, read rather than remembered. */
const headingLevel = computed(() => {
    if (! editor.value) return '';

    return HEADINGS.find((level) => editor.value.isActive('heading', { level })) ?? '';
});

function setHeading(event) {
    const value = event.target.value;

    if (value === '') {
        editor.value?.chain().focus().setParagraph().run();

        return;
    }

    editor.value?.chain().focus().setHeading({ level: Number(value) }).run();
}

function link() {
    const was = editor.value?.getAttributes('link').href ?? '';
    const href = window.prompt('URL', was);

    if (href === null) return;

    if (href === '') {
        editor.value?.chain().focus().extendMarkRange('link').unsetLink().run();

        return;
    }

    editor.value?.chain().focus().extendMarkRange('link').setLink({ href }).run();
}

const picking = ref(false);
const pickError = ref('');
const fileInput = ref(null);
const cheatOpen = ref(false);

function xsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function pickImage() {
    pickError.value = '';
    fileInput.value?.click();
}

async function uploadPicked(event) {
    const file = event.target.files?.[0];

    // Cleared at once: choosing the same file twice fires no second event otherwise.
    event.target.value = '';

    if (! file) return;

    picking.value = true;
    pickError.value = '';

    try {
        const body = new FormData();
        body.append('image', file);
        body.append('ref', file.name.replace(/\.[^.]+$/, ''));

        const res = await fetch(props.uploadUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body,
        });

        const data = await res.json().catch(() => ({}));

        if (! res.ok) throw new Error(data.message || `HTTP ${res.status}`);

        // On its own paragraph: it is read back as a block-level figure.
        editor.value?.chain().focus().insertContent(`<p>${data.picture.marker}</p>`).run();
        emit('uploaded', data.picture);
    } catch (e) {
        pickError.value = e.message;
    } finally {
        picking.value = false;
    }
}
</script>

<template>
    <div class="help-rt" :class="{ 'help-rt-off': disabled }">
        <div v-if="editor" class="help-rt-bar">
            <button type="button" class="help-rt-b" :class="{ 'is-on': editor.isActive('bold') }" :title="$t('Bold')" @click="editor.chain().focus().toggleBold().run()"><b>B</b></button>
            <button type="button" class="help-rt-b" :class="{ 'is-on': editor.isActive('italic') }" :title="$t('Italic')" @click="editor.chain().focus().toggleItalic().run()"><i>I</i></button>
            <button type="button" class="help-rt-b" :class="{ 'is-on': editor.isActive('underline') }" :title="$t('Underline')" @click="editor.chain().focus().toggleUnderline().run()"><u>U</u></button>
            <button type="button" class="help-rt-b" :class="{ 'is-on': editor.isActive('strike') }" :title="$t('Strikethrough')" @click="editor.chain().focus().toggleStrike().run()"><s>S</s></button>

            <span class="help-rt-sep"></span>

            <select class="help-rt-sel" :title="$t('Heading')" :value="headingLevel" @change="setHeading">
                <option value="">{{ $t('Text') }}</option>
                <option v-for="level in HEADINGS" :key="level" :value="level">{{ $t('Heading') }} {{ level }}</option>
            </select>

            <span class="help-rt-sep"></span>

            <button
                v-for="side in ['left', 'center', 'right']" :key="side"
                type="button" class="help-rt-b" :class="{ 'is-on': editor.isActive({ textAlign: side }) }"
                :title="$t({ left: 'Align left', center: 'Align center', right: 'Align right' }[side])"
                @click="editor.chain().focus().setTextAlign(side).run()"
            >{{ { left: '⯇', center: '☰', right: '⯈' }[side] }}</button>

            <span class="help-rt-sep"></span>

            <button type="button" class="help-rt-b" :class="{ 'is-on': editor.isActive('bulletList') }" :title="$t('List')" @click="editor.chain().focus().toggleBulletList().run()">•—</button>
            <button type="button" class="help-rt-b" :class="{ 'is-on': editor.isActive('orderedList') }" :title="$t('Numbered list')" @click="editor.chain().focus().toggleOrderedList().run()">1.</button>
            <button type="button" class="help-rt-b" :class="{ 'is-on': editor.isActive('blockquote') }" :title="$t('Quote')" @click="editor.chain().focus().toggleBlockquote().run()">&raquo;</button>

            <span class="help-rt-sep"></span>

            <button type="button" class="help-rt-b" :class="{ 'is-on': editor.isActive('link') }" :title="$t('Link')" @click="link">&#128279;</button>
            <template v-if="uploadUrl">
                <button type="button" class="help-rt-b" :title="$t('Picture')" :disabled="picking" @click="pickImage">{{ picking ? '…' : '🖼' }}</button>
                <!-- Hidden, not absent: a file input cannot be opened from script unless it exists. -->
                <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden @change="uploadPicked">
                <button type="button" class="help-rt-b" :class="{ 'is-on': cheatOpen }" :title="$t('How to set up a picture')" @click="cheatOpen = ! cheatOpen">?</button>
            </template>
            <button type="button" class="help-rt-b" :title="$t('Clear formatting')" @click="editor.chain().focus().unsetAllMarks().clearNodes().run()">&#10005;</button>
        </div>

        <div v-if="uploadUrl && cheatOpen" class="help-rt-cheat">
            <p class="help-rt-cheat-h">{{ $t('How to set up a picture') }}</p>
            <p>{{ $t('A picture reference may carry a width and a side, in any order, both optional:') }} <code>[[img:name|width=400|align=left]]</code></p>
            <p><code>width</code> - {{ $t('a number of pixels (200 to 2000), or small (300), medium (500), large (700), full (the whole column).') }}</p>
            <p><code>align</code> - {{ $t('left, center or right. Text does not flow beside the picture.') }}</p>
            <p class="help-rt-cheat-n">{{ $t('What the text says beats the width set on the picture itself. On a phone a picture is always full width. A value that makes no sense is ignored.') }}</p>
        </div>

        <p v-if="pickError" class="help-rt-err">{{ pickError }}</p>

        <EditorContent class="help-rt-in" :editor="editor" />
    </div>
</template>

<style scoped>
.help-rt {
    border: 1px solid var(--color-border);
    border-radius: 8px;
    background: var(--color-surface);
    overflow: hidden;
}
.help-rt-off { opacity: 0.6; }

.help-rt-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 2px;
    padding: 6px;
    border-bottom: 1px solid var(--color-border);
}
.help-rt-b {
    min-width: 28px;
    height: 26px;
    padding: 0 6px;
    border: 1px solid transparent;
    border-radius: 6px;
    background: none;
    color: var(--color-ink-soft);
    font-size: 0.8125rem;
    line-height: 1;
    cursor: pointer;
}
.help-rt-b:hover { color: var(--color-ink); border-color: var(--color-border); }
.help-rt-b.is-on { color: var(--color-brand-600); border-color: var(--color-brand-600); }
.help-rt-sel {
    height: 26px;
    padding: 0 4px;
    border: 1px solid var(--color-border);
    border-radius: 6px;
    background: var(--color-surface);
    color: var(--color-ink);
    font-size: 0.8125rem;
}
.help-rt-sep { width: 1px; height: 18px; margin: 0 4px; background: var(--color-border); }

.help-rt-cheat {
    padding: 10px 12px;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-surface-muted);
    color: var(--color-ink-soft);
    font-size: 0.8125rem;
    line-height: 1.55;
}
.help-rt-cheat p { margin: 0 0 6px; }
.help-rt-cheat-h { color: var(--color-ink); font-weight: 600; }
.help-rt-cheat-n { margin-bottom: 0; }
.help-rt-cheat code { padding: 1px 4px; border-radius: 4px; background: var(--color-surface-subtle); }

.help-rt-err {
    margin: 0;
    padding: 6px 12px;
    border-bottom: 1px solid var(--color-border);
    color: #b91c1c;
    font-size: 0.8125rem;
}

.help-rt-in { padding: 10px 12px; color: var(--color-ink); font-size: 0.875rem; line-height: 1.6; }
.help-rt-in :deep(.ProseMirror) { min-height: 160px; outline: none; }
.help-rt-in :deep(.ProseMirror p) { margin: 0 0 10px; }
.help-rt-in :deep(.ProseMirror h1),
.help-rt-in :deep(.ProseMirror h2),
.help-rt-in :deep(.ProseMirror h3),
.help-rt-in :deep(.ProseMirror h4),
.help-rt-in :deep(.ProseMirror h5),
.help-rt-in :deep(.ProseMirror h6) { margin: 14px 0 8px; font-weight: 600; }
.help-rt-in :deep(.ProseMirror h1) { font-size: 1.25rem; }
.help-rt-in :deep(.ProseMirror h2) { font-size: 1.0625rem; }
.help-rt-in :deep(.ProseMirror h3) { font-size: 0.9375rem; }
.help-rt-in :deep(.ProseMirror ul),
.help-rt-in :deep(.ProseMirror ol) { margin: 0 0 10px; padding-left: 20px; }
.help-rt-in :deep(.ProseMirror ul) { list-style: disc; }
.help-rt-in :deep(.ProseMirror ol) { list-style: decimal; }
.help-rt-in :deep(.ProseMirror blockquote) { margin: 0 0 10px; padding-left: 10px; border-left: 2px solid var(--color-border); color: var(--color-ink-soft); }
.help-rt-in :deep(.ProseMirror a) { color: var(--color-brand-600); text-decoration: underline; }
.help-rt-in :deep(.ProseMirror img) { max-width: 100%; height: auto; border-radius: 6px; }
</style>
