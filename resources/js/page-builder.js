/**
 * Canvas behaviour for the Filament Page Builder.
 *
 * Hand-written and shipped unminified. Uses the browser's native drag-and-drop rather
 * than a library: Filament bundles SortableJS but does not expose it as a public global,
 * and the package must not require consumers to run a JS build — the whole point is that
 * it works on hosts with no Node installed.
 *
 * Drop targets are slots (columns) and the root canvas. The pointer resolves to the
 * nearest valid well, so a block can land beside its siblings or inside a section.
 * Reordering is committed to Livewire in a single call per drop.
 *
 * Motion is Anime.js, vendored beside this file. It is deliberately optional: every
 * animation goes through `motion`, which no-ops when the library is absent or when the
 * reader has asked for reduced motion, and the editor stays fully usable either way.
 */

/**
 * The editor's motion vocabulary.
 *
 * Nothing here changes what the canvas *does* — every one of these runs after the state
 * has already changed, purely to show which thing changed and where it went. A page
 * builder edits by replacing chunks of the document out from under you, and without
 * some continuity a drop reads as "nothing happened" even when it worked.
 */
const motion = {
    /** Durations in ms, kept together so the whole editor stays on one rhythm. */
    duration: { flash: 620, enter: 420, exit: 200, move: 380, marker: 160 },

    get anime() {
        return typeof window.anime === 'function' ? window.anime : null;
    },

    /**
     * Whether to animate at all.
     *
     * A hidden tab has no business running timelines, and `prefers-reduced-motion` is a
     * request, not a hint — in a tool people use all day it is the difference between
     * usable and nauseating.
     */
    get enabled() {
        return (
            this.anime !== null &&
            document.visibilityState === 'visible' &&
            !window.matchMedia('(prefers-reduced-motion: reduce)').matches
        );
    },

    /**
     * Run `params` through Anime.js.
     *
     * When motion is off the animation is skipped but its `complete` callback still
     * fires, straight away — callers hang real work off it (removing a block, tidying
     * up inline styles) and that work has to happen either way.
     */
    play(params) {
        if (!this.enabled) {
            params.complete?.();

            return null;
        }

        return this.anime(params);
    },

    /**
     * A ring that blooms out of a block and fades.
     *
     * Drawn on a throwaway overlay rather than the block's own outline so it can sit on
     * top of the block's content without the block's styles having to know about it, and
     * so two flashes in a row cannot fight over one property.
     */
    flash(el, tone = 'accent') {
        if (!el || !this.enabled) {
            return;
        }

        const ring = document.createElement('div');
        ring.className = 'fpb-flash';
        ring.dataset.tone = tone;
        el.appendChild(ring);

        this.anime({
            targets: ring,
            opacity: [0, 1, 0],
            scale: [0.985, 1.012],
            easing: 'easeOutQuad',
            duration: this.duration.flash,
            complete: () => ring.remove(),
        });
    },

    /** Clear anything a timeline left behind on an element it no longer owns. */
    reset(el) {
        if (el) {
            el.style.opacity = '';
            el.style.transform = '';
        }
    },
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('pageBuilderCanvas', () => ({
        /*
         * Everything here looks elements up from `$root`, never `$el`.
         *
         * These methods are called from `x-on` expressions all over the tree, and inside
         * such an expression `$el` is the element the listener sits on — the canvas, a
         * palette button, a drag handle — not the component root. `canvas()` resolving
         * against the canvas itself returned null, which made every drag silently do
         * nothing. `$root` is the same element whichever handler we came in through.
         */

        /** 'move' while dragging an existing block, 'insert' from the palette, null otherwise. */
        mode: null,
        payload: null,
        marker: null,
        target: null,
        teardown: [],
        sideTab: 'blocks',
        inspectorTab: 'content',
        preview: 'desktop',

        /* ── Motion bookkeeping (not reactive state the template reads) ─── */

        /** Block ids already on the canvas, so a re-render can tell new from moved. */
        seen: null,
        /** id → bounding box, taken just before a change, for the FLIP afterwards. */
        before: null,
        observer: null,
        /** rAF handle and pixels-per-frame for the edge autoscroll while dragging. */
        scrolling: null,
        scrollSpeed: 0,
        /** When `before` was taken, so a click that changed nothing cannot mislead us. */
        capturedAt: 0,
        /** A block that was just moved, to be pointed out once it has finished sliding. */
        landing: null,

        init() {
            this.bind(window, 'beforeunload', (event) => this.guardUnload(event));
            this.bind(document, 'keydown', (event) => this.onKeydown(event));

            // Inline editing. Delegated from the root so blocks re-rendered by Livewire
            // are covered without rebinding anything.
            this.bind(this.$root, 'focusin', (event) => this.onEditableFocus(event));
            this.bind(this.$root, 'focusout', (event) => this.onEditableBlur(event));
            this.bind(this.$root, 'keydown', (event) => this.onEditableKeydown(event), true);

            // Filament navigates between panel pages without a page load, so the browser's
            // own unload prompt never fires. Livewire's navigate event is the only chance
            // to stop the canvas being left with unsaved work.
            this.bind(document, 'livewire:navigate', (event) => this.guardNavigate(event));

            // Positions are taken before the click that changes them, so anything the
            // toolbar or a block's own buttons set off — undo, redo, duplicate, delete —
            // can be animated without every one of them having to say so.
            this.bind(this.$root, 'pointerdown', () => this.captureRects(), true);

            this.seen = new Set(this.order());
            this.watchCanvas();
        },

        /**
         * Alpine's teardown hook. The listeners above live on `window` and `document`,
         * so nothing else would ever take them off: Filament's SPA navigation swaps the
         * page out without a reload, and a second visit would otherwise stack a fresh
         * set on top of the last.
         */
        destroy() {
            this.teardown.forEach((off) => off());
            this.teardown = [];
            this.observer?.disconnect();
            this.observer = null;
            this.stopAutoscroll();
        },

        bind(target, event, handler, capture = false) {
            target.addEventListener(event, handler, capture);
            this.teardown.push(() => target.removeEventListener(event, handler, capture));
        },

        /* ── Showing what changed ───────────────────────── */

        /**
         * Watch the canvas and narrate whatever Livewire just did to it.
         *
         * Livewire replaces markup without saying what changed, so the canvas works it
         * out by diffing: an id that was not there before has arrived, an id whose box
         * has moved was reordered. Reading the DOM rather than hooking each action means
         * undo, redo, duplicate and the inspector are all covered by the same code.
         */
        watchCanvas() {
            const canvas = this.canvas();

            if (!canvas || typeof MutationObserver === 'undefined') {
                return;
            }

            let queued = false;

            this.observer = new MutationObserver((records) => {
                if (queued || records.every((record) => this.isOwnChrome(record))) {
                    return;
                }

                queued = true;

                // A single morph fires dozens of records; one pass per frame is plenty.
                requestAnimationFrame(() => {
                    queued = false;
                    this.settle();
                });
            });

            this.observer.observe(canvas, { childList: true, subtree: true });
        },

        /**
         * Whether a mutation is just the editor's own decoration.
         *
         * The drop marker moves on every pointer move during a drag and the flash ring
         * comes and goes on its own; both land in the canvas and would otherwise read as
         * the page having changed — which, with the marker being 3px tall, had every
         * block on the page twitching as the cursor went by.
         */
        isOwnChrome(record) {
            const nodes = [...record.addedNodes, ...record.removedNodes];

            return (
                nodes.length > 0 &&
                nodes.every(
                    (node) =>
                        node instanceof HTMLElement &&
                        (node.classList.contains('fpb-drop-marker') || node.classList.contains('fpb-flash'))
                )
            );
        },

        /** Where every block sits right now, to compare against once it has changed. */
        captureRects() {
            if (!motion.enabled) {
                return;
            }

            this.capturedAt = Date.now();
            this.before = new Map(
                Array.from(this.$root.querySelectorAll('.fpb-block')).map((el) => [
                    el.dataset.id,
                    el.getBoundingClientRect(),
                ])
            );
        },

        /** The canvas has settled: greet what is new, carry what moved. */
        settle() {
            const blocks = Array.from(this.$root.querySelectorAll('.fpb-block'));

            // A capture belongs to the change it preceded. If a second has gone by, the
            // click it came from did nothing to the canvas and the boxes are stale.
            const before = Date.now() - this.capturedAt < 1500 ? this.before : null;

            this.before = null;

            blocks.forEach((el) => {
                if (!this.seen.has(el.dataset.id)) {
                    return this.enter(el);
                }

                const was = before?.get(el.dataset.id);

                if (was) {
                    this.slide(el, was);
                }
            });

            // A block that merely moved gets no entrance, so without this the reader has
            // to work out for themselves which of seven sliding blocks was theirs.
            if (this.landing) {
                motion.flash(this.blockEl(this.landing));
                this.landing = null;
            }

            this.seen = new Set(blocks.map((el) => el.dataset.id));
        },

        /** A block that was not on the page a moment ago. */
        enter(el) {
            if (motion.enabled) {
                // Set before the first frame Anime.js gets, so the block never shows at
                // full strength and then starts its entrance from nothing.
                el.style.opacity = '0';
            }

            motion.play({
                targets: el,
                opacity: [0, 1],
                translateY: [14, 0],
                scale: [0.985, 1],
                easing: 'easeOutCubic',
                duration: motion.duration.enter,
                complete: () => motion.reset(el),
            });

            motion.flash(el);
            this.revealBlock(el);
        },

        /**
         * FLIP. The block is already where it belongs, so put it back visually and let
         * it travel: transforms cost nothing per frame, whereas animating the layout
         * itself would reflow the whole page sixty times a second.
         */
        slide(el, was) {
            const now = el.getBoundingClientRect();
            const dx = was.left - now.left;
            const dy = was.top - now.top;

            // Sub-pixel drift from a scrollbar appearing is not a move worth showing.
            if (Math.abs(dx) < 2 && Math.abs(dy) < 2) {
                return;
            }

            motion.play({
                targets: el,
                translateX: [dx, 0],
                translateY: [dy, 0],
                easing: 'easeOutQuad',
                duration: motion.duration.move,
                complete: () => motion.reset(el),
            });
        },

        /**
         * Bring a block into view inside the canvas.
         *
         * The canvas is its own scroller, so a block inserted below the fold would land
         * out of sight and read as nothing having happened at all.
         */
        revealBlock(el) {
            const frame = this.$root.querySelector('.fpb-canvas-frame');

            if (!frame || !el) {
                return;
            }

            const box = el.getBoundingClientRect();
            const view = frame.getBoundingClientRect();

            if (box.top >= view.top && box.bottom <= view.bottom) {
                return;
            }

            const centred = (view.height - Math.min(box.height, view.height)) / 2;
            const to = Math.max(0, frame.scrollTop + (box.top - view.top) - centred);

            if (!motion.enabled) {
                frame.scrollTop = to;

                return;
            }

            // Animate a number and write it across, rather than asking Anime.js to guess
            // what kind of property `scrollTop` is.
            const at = { top: frame.scrollTop };

            motion.play({
                targets: at,
                top: to,
                easing: 'easeInOutQuad',
                duration: 420,
                update: () => {
                    frame.scrollTop = at.top;
                },
            });
        },

        /**
         * Scroll the canvas when a drag reaches its edge.
         *
         * Native drag-and-drop will not scroll a nested scroller for you, so without
         * this there is no way to drop a block anywhere that is not already on screen —
         * which, on a real page, is most of it.
         */
        autoscroll(event) {
            const frame = this.$root.querySelector('.fpb-canvas-frame');

            if (!frame) {
                return;
            }

            const view = frame.getBoundingClientRect();
            const zone = Math.min(110, view.height / 4);
            const fromTop = event.clientY - view.top;
            const fromBottom = view.bottom - event.clientY;

            if (fromTop < zone) {
                this.scrollSpeed = -Math.ceil(((zone - fromTop) / zone) * 20);
            } else if (fromBottom < zone) {
                this.scrollSpeed = Math.ceil(((zone - fromBottom) / zone) * 20);
            } else {
                return this.stopAutoscroll();
            }

            if (this.scrolling !== null) {
                return;
            }

            const step = () => {
                frame.scrollTop += this.scrollSpeed;
                this.scrolling = requestAnimationFrame(step);
            };

            this.scrolling = requestAnimationFrame(step);
        },

        stopAutoscroll() {
            if (this.scrolling !== null) {
                cancelAnimationFrame(this.scrolling);
                this.scrolling = null;
            }

            this.scrollSpeed = 0;
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
            return Array.from(this.$root.querySelectorAll('.fpb-block')).map((el) => el.dataset.id);
        },

        selectedHasContent() {
            const el = this.$root.querySelector('.fpb-block[data-selected="true"]');

            return el ? el.dataset.hasContent === 'true' : false;
        },

        selectNeighbour(id, step) {
            const order = this.order();
            const next = order[order.indexOf(id) + step];

            if (next) {
                this.$wire.selectBlock(next);
                this.revealBlock(this.blockEl(next));
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

            this.captureRects();
            this.landing = id;

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

        /**
         * Delete, but let the block leave first.
         *
         * The server rewrites the page around it either way; playing the exit before the
         * call makes the gap closing up read as one movement instead of a block blinking
         * out and everything below it jumping.
         */
        remove(id, hasContent) {
            if (hasContent && !window.confirm('Delete this block? Its content goes with it.')) {
                return;
            }

            const el = this.blockEl(id);

            this.captureRects();

            motion.play({
                targets: el,
                opacity: [1, 0],
                translateX: [0, -18],
                scale: [1, 0.97],
                easing: 'easeInQuad',
                duration: motion.duration.exit,
                complete: () => {
                    motion.reset(el);
                    this.$wire.removeBlock(id);
                },
            });
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

            this.lift(event.currentTarget);
        },

        startInsert(event, type) {
            this.mode = 'insert';
            this.payload = type;
            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData('text/plain', type);

            this.lift(event.currentTarget);
        },

        /**
         * A press-down on the thing you just picked up.
         *
         * The browser has already photographed the drag image by the time this runs, so
         * this only ever moves what stays behind — which is the point: it acknowledges
         * the grab without the ghost under the cursor twitching.
         */
        lift(el) {
            motion.play({
                targets: el,
                scale: [1, 0.95, 1],
                easing: 'easeOutQuad',
                duration: 280,
                complete: () => motion.reset(el),
            });
        },

        clearDrag() {
            this.mode = null;
            this.payload = null;
            this.target = null;
            this.removeMarker();
            this.clearSlotHighlight();
            this.stopAutoscroll();

            this.$root.querySelectorAll('.fpb-block[data-dragging]').forEach((el) => {
                delete el.dataset.dragging;
            });
        },

        canvas() {
            return this.$root.querySelector('.fpb-canvas');
        },

        blockEl(id) {
            return this.$root.querySelector(`.fpb-block[data-id="${this.cssEscape(id)}"]`);
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
            this.autoscroll(event);
        },

        onDragLeave(event) {
            if (!this.$root.contains(event.relatedTarget)) {
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

            // Taken here rather than at `dragstart`: a drag lasts as long as the reader
            // takes to aim, and these boxes are only good for about a second.
            this.captureRects();

            if (mode === 'move') {
                this.landing = payload;
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
            this.$root.querySelectorAll('.fpb-slot[data-drop-active]').forEach((el) => {
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

            motion.play({
                targets: this.marker,
                scaleX: [0.15, 1],
                opacity: [0, 1],
                easing: 'easeOutQuad',
                duration: motion.duration.marker,
            });
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
