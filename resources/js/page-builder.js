/**
 * Canvas behaviour for the Filament Page Builder.
 *
 * Hand-written and shipped unminified. Uses the browser's native drag-and-drop rather
 * than a library: Filament bundles SortableJS but does not expose it as a public global,
 * and the package must not require consumers to run a JS build — the whole point is that
 * it works on hosts with no Node installed.
 *
 * Reordering is resolved here and committed to Livewire in a single call per drop, so a
 * drag gesture never waits on the server.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('pageBuilderCanvas', () => ({
        /** 'move' while dragging an existing block, 'insert' from the palette, null otherwise. */
        mode: null,
        payload: null,
        marker: null,
        teardown: [],

        init() {
            this.bind(window, 'beforeunload', (event) => this.guardUnload(event));
            this.bind(document, 'keydown', (event) => this.onKeydown(event));

            // Inline editing. Delegated from the root so blocks re-rendered by Livewire
            // are covered without rebinding anything.
            this.bind(this.$el, 'focusin', (event) => this.onEditableFocus(event));
            this.bind(this.$el, 'focusout', (event) => this.onEditableBlur(event));
            this.bind(this.$el, 'keydown', (event) => this.onEditableKeydown(event), true);

            // Filament navigates between panel pages without a page load, so the browser's
            // own unload prompt never fires. Livewire's navigate event is the only chance
            // to stop the canvas being left with unsaved work.
            this.bind(document, 'livewire:navigate', (event) => this.guardNavigate(event));

            this.$cleanup(() => {
                this.teardown.forEach((off) => off());
                this.teardown = [];
            });
        },

        bind(target, event, handler, capture = false) {
            target.addEventListener(event, handler, capture);
            this.teardown.push(() => target.removeEventListener(event, handler, capture));
        },

        /* ── Leaving with unsaved work ──────────────────── */

        guardUnload(event) {
            if (!this.$wire.isDirty) {
                return;
            }

            event.preventDefault();
            // Browsers ignore the message and show their own, but older ones need a value.
            event.returnValue = '';
        },

        guardNavigate(event) {
            if (!this.$wire.isDirty) {
                return;
            }

            if (!window.confirm('This page has unsaved changes. Leave without saving?')) {
                event.preventDefault();
            }
        },

        /* ── Keyboard ───────────────────────────────────── */

        /**
         * Whether the user is typing, in which case the canvas keeps its hands off.
         *
         * Covers the inspector's inputs and, from Stage B onwards, text being edited
         * directly on the page.
         */
        isTyping(event) {
            const el = event.target;

            return (
                el instanceof HTMLElement &&
                (el.isContentEditable ||
                    ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) ||
                    el.closest('[contenteditable="true"]') !== null)
            );
        },

        onKeydown(event) {
            const chord = event.metaKey || event.ctrlKey;

            if (chord && event.key.toLowerCase() === 's') {
                event.preventDefault();

                return this.$wire.save();
            }

            if (chord && event.key.toLowerCase() === 'z') {
                event.preventDefault();

                return event.shiftKey ? this.$wire.redo() : this.$wire.undo();
            }

            if (this.isTyping(event)) {
                return;
            }

            const selected = this.$wire.selectedId;

            if (event.key === 'Escape') {
                return this.$wire.selectBlock(null);
            }

            if (!selected) {
                return;
            }

            if (chord && event.key.toLowerCase() === 'd') {
                event.preventDefault();

                return this.$wire.duplicateBlock(selected);
            }

            if (event.key === 'Backspace' || event.key === 'Delete') {
                event.preventDefault();

                return this.remove(selected, this.selectedHasContent());
            }

            if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                event.preventDefault();

                const step = event.key === 'ArrowDown' ? 1 : -1;

                return event.shiftKey
                    ? this.moveSelected(selected, step)
                    : this.selectNeighbour(selected, step);
            }
        },

        /** Block ids in the order they appear on the canvas. */
        order() {
            return Array.from(this.$el.querySelectorAll('.fpb-block')).map((el) => el.dataset.id);
        },

        selectedHasContent() {
            const el = this.$el.querySelector('.fpb-block[data-selected="true"]');

            return el ? el.dataset.hasContent === 'true' : false;
        },

        selectNeighbour(id, step) {
            const order = this.order();
            const next = order[order.indexOf(id) + step];

            if (next) {
                this.$wire.selectBlock(next);
            }
        },

        moveSelected(id, step) {
            const order = this.order();
            const from = order.indexOf(id);
            const to = from + step;

            if (to < 0 || to >= order.length) {
                return;
            }

            // moveBlock takes the index in the array before the block is lifted out, so
            // moving down by one has to aim one past the neighbour it swaps with.
            this.$wire.moveBlock(id, step > 0 ? to + 1 : to);
        },

        /* ── Editing text on the page ───────────────────── */

        /** The editable field an event happened inside, if any. */
        editableFrom(event) {
            const el = event.target;

            return el instanceof HTMLElement ? el.closest('[data-fpb-field]') : null;
        },

        onEditableFocus(event) {
            const el = this.editableFrom(event);

            if (!el) {
                return;
            }

            // Remember what was there, so Escape has something to go back to.
            el.dataset.fpbOriginal = el.innerText;

            // Typing in a block is a way of choosing it. The inspector is a second view
            // of the same fields and should follow the caret.
            if (this.$wire.selectedId !== el.dataset.fpbBlock) {
                this.$wire.selectBlock(el.dataset.fpbBlock);
            }
        },

        /**
         * Commit on blur rather than on every keystroke.
         *
         * A round trip per character would have Livewire re-render the block under the
         * caret. Committing once, when the caret has already left, is both cheaper and
         * the same thing the inspector's own fields do.
         */
        onEditableBlur(event) {
            const el = this.editableFrom(event);

            if (!el) {
                return;
            }

            const value = el.innerText;
            const original = el.dataset.fpbOriginal;

            delete el.dataset.fpbOriginal;

            if (value === original) {
                return;
            }

            this.$wire.setBlockField(el.dataset.fpbBlock, el.dataset.fpbField, value);
        },

        onEditableKeydown(event) {
            const el = this.editableFrom(event);

            if (!el) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();

                el.innerText = el.dataset.fpbOriginal ?? '';
                delete el.dataset.fpbOriginal;

                return el.blur();
            }

            if (event.key === 'Enter' && el.dataset.fpbMultiline !== 'true') {
                event.preventDefault();

                return el.blur();
            }
        },

        remove(id, hasContent) {
            if (hasContent && !window.confirm('Delete this block? Its content goes with it.')) {
                return;
            }

            this.$wire.removeBlock(id);
        },

        startMove(event, id) {
            this.mode = 'move';
            this.payload = id;
            event.dataTransfer.effectAllowed = 'move';
            // Firefox refuses to start a drag unless something is set.
            event.dataTransfer.setData('text/plain', id);
        },

        startInsert(event, type) {
            this.mode = 'insert';
            this.payload = type;
            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData('text/plain', type);
        },

        clearDrag() {
            this.mode = null;
            this.payload = null;
            this.removeMarker();
        },

        /** Index the dragged item would land at, from the pointer's position. */
        targetIndex(event) {
            const blocks = Array.from(this.$el.querySelectorAll('.fpb-block'));

            if (blocks.length === 0) {
                return 0;
            }

            for (let i = 0; i < blocks.length; i++) {
                const box = blocks[i].getBoundingClientRect();

                if (event.clientY < box.top + box.height / 2) {
                    return i;
                }
            }

            return blocks.length;
        },

        onDragOver(event) {
            if (!this.mode) {
                return;
            }

            event.dataTransfer.dropEffect = this.mode === 'move' ? 'move' : 'copy';
            this.showMarker(this.targetIndex(event));
        },

        onDragLeave(event) {
            if (!this.$el.contains(event.relatedTarget)) {
                this.removeMarker();
            }
        },

        onDrop(event) {
            if (!this.mode) {
                return;
            }

            const index = this.targetIndex(event);
            const mode = this.mode;
            const payload = this.payload;

            this.clearDrag();

            if (mode === 'move') {
                this.$wire.moveBlock(payload, index);
            } else {
                this.$wire.insertBlock(payload, index);
            }
        },

        showMarker(index) {
            this.removeMarker();

            const canvas = this.$el.querySelector('.fpb-canvas');

            if (!canvas) {
                return;
            }

            const blocks = Array.from(canvas.querySelectorAll('.fpb-block'));

            this.marker = document.createElement('div');
            this.marker.className = 'fpb-drop-marker';

            if (index >= blocks.length) {
                canvas.appendChild(this.marker);
            } else {
                canvas.insertBefore(this.marker, blocks[index]);
            }
        },

        removeMarker() {
            if (this.marker && this.marker.parentNode) {
                this.marker.parentNode.removeChild(this.marker);
            }

            this.marker = null;
        },
    }));
});

/**
 * Keep Livewire's morph off whatever is being typed into.
 *
 * A render triggered by anything else — selecting a block, an inspector field, a save —
 * would otherwise rewrite the element under the caret with the server's copy of the same
 * text and drop the caret to the end of it.
 */
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morph.updating', ({ el, skip }) => {
        if (
            el instanceof HTMLElement &&
            el.hasAttribute('data-fpb-field') &&
            (el === document.activeElement || el.contains(document.activeElement))
        ) {
            skip();
        }
    });
});
