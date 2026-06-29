<?php

namespace Marvel\Translation;

/**
 * Drop-in trait that makes a model's `$translatable` fields language-aware via the
 * request-scoped TranslationContext, with ZERO controller/Resource/GraphQL changes.
 *
 * Why override getAttributeValue(): it is the single code path hit by `$this->name`
 * (REST Resources), Lighthouse attribute resolution (GraphQL), and nested relation
 * reads (`getResourceData($this->type)->name`). One override therefore localizes
 * every read surface at once.
 *
 * Usage on a model:
 *   use HasTranslationOverlay;
 *   protected array $translatable = ['name', 'description'];
 *
 * Writes are unaffected: Pickbazar mutates models from request payloads via mass
 * assignment (setAttribute), never by reading the overlaid getter, and the overlay
 * only returns a value when a translation already exists (brand-new/just-saved
 * content has none → English).
 */
trait HasTranslationOverlay
{
    /** Register every retrieved instance with the overlay (cheap, no query). */
    public static function bootHasTranslationOverlay(): void
    {
        static::retrieved(function ($model) {
            app(TranslationContext::class)->register($model);
        });
    }

    /** The model's overlay fields. Override via `protected array $translatable`. */
    public function getTranslatableFields(): array
    {
        return property_exists($this, 'translatable') ? (array) $this->translatable : [];
    }

    public function getAttributeValue($key)
    {
        $value = parent::getAttributeValue($key);

        if (!in_array($key, $this->getTranslatableFields(), true)) {
            return $value;
        }

        $translated = app(TranslationContext::class)->translate($this, $key);

        return $translated ?? $value;
    }

    /** The canonical English source map [field => value] (overlay-bypassing). */
    public function getTranslatableSource(): array
    {
        // Read the CURRENT raw attributes (not getRawOriginal): the `saved` event
        // fires BEFORE Eloquent syncs original, so getRawOriginal would return the
        // pre-save value and the observer would miss the change. getAttributes()
        // bypasses the overlay accessor too (the overlay lives in getAttributeValue).
        $raw = $this->getAttributes();
        $out = [];
        foreach ($this->getTranslatableFields() as $field) {
            $out[$field] = (string) ($raw[$field] ?? '');
        }
        return $out;
    }

    /** sha256 of the canonical source — drives versioning / outdated detection. */
    public function translatableSourceHash(): string
    {
        return hash('sha256', json_encode($this->getTranslatableSource()));
    }
}
