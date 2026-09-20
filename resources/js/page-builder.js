/**
 * Canvas behaviour for the Filament Page Builder.
 *
 * Hand-written and shipped unminified. Uses the browser's native drag-and-drop rather
 * than a library: Filament bundles SortableJS but does not expose it as a public global,
 * and the package must not require consumers to run a JS build — the whole point is that
 * it works on hosts with no Node installed.
 *
 * Drop targets are slots (columns) and the root canvas. The pointer resolves to the
 * nearest valid well, so a block can land beside its siblings or inside a section,
 * WordPress-style. Reordering is committed to Livewire in a single call per drop.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('pageBuilderCanvas', () => ({
        /** 'move' while dragging an existing block, 'insert' from the palette, null otherwise. */
        mode: null,
        payload: null,
        marker: null,
        target: null,
        teardown: [],
        sideTab: 'blocks',
        inspectorTab: 'content',
        preview: 'desktop',

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

        /** Block ids in the order they appear on the canvas, nested children included. */
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
            const el = this.blockEl(id);

            if (!el) {
                return;
            }

            const siblings = this.directBlocks(this.slotOf(el) ?? this.canvas());
            const from = siblings.indexOf(el);
            const to = from + step;

            if (from < 0 || to < 0 || to >= siblings.length) {
                return;
            }

            const parent = el.dataset.parent || null;
            const slot = el.dataset.slot || null;

            // moveBlock takes the index in the sibling list before the block is lifted
            // out, so moving down by one has to aim one past the neighbour it swaps with.
            this.$wire.moveBlock(id, step > 0 ? to + 1 : to, parent, slot);
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

            const el = this.blockEl(id);

            if (el) {
                el.dataset.dragging = 'true';
            }
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
            this.target = null;
            this.removeMarker();
            this.clearSlotHighlight();

            this.$el.querySelectorAll('.fpb-block[data-dragging]').forEach((el) => {
                delete el.dataset.dragging;
            });
        },

        canvas() {
            return this.$el.querySelector('.fpb-canvas');
        },

        blockEl(id) {
            return this.$el.querySelector(`.fpb-block[data-id="${this.cssEscape(id)}"]`);
        },

        cssEscape(value) {
            return typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(value) : value.replace(/"/g, '\\"');
        },

        slotOf(el) {
            return el.closest('.fpb-slot');
        },

        /** Direct child blocks of a slot or the root canvas — not nested descendants. */
        directBlocks(container) {
            if (!container) {
                return [];
            }

            return Array.from(container.children).filter((el) => el.classList?.contains('fpb-block'));
        },

        /**
         * Whether dropping `id` into `parent` would nest a block inside itself.
         */
        isInvalidParent(id, parent) {
            if (!id || !parent) {
                return false;
            }

            if (id === parent) {
                return true;
            }

            const parentEl = this.blockEl(parent);

            return parentEl !== null && this.blockEl(id)?.contains(parentEl);
        },

        /**
         * The slot or root canvas the pointer is over, and the sibling index it would
         * land at. Prefers an inner column when the pointer is inside one, so dropping
         * onto a section does not always bounce the block back to the page root.
         */
        targetFromEvent(event) {
            const canvas = this.canvas();

            if (!canvas) {
                return null;
            }

            const slot = event.target instanceof Element ? event.target.closest('.fpb-slot') : null;
            const overCanvas = event.target instanceof Element && canvas.contains(event.target);

            if (!overCanvas && event.target !== canvas) {
                // Still allow the empty-canvas drop, when the event target is the canvas itself.
            }

            let container = canvas;
            let parent = null;
            let slotName = null;

            if (slot && canvas.contains(slot)) {
                container = slot;
                parent = slot.dataset.fpbParent || null;
                slotName = slot.dataset.fpbSlot || null;
            }

            if (this.mode === 'move' && this.isInvalidParent(this.payload, parent)) {
                return null;
            }

            const blocks = this.directBlocks(container);
            let index = blocks.length;

            for (let i = 0; i < blocks.length; i++) {
                if (this.mode === 'move' && blocks[i].dataset.id === this.payload) {
                    continue;
                }

                const box = blocks[i].getBoundingClientRect();

                if (event.clientY < box.top + box.height / 2) {
                    index = i;

                    break;
                }
            }

            // The sibling index we send to Livewire includes the dragged block when it
            // is already in this group, matching moveBlock's "before lift" contract.
            if (this.mode === 'move') {
                const dragged = blocks.find((el) => el.dataset.id === this.payload);

                if (dragged) {
                    const draggedIndex = blocks.indexOf(dragged);

                    if (draggedIndex !== -1 && draggedIndex < index) {
                        // Walking past the dragged block: the visual gap after it is
                        // already "its current index + 1" in the before-lift list.
                    }
                } else {
                    // Coming from another slot: `index` is the destination sibling list
                    // as it stands, which is what insert/move expect for a new group.
                }
            }

            return { parent, slot: slotName, index, container };
        },

        onDragOver(event) {
            if (!this.mode) {
                return;
            }

            event.dataTransfer.dropEffect = this.mode === 'move' ? 'move' : 'copy';

            const next = this.targetFromEvent(event);

            this.target = next;
            this.highlightSlot(next?.container);
            this.showMarker(next);
        },

        onDragLeave(event) {
            if (!this.$el.contains(event.relatedTarget)) {
                this.removeMarker();
                this.clearSlotHighlight();
            }
        },

        onDrop(event) {
            if (!this.mode) {
                return;
            }

            const next = this.targetFromEvent(event) ?? this.target;
            const mode = this.mode;
            const payload = this.payload;

            this.clearDrag();

            if (!next) {
                return;
            }

            if (mode === 'move') {
                this.$wire.moveBlock(payload, next.index, next.parent, next.slot);
            } else {
                this.$wire.insertBlock(payload, next.index, next.parent, next.slot);
            }
        },

        highlightSlot(container) {
            this.clearSlotHighlight();

            if (container && container.classList.contains('fpb-slot')) {
                container.dataset.dropActive = 'true';
            }
        },

        clearSlotHighlight() {
            this.$el.querySelectorAll('.fpb-slot[data-drop-active]').forEach((el) => {
                delete el.dataset.dropActive;
            });
        },

        showMarker(target) {
            this.removeMarker();

            if (!target || !target.container) {
                return;
            }

            const blocks = this.directBlocks(target.container);

            this.marker = document.createElement('div');
            this.marker.className = 'fpb-drop-marker';

            const before = blocks[target.index];

            if (before) {
                target.container.insertBefore(this.marker, before);
            } else {
                target.container.appendChild(this.marker);
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
