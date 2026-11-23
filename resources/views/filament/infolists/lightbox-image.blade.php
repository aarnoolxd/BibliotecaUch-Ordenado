@php
    use Illuminate\Filesystem\FilesystemAdapter;
    use Illuminate\Support\Facades\Storage;

    $photoPath = $getState();
    $imageUrl = null;

    if (filled($photoPath)) {
        if (filter_var($photoPath, FILTER_VALIDATE_URL) !== false || str($photoPath)->startsWith('data:')) {
            $imageUrl = $photoPath;
        } else {
            $diskName = $disk ?? config('filament.default_filesystem_disk');

            /** @var FilesystemAdapter $storageDisk */
            $storageDisk = Storage::disk($diskName);

            if ($storageDisk->exists($photoPath)) {
                if ($diskName !== 'public') {
                    try {
                        $imageUrl = $storageDisk->temporaryUrl(
                            $photoPath,
                            now()->addMinutes((int) config('filament.temporary_file_url_expiry_minutes', 30))->endOfHour(),
                        );
                    } catch (\Throwable) {
                        $imageUrl = $storageDisk->url($photoPath);
                    }
                } else {
                    $imageUrl = $storageDisk->url($photoPath);
                }
            }
        }
    }
@endphp

@if (filled($imageUrl))
    <div
        x-data="{ open: false }"
        x-on:keydown.escape.window.capture="if (open) { $event.stopImmediatePropagation(); open = false; }"
        class="lightbox-wrapper"
    >
        <button
            type="button"
            x-on:click="open = true"
            title="Haz clic para ver en pantalla completa"
            class="lightbox-thumb-btn"
        >
            <img
                src="{{ $imageUrl }}"
                alt="{{ $alt ?? 'Fotografía de evidencia' }}"
                class="lightbox-thumb-img"
            />
        </button>

        <template x-teleport="body">
            <div
                x-show="open"
                x-cloak
                x-transition.opacity.duration.150ms
                x-on:click.self="open = false"
                role="dialog"
                aria-modal="true"
                aria-label="{{ $alt ?? 'Vista ampliada de la imagen' }}"
                class="lightbox-overlay"
            >
                <button
                    type="button"
                    x-on:click="open = false"
                    aria-label="Cerrar imagen"
                    class="lightbox-close"
                >
                    &times;
                </button>

                <figure class="lightbox-figure">
                    <img
                        src="{{ $imageUrl }}"
                        alt="{{ $alt ?? 'Fotografía de evidencia' }}"
                        class="lightbox-full-img"
                    />
                    @if (filled($caption ?? null))
                        <figcaption class="lightbox-caption">
                            {{ $caption }}
                        </figcaption>
                    @endif
                </figure>
            </div>
        </template>
    </div>
@endif
