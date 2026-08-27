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
