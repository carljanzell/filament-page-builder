/**
 * Canvas behaviour for the Filament Page Builder.
 *
 * Written by hand and shipped unminified. Filament already bundles Alpine and SortableJS,
 * so the canvas needs no bundler of its own — which is the point: the package has to work
 * on hosts with no Node installed.
 *
 * Stage A will register an Alpine component here for the drag-and-drop canvas.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('pageBuilderCanvas', () => ({
        init() {
            // Stage A: mount SortableJS on the canvas and the palette.
        },
    }));
});
